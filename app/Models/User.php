<?php

namespace App\Models;

use App\Enums\TeamRole;
use App\Models\Concerns\HasSubscriptions;
use App\Support\Teams\Teams;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasDefaultTenant, HasTenants, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    use HasSubscriptions;
    use InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     * (2FA secret + recovery codes are hidden & encrypted by the Filament traits.)
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * /admin: everyone while there is no customer panel; staff only (Super Admin or
     * the admin.access permission) once the customer panel is on.
     * /app: every registered user. Email verification is enforced by the panels,
     * and every page/resource is guarded by policies.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => ! Teams::appPanelEnabled() || $this->isStaff(),
            'app' => Teams::appPanelEnabled(),
            default => false,
        };
    }

    public function isStaff(): bool
    {
        return $this->isSuperAdmin() || $this->can('admin.access');
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(config('filalaunch.roles.super_admin'));
    }

    public function hasAppAuthenticationEnabled(): bool
    {
        return filled($this->app_authentication_secret);
    }

    // ── Teams (tenancy) ──────────────────────────────────────────────

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('role')->withTimestamps();
    }

    public function teamRole(Team $team): ?TeamRole
    {
        $role = $this->teams()->whereKey($team->getKey())->first()?->pivot?->role;

        return $role ? TeamRole::tryFrom($role) : null;
    }

    public function canManageTeam(Team $team): bool
    {
        return (bool) $this->teamRole($team)?->canManage();
    }

    /**
     * Teams in the switcher. A user without a team gets a personal one automatically
     * (e.g. right after teams are switched on for an existing app).
     */
    public function getTenants(Panel $panel): Collection
    {
        if (! Teams::enabled() || $panel->getId() !== 'app') {
            return collect();
        }

        $teams = $this->teams()->orderBy('name')->get();

        if ($teams->isEmpty()) {
            app(Teams::class)->createPersonal($this);
            $teams = $this->teams()->orderBy('name')->get();
        }

        return $teams;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Team && $this->teams()->whereKey($tenant->getKey())->exists();
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->teams()->orderByPivot('created_at')->first();
    }
}

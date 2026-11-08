<?php

namespace App\Models;

use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant in the customer panel (labelled "Team" by default — see System → Settings).
 * Team-owned module models use the BelongsToTeam trait; the Module Builder adds their
 * inverse relation (e.g. posts()) below, which Filament tenancy needs for scoping.
 */
class Team extends Model
{
    protected $fillable = ['name', 'slug', 'owner_id'];

    public static function label(): string
    {
        return (string) config('filalaunch.tenancy.label', 'Team');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function roleOf(User $user): ?TeamRole
    {
        $role = $this->members()->whereKey($user->getKey())->first()?->pivot?->role;

        return $role ? TeamRole::tryFrom($role) : null;
    }
}

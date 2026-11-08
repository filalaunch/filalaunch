<?php

namespace App\Support\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Team (tenant) operations. Every change goes through here so the customer panel,
 * the admin panel and future APIs follow the same rules.
 */
class Teams
{
    // ── Switches ─────────────────────────────────────────────────────

    public static function enabled(): bool
    {
        return (bool) config('filalaunch.tenancy.enabled');
    }

    public static function appPanelEnabled(): bool
    {
        return (bool) config('filalaunch.panels.app.enabled');
    }

    public static function label(): string
    {
        return Team::label();
    }

    /**
     * Team billing: the current team subscribes and all members get the plan.
     */
    public static function teamBilling(): bool
    {
        return static::enabled() && config('filalaunch.tenancy.billing', 'team') === 'team';
    }

    /**
     * Team currently open in the customer panel (null in /admin, console, or when teams are off).
     */
    public static function current(): ?Team
    {
        if (! static::enabled()) {
            return null;
        }

        $tenant = rescue(fn () => Filament::getTenant(), null, report: false);

        return $tenant instanceof Team ? $tenant : null;
    }

    // ── Teams ────────────────────────────────────────────────────────

    public function create(User $owner, string $name, ?string $slug = null): Team
    {
        return DB::transaction(function () use ($owner, $name, $slug): Team {
            $team = Team::query()->create([
                'name' => $name,
                'slug' => $this->uniqueSlug($slug ?: $name),
                'owner_id' => $owner->id,
            ]);

            $team->members()->attach($owner->id, ['role' => TeamRole::Owner->value]);

            return $team;
        });
    }

    /**
     * Automatic first team for a new user, e.g. "Jiten's Team".
     */
    public function createPersonal(User $user): Team
    {
        return $this->create($user, Str::of($user->name)->before(' ')->append("'s ".static::label())->toString());
    }

    public function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'team';
        $slug = $base;
        $i = 2;

        while (Team::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    public function delete(Team $team): void
    {
        if ($team->subscriptions()->active()->whereHas('plan', fn ($q) => $q->where('price', '>', 0))->exists()) {
            throw new RuntimeException('Cancel the paid plan before deleting this '.strtolower(static::label()).'.');
        }

        $team->delete();
    }

    public function transferOwnership(Team $team, User $newOwner): void
    {
        if (! $team->members()->whereKey($newOwner->id)->exists()) {
            throw new RuntimeException('The new owner must be a member.');
        }

        DB::transaction(function () use ($team, $newOwner): void {
            if ($team->owner_id) {
                $team->members()->updateExistingPivot($team->owner_id, ['role' => TeamRole::Admin->value]);
            }

            $team->members()->updateExistingPivot($newOwner->id, ['role' => TeamRole::Owner->value]);
            $team->update(['owner_id' => $newOwner->id]);
        });
    }

    // ── Members ──────────────────────────────────────────────────────

    public function changeRole(Team $team, User $member, TeamRole $role): void
    {
        if ($role === TeamRole::Owner || $team->owner_id === $member->id) {
            throw new RuntimeException('Use "Transfer ownership" to change the owner.');
        }

        $team->members()->updateExistingPivot($member->id, ['role' => $role->value]);
    }

    public function remove(Team $team, User $member): void
    {
        if ($team->owner_id === $member->id) {
            throw new RuntimeException('The owner can\'t be removed — transfer ownership first.');
        }

        $team->members()->detach($member->id);
    }

    public function leave(Team $team, User $user): void
    {
        if ($team->owner_id === $user->id) {
            throw new RuntimeException('Owners can\'t leave — transfer ownership or delete the '.strtolower(static::label()).'.');
        }

        $team->members()->detach($user->id);
    }

    // ── Invitations ──────────────────────────────────────────────────

    public function invite(Team $team, string $email, TeamRole $role, ?User $by = null): TeamInvitation
    {
        $email = Str::lower(trim($email));

        if ($role === TeamRole::Owner) {
            throw new RuntimeException('Invite as admin or member — ownership is transferred later.');
        }

        if ($team->members()->where('email', $email)->exists()) {
            throw new RuntimeException("{$email} is already a member.");
        }

        // One open invitation per email.
        $team->invitations()->where('email', $email)->whereNull('accepted_at')->delete();

        $plain = Str::random(48);

        $invitation = $team->invitations()->create([
            'email' => $email,
            'role' => $role,
            'token' => hash('sha256', $plain),
            'invited_by' => $by?->id,
            'expires_at' => now()->addDays(max(1, (int) config('filalaunch.tenancy.invite_days', 7))),
        ]);

        Notification::route('mail', $email)->notify(new TeamInvitationNotification($invitation, $plain));

        return $invitation;
    }

    public function resend(TeamInvitation $invitation): TeamInvitation
    {
        return $this->invite($invitation->team, $invitation->email, $invitation->role, $invitation->inviter);
    }

    public function revoke(TeamInvitation $invitation): void
    {
        $invitation->delete();
    }

    public function accept(TeamInvitation $invitation, User $user): Team
    {
        if (! $invitation->isPending()) {
            throw new RuntimeException('This invitation has expired or was already used.');
        }

        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            throw new RuntimeException("This invitation is for {$invitation->email}. Log in with that account to accept it.");
        }

        return DB::transaction(function () use ($invitation, $user): Team {
            $team = $invitation->team;

            if (! $team->members()->whereKey($user->id)->exists()) {
                $team->members()->attach($user->id, ['role' => $invitation->role->value]);
            }

            $invitation->update(['accepted_at' => now()]);

            return $team;
        });
    }
}

<?php

namespace App\Models;

use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Email invitation to join a team. Only a sha256 hash of the token is stored;
 * the plain token exists only in the emailed link. Single use, expires.
 */
class TeamInvitation extends Model
{
    protected $fillable = ['team_id', 'email', 'role', 'token', 'invited_by', 'expires_at', 'accepted_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public static function findByToken(string $plain): ?self
    {
        return static::query()->where('token', hash('sha256', $plain))->first();
    }
}

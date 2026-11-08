<?php

namespace App\Models\Concerns;

use App\Models\Team;
use App\Support\Teams\Teams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For team-owned module models (added by the Module Builder's "Belongs to team" option).
 *
 * Inside a team in the customer panel, queries only see that team's rows and new rows
 * get its team_id — also outside Filament (jobs, APIs, custom pages). The admin panel
 * has no current team, so staff see everything.
 */
trait BelongsToTeam
{
    public static function bootBelongsToTeam(): void
    {
        static::addGlobalScope('team', function (Builder $query): void {
            if ($team = Teams::current()) {
                $query->where($query->qualifyColumn('team_id'), $team->getKey());
            }
        });

        static::creating(function ($model): void {
            if (blank($model->team_id) && ($team = Teams::current())) {
                $model->team_id = $team->getKey();
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}

<?php

namespace App\Models;

use App\Enums\LimitBehavior;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A meter on a plan: included quantity per period, price per extra unit, behaviour at the limit.
 */
class PlanMeter extends Model
{
    protected $fillable = ['plan_id', 'meter_id', 'included', 'unit_price', 'on_limit'];

    protected function casts(): array
    {
        return [
            'included' => 'integer',
            'unit_price' => 'integer',
            'on_limit' => LimitBehavior::class,
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(Meter::class);
    }
}

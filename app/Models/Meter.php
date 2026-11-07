<?php

namespace App\Models;

use App\Enums\MeterReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meter extends Model
{
    protected $fillable = ['key', 'name', 'unit', 'reset'];

    protected function casts(): array
    {
        return [
            'reset' => MeterReset::class,
        ];
    }

    public function planMeters(): HasMany
    {
        return $this->hasMany(PlanMeter::class);
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }
}

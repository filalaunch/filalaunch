<?php

namespace App\Models;

use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    /**
     * Group key derived from the "{group}.{ability}" naming format.
     */
    public function getGroup(): string
    {
        return Str::contains($this->name, '.') ? Str::before($this->name, '.') : 'other';
    }
}

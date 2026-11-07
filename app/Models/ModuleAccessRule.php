<?php

namespace App\Models;

use App\Enums\AccessMode;
use Illuminate\Database\Eloquent\Model;

/**
 * Access mode per module (permission prefix): role/permission, plan, or either.
 * Modules without a row use "permission" (the default).
 */
class ModuleAccessRule extends Model
{
    protected $fillable = ['prefix', 'mode'];

    protected function casts(): array
    {
        return [
            'mode' => AccessMode::class,
        ];
    }
}

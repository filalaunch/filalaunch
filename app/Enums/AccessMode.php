<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum AccessMode: string implements HasDescription, HasLabel
{
    case Permission = 'permission';
    case Plan = 'plan';
    case Either = 'either';

    public function getLabel(): string
    {
        return match ($this) {
            self::Permission => 'Role / permission',
            self::Plan => 'Plan',
            self::Either => 'Role / permission or plan',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Permission => 'Only roles and direct permissions grant access.',
            self::Plan => 'Only an active plan that includes it grants access.',
            self::Either => 'A role/permission or an active plan grants access.',
        };
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum LimitBehavior: string implements HasLabel
{
    case Block = 'block';
    case Overage = 'overage';

    public function getLabel(): string
    {
        return match ($this) {
            self::Block => 'Block at limit',
            self::Overage => 'Allow overage (billed per unit)',
        };
    }
}

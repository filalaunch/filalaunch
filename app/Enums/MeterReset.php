<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MeterReset: string implements HasLabel
{
    case Monthly = 'monthly';
    case Cycle = 'cycle';
    case Never = 'never';

    public function getLabel(): string
    {
        return match ($this) {
            self::Monthly => 'Every calendar month',
            self::Cycle => 'Every billing cycle',
            self::Never => 'Never (lifetime credits)',
        };
    }
}

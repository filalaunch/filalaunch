<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum PlanType: string implements HasColor, HasDescription, HasLabel
{
    case OneTime = 'one_time';
    case Subscription = 'subscription';
    case Usage = 'usage';

    public function getLabel(): string
    {
        return match ($this) {
            self::OneTime => 'One-time',
            self::Subscription => 'Subscription',
            self::Usage => 'Usage-based',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::OneTime => 'Pay once — lifetime or fixed access period.',
            self::Subscription => 'Recurring price per interval, optional trial.',
            self::Usage => 'Optional base price + metered usage (included quantity, price per unit).',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::OneTime => 'info',
            self::Subscription => 'primary',
            self::Usage => 'warning',
        };
    }
}

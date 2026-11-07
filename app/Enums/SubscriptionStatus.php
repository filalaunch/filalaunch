<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SubscriptionStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Trialing = 'trialing';
    case Active = 'active';
    case Canceled = 'canceled';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Trialing => 'info',
            self::Active => 'success',
            self::Canceled => 'gray',
            self::Expired => 'danger',
        };
    }
}

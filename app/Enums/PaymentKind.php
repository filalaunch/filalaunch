<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentKind: string implements HasLabel
{
    case New = 'new';
    case Renewal = 'renewal';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}

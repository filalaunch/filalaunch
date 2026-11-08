<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TeamRole: string implements HasColor, HasLabel
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Owner => 'danger',
            self::Admin => 'warning',
            self::Member => 'gray',
        };
    }

    /**
     * Owners and admins manage members, invitations and billing.
     */
    public function canManage(): bool
    {
        return $this !== self::Member;
    }

    /**
     * Roles that can be given by invitation or role change (ownership is transferred, not assigned).
     *
     * @return array<string, string>
     */
    public static function assignable(): array
    {
        return [self::Admin->value => self::Admin->getLabel(), self::Member->value => self::Member->getLabel()];
    }
}

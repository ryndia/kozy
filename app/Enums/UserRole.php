<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case USER = 'user';
    case RENTER = 'renter';

    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }

    public static function isValid(string $role): bool
    {
        return in_array($role, self::values(), true);
    }
}

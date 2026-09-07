<?php

namespace Modules\User\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::Employee => 'Employee',
        };
    }
}

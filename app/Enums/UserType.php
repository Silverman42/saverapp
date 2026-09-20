<?php

namespace App\Enums;

enum UserType: string
{
    case Customer = 'customer';
    case Agent = 'agent';
    case Admin = 'admin';

    /**
     * Get the route name for this user type's dashboard.
     */
    public function dashboardRouteName(): string
    {
        return match ($this) {
            self::Customer => 'customer.dashboard',
            self::Agent => 'agent.dashboard',
            self::Admin => 'admin.dashboard',
        };
    }

    /**
     * Get the canonical Spatie role name for this user type.
     */
    public function roleName(): string
    {
        return $this->value;
    }

    /**
     * Get all user type values as an array of strings.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

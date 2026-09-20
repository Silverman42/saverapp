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
}

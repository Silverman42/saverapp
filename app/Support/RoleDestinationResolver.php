<?php

namespace App\Support;

use App\Enums\UserType;
use App\Models\User;

class RoleDestinationResolver
{
    /**
     * Resolve the dashboard route name for the given user or user type.
     */
    public static function resolveRouteName(User|UserType $userOrType): string
    {
        $type = $userOrType instanceof User ? $userOrType->user_type : $userOrType;

        return match ($type) {
            UserType::Customer => 'customer.dashboard',
            UserType::Agent => 'agent.dashboard',
            UserType::Admin => 'admin.dashboard',
        };
    }

    /**
     * Resolve the dashboard URL for the given user or user type.
     */
    public static function resolveUrl(User|UserType $userOrType, bool $absolute = true): string
    {
        return route(static::resolveRouteName($userOrType), absolute: $absolute);
    }
}

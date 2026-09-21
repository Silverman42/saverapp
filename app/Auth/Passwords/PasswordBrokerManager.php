<?php

namespace App\Auth\Passwords;

use Illuminate\Auth\Passwords\PasswordBrokerManager as BasePasswordBrokerManager;
use InvalidArgumentException;

class PasswordBrokerManager extends BasePasswordBrokerManager
{
    /**
     * Resolve the given broker instance.
     *
     * @param  string  $name
     * @return PasswordResetBroker
     *
     * @throws InvalidArgumentException
     */
    protected function resolve($name)
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Password resetter [{$name}] is not defined.");
        }

        return new PasswordResetBroker(
            $this->createTokenRepository($config),
            $this->app->make('auth')->createUserProvider($config['provider'] ?? null),
            $this->app->bound('events') ? $this->app->make('events') : null,
            timeboxDuration: (int) $this->app->make('config')->get('auth.timebox_duration', 200000),
        );
    }
}

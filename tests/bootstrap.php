<?php

use ScoutElastic\Tests\Config;

if (!function_exists('config')) {
    /**
     * @param  string|null             $key
     * @param  mixed|null              $default
     * @return array|ArrayAccess|mixed
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

include __DIR__ . '/../vendor/autoload.php';

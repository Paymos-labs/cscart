<?php

declare(strict_types=1);

namespace PaymosCsCart;

final class Autoloader
{
    public static function register()
    {
        spl_autoload_register(static function ($class) {
            $prefix = 'PaymosCsCart\\';
            if (strncmp($class, $prefix, strlen($prefix)) === 0) {
                $relative = substr($class, strlen($prefix));
                $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
                if (is_readable($path)) {
                    require_once $path;
                }
                return;
            }

            $sdkPrefix = 'Paymos\\';
            if (strncmp($class, $sdkPrefix, strlen($sdkPrefix)) !== 0) {
                return;
            }

            $relative = substr($class, strlen($sdkPrefix));
            $path = dirname(__DIR__) . '/vendor/paymos/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php';
            if (is_readable($path)) {
                require_once $path;
            }
        });
    }
}

<?php
declare(strict_types=1);

namespace App;

final class Config
{
    /** SLIMBUDGET_CONFIG points to another file (used by the tests). */
    public static function load(): array
    {
        $file = getenv('SLIMBUDGET_CONFIG') ?: dirname(__DIR__) . '/config/local.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Missing $file: copy config/local.example.php (see the README).");
        }
        $config = (require $file) + [
            'env' => 'production',
            'timezone' => 'UTC',
            'locale' => 'en',
            'currency' => 'USD',
            'var_dir' => dirname(__DIR__) . '/var', // cache, sessions and logs
            'key' => '',
            'trusted_proxies' => [],
        ];
        date_default_timezone_set($config['timezone']);
        return $config;
    }
}

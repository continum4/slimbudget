<?php
declare(strict_types=1);

// PHP's built-in server: let static files through
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}
require dirname(__DIR__) . '/vendor/autoload.php';
(require dirname(__DIR__) . '/src/app.php')->run();

<?php
// Copy to config/local.php and adjust.
return [
    'env' => 'production',            // 'dev' shows error details
    'url' => 'http://localhost:8080', // an https URL turns on Secure cookies
    'timezone' => 'UTC',
    'locale' => 'en',                 // initial language: any file in lang/ (change it later in Settings)
    'currency' => 'USD',              // initial currency: any code in config/currencies.json (idem)
    'key' => '',                      // for two-step verification; generate it with: php -r 'echo bin2hex(random_bytes(32));'
    'trusted_proxies' => [],          // addresses of your reverse proxies, if any: only their X-Forwarded-For is believed
    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'slimbudget', 'user' => 'slimbudget', 'pass' => ''],
    // 'db_admin' => [...],           // optional: another database user, for bin/migrate.php only
];

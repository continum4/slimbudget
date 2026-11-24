<?php
// Copy to config/local.php and adjust.
return [
    'env' => 'production',            // 'dev' shows error details
    'url' => 'http://localhost:8080', // an https URL turns on Secure cookies
    'timezone' => 'UTC',
    'locale' => 'en',                 // initial language: any file in lang/ (change it later in Settings)
    'currency' => 'USD',              // initial currency: any code in config/currencies.json (idem)
    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'slimbudget', 'user' => 'slimbudget', 'pass' => ''],
];

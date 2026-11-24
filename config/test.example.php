<?php
// Copy to config/test.php. The end-to-end tests ERASE this database; its name must end in _test.
return [
    'env' => 'dev',
    'url' => 'http://127.0.0.1:8099',
    'timezone' => 'UTC',
    'locale' => 'en',
    'currency' => 'USD',
    'var_dir' => sys_get_temp_dir() . '/slimbudget-test',
    'key' => 'only-for-tests-only-for-tests-only-for-tests',
    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'slimbudget_test', 'user' => 'slimbudget', 'pass' => ''],
];

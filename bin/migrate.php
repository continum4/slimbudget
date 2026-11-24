<?php
declare(strict_types=1);

// Usage: php bin/migrate.php — applies the pending files in database/migrations, in order.
require dirname(__DIR__) . '/vendor/autoload.php';

$config = App\Config::load();
$db = new App\Database($config['db_admin'] ?? $config['db']);
$pdo = $db->pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(80) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$applied = array_column($db->all('SELECT version FROM schema_migrations'), 'version');
$pending = 0;
foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') as $file) {
    $version = basename($file, '.sql');
    if (in_array($version, $applied, true)) {
        continue;
    }
    // MySQL commits each DDL statement, so a failed migration can be half applied: back up first
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', file_get_contents($file)))) as $sql) {
        $pdo->exec($sql);
    }
    $db->insert('schema_migrations', ['version' => $version]);
    echo "Applied $version\n";
    $pending++;
}
echo $pending ? "Done.\n" : "Nothing to apply.\n";

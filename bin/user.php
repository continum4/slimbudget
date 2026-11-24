<?php
declare(strict_types=1);

// Usage: php bin/user.php <username> <name> <admin|member> [person]
// Creates the user, or gives an existing one a new temporary password.
require dirname(__DIR__) . '/vendor/autoload.php';

[, $username, $name, $role] = $argv + [null, null, null, null];
if (!$username || !preg_match('/^[a-z0-9._-]{3,40}$/', $username) || !$name || !in_array($role, ['admin', 'member'], true)) {
    fwrite(STDERR, "Usage: php bin/user.php <username> <name> <admin|member> [person]\n");
    exit(1);
}
$db = new App\Database(App\Config::load()['db']);
$person = isset($argv[4]) ? ($db->value('SELECT id FROM people WHERE name=?', [$argv[4]]) ?: null) : null;
$password = App\Controllers\UserController::temporaryPassword();
$hash = password_hash($password, PASSWORD_DEFAULT);
if ($id = $db->value('SELECT id FROM users WHERE username=?', [$username])) {
    $db->update('users', (int) $id, ['password_hash' => $hash, 'must_change_password' => 1, 'active' => 1]);
} else {
    $db->insert('users', ['username' => $username, 'name' => $name, 'role' => $role, 'person_id' => $person, 'password_hash' => $hash]);
}
echo "$username ($role) — temporary password: $password (it must be changed on first login)\n";

<?php
declare(strict_types=1);

// Usage: php bin/user.php <username> <name> <admin|member> [person]
// Creates the user, or gives an existing one a new temporary password: also turns off their two-step verification and closes their sessions.
require dirname(__DIR__) . '/vendor/autoload.php';

[, $username, $name, $role] = $argv + [null, null, null, null];
if (!$username || !preg_match('/^[a-z0-9._-]{3,40}$/', $username) || !$name || !in_array($role, ['admin', 'member'], true)) {
    fwrite(STDERR, "Usage: php bin/user.php <username> <name> <admin|member> [person]\n");
    exit(1);
}
$db = new App\Database(App\Config::load()['db']);
$person = isset($argv[4]) ? ($db->value('SELECT id FROM people WHERE name=?', [$argv[4]]) ?: null) : null;
$password = App\Services\Password::temporary();
$fields = App\Services\Password::temporaryFields($password);
if ($id = $db->value('SELECT id FROM users WHERE username=?', [$username])) {
    $db->update('users', (int) $id, $fields + ['role' => $role, 'active' => 1]);
    (new App\Services\TwoFactor($db, new App\Services\Clock(), ''))->disable((int) $id);
    $db->run('DELETE FROM login_attempts WHERE username IN (?,?,?)', [$username, "code:$username", "confirm:$username"]);
} else {
    $db->insert('users', ['username' => $username, 'name' => $name, 'role' => $role, 'person_id' => $person] + $fields);
}
echo "$username ($role) — temporary password: $password (it must be changed on first login, within " . App\Services\Password::TEMPORARY_DAYS . " days)\n";

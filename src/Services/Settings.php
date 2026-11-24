<?php
declare(strict_types=1);

namespace App\Services;

use App\Database;

/** Values an admin can change from the app; config/local.php provides the initial ones. */
final class Settings
{
    private ?array $values = null;

    public function __construct(private Database $db, private array $defaults)
    {
    }

    public function get(string $name): string
    {
        $this->values ??= array_column($this->db->all('SELECT name, value FROM settings'), 'value', 'name');
        return (string) ($this->values[$name] ?? $this->defaults[$name] ?? '');
    }

    public function set(string $name, string $value): void
    {
        $this->db->run('REPLACE INTO settings (name, value) VALUES (?,?)', [$name, $value]);
        $this->values = null;
    }
}

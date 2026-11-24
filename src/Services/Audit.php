<?php
declare(strict_types=1);

namespace App\Services;

use App\Database;

/** Who did what: sign-ins, accounts and everything deleted. */
final class Audit
{
    private const KEEP_DAYS = 365;

    public function __construct(private Database $db)
    {
    }

    public function record(?int $userId, string $action, string $detail = '', string $ip = ''): void
    {
        $this->db->insert('audit_log', ['user_id' => $userId, 'action' => $action, 'detail' => mb_substr($detail, 0, 255) ?: null, 'ip' => $ip]);
    }

    /** Has the user signed in from this address lately? */
    public function knows(int $userId, string $ip): bool
    {
        return (bool) $this->db->value("SELECT 1 FROM audit_log WHERE user_id=? AND action='login' AND ip=? AND created_at > NOW() - INTERVAL 30 DAY LIMIT 1", [$userId, $ip]);
    }

    public function latest(int $limit): array
    {
        return $this->db->all('SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT ' . $limit);
    }

    public function purge(): void
    {
        $this->db->run('DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL ? DAY', [self::KEEP_DAYS]);
    }
}

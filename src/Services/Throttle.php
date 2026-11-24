<?php
declare(strict_types=1);

namespace App\Services;

use App\Database;

/** Limits failed sign-ins. Every attempt is written before it is checked, so simultaneous requests cannot slip through. */
final class Throttle
{
    public const WINDOW_MINUTES = 15;
    private const PER_USER_AND_IP = 5;
    private const PER_IP = 20;
    private const PER_USER = 30;

    public function __construct(private Database $db)
    {
    }

    /**
     * Id of the attempt, or null when there have been too many. Leave it in place if it fails; call forgive() if it succeeds.
     * $perUser null skips the limit for the whole account, for addresses the user signs in from: nobody can lock them out of their own.
     */
    public function attempt(string $key, string $ip, ?int $perUser = self::PER_USER): ?int
    {
        $ip = self::network($ip);
        $this->db->run('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL ? MINUTE', [self::WINDOW_MINUTES]);
        $id = $this->db->insert('login_attempts', ['username' => $key, 'ip' => $ip]);
        $count = $this->db->row('SELECT SUM(username=? AND ip=?) pair, SUM(ip=?) ip, SUM(username=?) user FROM login_attempts WHERE username=? OR ip=?', [$key, $ip, $ip, $key, $key, $ip]);
        if ((int) $count['pair'] > self::PER_USER_AND_IP || (int) $count['ip'] > self::PER_IP || ($perUser !== null && (int) $count['user'] > $perUser)) {
            $this->forgive($id); // a blocked attempt checked nothing, so it does not extend the wait
            return null;
        }
        return $id;
    }

    public function forgive(int $id): void
    {
        $this->db->run('DELETE FROM login_attempts WHERE id=?', [$id]);
    }

    /** Forgets the failures from this address only: the ones from others still count. */
    public function clear(string $key, string $ip): void
    {
        $this->db->run('DELETE FROM login_attempts WHERE username=? AND ip=?', [$key, self::network($ip)]);
    }

    /** IPv6 clients own a whole /64, so it counts as one address. */
    public static function network(string $ip): string
    {
        $binary = @inet_pton($ip);
        return $binary !== false && strlen($binary) === 16 ? inet_ntop(substr($binary, 0, 8) . str_repeat("\0", 8)) : $ip;
    }
}

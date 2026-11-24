<?php
declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Logger;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Everything that stands between a visitor and a password: limits, failure log and history. */
final class Gate
{
    private const SENSITIVE_PER_USER = 5;
    private Throttle $throttle;
    private Audit $audit;

    public function __construct(private Database $db, private ClientIp $clientIp, private string $logFile)
    {
        $this->throttle = new Throttle($db);
        $this->audit = new Audit($db);
    }

    /** Id of a sign-in attempt, or null when there have been too many. */
    public function signIn(Request $request, string $username, ?array $user): ?int
    {
        $ip = $this->clientIp->of($request);
        return $user && $this->audit->knows((int) $user['id'], $ip) ? $this->throttle->attempt($username, $ip, null) : $this->throttle->attempt($username, $ip);
    }

    /** Same, for a two-step code or the current password: fewer attempts, from any address. */
    public function sensitive(Request $request, string $what, array $user): ?int
    {
        return $this->throttle->attempt("$what:{$user['username']}", $this->clientIp->of($request), self::SENSITIVE_PER_USER);
    }

    public function forgive(int $attempt): void
    {
        $this->throttle->forgive($attempt);
    }

    /** One line per failure, with the address: fail2ban can read it. */
    public function failed(Request $request, string $what, string $username): void
    {
        $name = preg_replace('/[^\w.@-]+/', '?', mb_substr($username, 0, 40));
        Logger::write($this->logFile, "failed $what user=$name ip=" . $this->clientIp->of($request));
    }

    /** Key of the error text, or null when the password is right. Never call it inside a transaction: a rollback would erase the attempt. */
    public function confirmPassword(Request $request, array $user, #[\SensitiveParameter] mixed $password): ?string
    {
        $attempt = $this->sensitive($request, 'confirm', $user);
        if ($attempt === null) {
            return 'login.too_many';
        }
        if (!is_string($password) || !password_verify($password, $user['password_hash'])) {
            $this->failed($request, 'confirmation', $user['username']);
            return 'profile.wrong_password';
        }
        $this->throttle->forgive($attempt);
        $this->throttle->clear("confirm:{$user['username']}", $this->clientIp->of($request));
        return null;
    }

    public function signedIn(Request $request, array $user): void
    {
        $ip = $this->clientIp->of($request);
        $this->throttle->clear($user['username'], $ip);
        $this->throttle->clear("code:{$user['username']}", $ip);
        $this->audit->purge();
        $this->audit->record((int) $user['id'], 'login', '', $ip);
        $this->db->run('UPDATE users SET last_login_at=NOW() WHERE id=?', [$user['id']]);
    }

    public function record(Request $request, int $userId, string $action, string $detail = ''): void
    {
        $this->audit->record($userId, $action, $detail, $this->clientIp->of($request));
    }

    /** Closes every session of the user except the current one. */
    public function closeOtherSessions(int $userId): void
    {
        $this->db->run('UPDATE users SET session_version=session_version+1 WHERE id=?', [$userId]);
        $this->keepThisSession($userId);
    }

    public function keepThisSession(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['version'] = (int) $this->db->value('SELECT session_version FROM users WHERE id=?', [$userId]);
    }
}

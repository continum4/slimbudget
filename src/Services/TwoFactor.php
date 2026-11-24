<?php
declare(strict_types=1);

namespace App\Services;

use App\Database;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use OTPHP\TOTP;
use Psr\Clock\ClockInterface;

/** Codes from an authenticator app (TOTP) and single-use recovery codes. */
final class TwoFactor
{
    private const PERIOD = 30;
    private const RECOVERY_CODES = 10;
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private Database $db, private ClockInterface $clock, #[\SensitiveParameter] private string $key)
    {
    }

    /** The secrets are stored encrypted, so it needs a 'key' in config/local.php. */
    public function available(): bool
    {
        return strlen($this->key) >= 32;
    }

    public function newSecret(): string
    {
        return TOTP::generate($this->clock, 20)->getSecret();
    }

    public function uri(#[\SensitiveParameter] string $secret, string $account, string $issuer): string
    {
        return $this->totp($secret)->withLabel($account)->withIssuer($issuer)->getProvisioningUri();
    }

    /** QR as a data: URI, so nothing leaves the server. */
    public function qr(#[\SensitiveParameter] string $uri): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 2), new SvgImageBackEnd())))->writeString($uri);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /** Time step the code belongs to (one step of tolerance), or null if it is wrong or not newer than $after. */
    public function step(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code, int $after = 0): ?int
    {
        $now = intdiv($this->clock->now()->getTimestamp(), self::PERIOD);
        $found = null;
        foreach ([$now - 1, $now, $now + 1] as $step) {
            if (hash_equals($this->totp($secret)->at($step * self::PERIOD), $code) && $step > $after) {
                $found = $step;
            }
        }
        return $found;
    }

    /** Accepts a code from the app or a recovery code; each one works once, even with simultaneous requests. */
    public function verify(array $user, #[\SensitiveParameter] string $input): bool
    {
        $code = preg_replace('/[\s-]+/', '', $input);
        if (preg_match('/^\d{6}$/', $code)) {
            $step = $this->step($this->decrypt($user['totp_secret']), $code, (int) $user['totp_last_step']);
            return $step !== null && $this->db->run('UPDATE users SET totp_last_step=? WHERE id=? AND totp_last_step<?', [$step, $user['id'], $step])->rowCount() === 1;
        }
        return $this->db->run('DELETE FROM recovery_codes WHERE user_id=? AND code_hash=?', [$user['id'], $this->hash($code)])->rowCount() === 1;
    }

    public function enable(int $userId, #[\SensitiveParameter] string $secret, int $step): void
    {
        $this->db->run('UPDATE users SET totp_secret=?, totp_last_step=?, session_version=session_version+1 WHERE id=?', [$this->encrypt($secret), $step, $userId]);
    }

    public function disable(int $userId): void
    {
        $this->db->run('UPDATE users SET totp_secret=NULL, totp_last_step=0, session_version=session_version+1 WHERE id=?', [$userId]);
        $this->db->run('DELETE FROM recovery_codes WHERE user_id=?', [$userId]);
    }

    /** @return string[] new codes; the previous ones stop working */
    public function newRecoveryCodes(int $userId): array
    {
        $this->db->run('DELETE FROM recovery_codes WHERE user_id=?', [$userId]);
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            // Only digits: phones show the numeric keyboard for this field
            $groups = array_map(static fn (): string => sprintf('%04d', random_int(0, 9999)), range(1, 4));
            $this->db->insert('recovery_codes', ['user_id' => $userId, 'code_hash' => $this->hash(implode('', $groups))]);
            $codes[] = implode('-', $groups);
        }
        return $codes;
    }

    public function recoveryCodesLeft(int $userId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM recovery_codes WHERE user_id=?', [$userId]);
    }

    /** 16 random digits: a keyed hash is enough, because the key is not in the database. */
    private function hash(#[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $code, $this->key);
    }

    private function totp(#[\SensitiveParameter] string $secret): TOTP
    {
        return TOTP::createFromSecret($secret, $this->clock);
    }

    private function encrypt(#[\SensitiveParameter] string $text): string
    {
        $iv = random_bytes(12);
        $data = openssl_encrypt($text, self::CIPHER, hash('sha256', $this->key, true), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $data);
    }

    private function decrypt(string $stored): string
    {
        $raw = (string) base64_decode($stored, true);
        $text = openssl_decrypt(substr($raw, 28), self::CIPHER, hash('sha256', $this->key, true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $text !== false ? $text : throw new \RuntimeException("Cannot read the two-step secret: was 'key' changed in config/local.php?");
    }
}

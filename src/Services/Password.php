<?php
declare(strict_types=1);

namespace App\Services;

/** Rules for a new password. Each problem is the key of a text in profile.* */
final class Password
{
    public const MIN = 10;
    private const MAX_BYTES = 72; // bcrypt ignores the rest
    private const MIN_DIFFERENT = 5;
    public const TEMPORARY_DAYS = 7;

    public static function problem(#[\SensitiveParameter] string $new, #[\SensitiveParameter] string $current, string $username): ?string
    {
        return match (true) {
            !mb_check_encoding($new, 'UTF-8') || str_contains($new, "\0") || strlen($new) > self::MAX_BYTES => 'long_password',
            mb_strlen($new) < self::MIN => 'short_password',
            hash_equals($current, $new) => 'same_password',
            count(array_unique(mb_str_split(mb_strtolower($new)))) < self::MIN_DIFFERENT || str_contains(mb_strtolower($new), $username) => 'weak_password',
            default => null,
        };
    }

    public static function temporary(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(9)), '+/', 'xy'), '=');
    }

    /** Columns that give a user a temporary password and close their sessions. */
    public static function temporaryFields(#[\SensitiveParameter] string $password): array
    {
        return ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'must_change_password' => 1, 'password_expires_at' => date('Y-m-d H:i:s', strtotime('+' . self::TEMPORARY_DAYS . ' days'))];
    }
}

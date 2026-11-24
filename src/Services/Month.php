<?php
declare(strict_types=1);

namespace App\Services;

final class Month
{
    public static function isValid(string $ym): bool
    {
        return (bool) preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $ym);
    }

    public static function current(): string
    {
        return date('Y-m');
    }

    public static function add(string $ym, int $n): string
    {
        return (new \DateTimeImmutable("$ym-01"))->modify(sprintf('%+d months', $n))->format('Y-m');
    }

    /** Date for a day of the month, clamped to its length. */
    public static function date(string $ym, int $day): string
    {
        $length = (int) (new \DateTimeImmutable("$ym-01"))->format('t');
        return sprintf('%s-%02d', $ym, max(1, min($day, $length)));
    }
}

<?php
declare(strict_types=1);

namespace App;

final class Logger
{
    public static function write(string $file, string $text): void
    {
        $new = !is_file($file);
        @file_put_contents($file, '[' . date('Y-m-d H:i:s') . "] $text\n", FILE_APPEND | LOCK_EX);
        if ($new) {
            @chmod($file, 0640);
        }
    }
}

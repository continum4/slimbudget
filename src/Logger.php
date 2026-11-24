<?php
declare(strict_types=1);

namespace App;

final class Logger
{
    public static function write(string $file, string $text): void
    {
        @file_put_contents($file, '[' . date('Y-m-d H:i:s') . "] $text\n\n", FILE_APPEND | LOCK_EX);
    }
}

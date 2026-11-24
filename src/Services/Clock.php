<?php
declare(strict_types=1);

namespace App\Services;

use Psr\Clock\ClockInterface;

final class Clock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}

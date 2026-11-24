<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Budget;
use App\Services\Month;
use PHPUnit\Framework\TestCase;

final class BudgetTest extends TestCase
{
    public function testStatus(): void
    {
        $this->assertSame('unused', Budget::status(0, 0)['key']);
        $this->assertSame('no_budget', Budget::status(0, 10)['key']);
        $this->assertSame('no_records', Budget::status(10, 0)['key']);
        $this->assertSame(['good', '50'], [Budget::status(10, 5)['level'], Budget::status(10, 5)['percent']]);
        $this->assertSame(['good', '100'], [Budget::status(10, 10)['level'], Budget::status(10, 10)['percent']]);
        $this->assertSame(['critical', '100.2'], [Budget::status(1000, 1002)['level'], Budget::status(1000, 1002)['percent']]);
    }

    public function testMonths(): void
    {
        $this->assertSame('2027-01', Month::add('2026-11', 2));
        $this->assertSame('2026-02-28', Month::date('2026-02', 31));
        $this->assertTrue(Month::isValid('2026-12'));
        $this->assertFalse(Month::isValid('2026-13'));
    }
}

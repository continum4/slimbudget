<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Budget;
use App\Services\Month;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public static function periods(): array
    {
        return [
            'calendar month' => ['2026-12', null, null, '2026-11-01', '2026-11-30'],
            'calendar month, due day alone' => ['2026-03', null, 10, '2026-02-01', '2026-02-28'],
            'closes the month before' => ['2026-12', 20, 10, '2026-10-21', '2026-11-20'],
            'no due day' => ['2026-12', 20, null, '2026-10-21', '2026-11-20'],
            'closes and is paid the same month' => ['2026-12', 3, 23, '2026-11-04', '2026-12-03'],
            'same closing and due day' => ['2026-12', 15, 15, '2026-10-16', '2026-11-15'],
            'year boundary' => ['2027-01', 20, 10, '2026-11-21', '2026-12-20'],
            'closing day longer than the month' => ['2026-03', 31, 20, '2026-02-01', '2026-02-28'],
            'after a short month' => ['2026-04', 31, 20, '2026-03-01', '2026-03-31'],
            'leap year' => ['2028-03', 30, 20, '2028-01-31', '2028-02-29'],
        ];
    }

    #[DataProvider('periods')]
    public function testStatementPeriod(string $ym, ?int $closing, ?int $due, string $from, string $to): void
    {
        $this->assertSame([$from, $to], Budget::statementPeriod($ym, $closing, $due));
    }

    /** Consecutive statements never overlap nor leave a day out. */
    public function testStatementsCoverEveryDayOnce(): void
    {
        foreach ([[null, null], [20, 10], [3, 23], [28, 18], [29, 9], [30, 20], [31, 20], [15, 15]] as [$closing, $due]) {
            for ($ym = '2026-01'; $ym < '2028-12'; $ym = Month::add($ym, 1)) {
                $end = Budget::statementPeriod($ym, $closing, $due)[1];
                $start = Budget::statementPeriod(Month::add($ym, 1), $closing, $due)[0];
                $this->assertSame((new \DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d'), $start, "$ym closing $closing");
            }
        }
    }

    public function testMonths(): void
    {
        $this->assertSame('2027-01', Month::add('2026-11', 2));
        $this->assertSame('2026-02-28', Month::date('2026-02', 31));
        $this->assertTrue(Month::isValid('2026-12'));
        $this->assertFalse(Month::isValid('2026-13'));
    }
}

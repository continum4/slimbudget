<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CardCycles;
use App\Services\Month;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CardCyclesTest extends TestCase
{
    private static function cycle(string $from, ?int $closing, ?int $due): array
    {
        return ['from_month' => $from, 'closing_day' => $closing, 'due_day' => $due];
    }

    public static function periods(): array
    {
        return [
            'calendar month' => ['2026-12', null, null, '2026-11-01', '2026-11-30', null],
            'calendar month with due day' => ['2026-03', null, 10, '2026-02-01', '2026-02-28', '2026-03-10'],
            'closes the month before' => ['2026-12', 20, 10, '2026-10-21', '2026-11-20', '2026-12-10'],
            'no due day' => ['2026-12', 20, null, '2026-10-21', '2026-11-20', null],
            'closes and is paid the same month' => ['2026-12', 3, 23, '2026-11-04', '2026-12-03', '2026-12-23'],
            'same closing and due day' => ['2026-12', 15, 15, '2026-10-16', '2026-11-15', '2026-12-15'],
            'year boundary' => ['2027-01', 20, 10, '2026-11-21', '2026-12-20', '2027-01-10'],
            'closing day longer than the month' => ['2026-03', 31, 20, '2026-02-01', '2026-02-28', '2026-03-20'],
            'leap year' => ['2028-03', 30, 20, '2028-01-31', '2028-02-29', '2028-03-20'],
        ];
    }

    #[DataProvider('periods')]
    public function testPeriod(string $ym, ?int $closing, ?int $due, string $from, string $to, ?string $dueOn): void
    {
        $cycles = new CardCycles([self::cycle(CardCycles::BEGINNING, $closing, $due)]);
        $this->assertSame(['from' => $from, 'to' => $to, 'due_on' => $dueOn], $cycles->period($ym));
    }

    public function testWithoutDatesItIsTheCalendarMonth(): void
    {
        $this->assertSame(['from' => '2026-11-01', 'to' => '2026-11-30', 'due_on' => null], (new CardCycles())->period('2026-12'));
    }

    /** Closed on the 7th until December 2025; on the 4th since January 2026. */
    public function testAChangeKeepsThePast(): void
    {
        $before = new CardCycles([self::cycle(CardCycles::BEGINNING, 7, null)]);
        $after = new CardCycles([self::cycle(CardCycles::BEGINNING, 7, null), self::cycle('2026-01', 4, null)]);
        for ($ym = '2025-01'; $ym <= '2026-01'; $ym = Month::add($ym, 1)) {
            $this->assertSame($before->period($ym), $after->period($ym), $ym);
        }
        $this->assertSame(['from' => '2025-12-08', 'to' => '2026-01-04', 'due_on' => null], $after->period('2026-02'));
        $this->assertSame(['from' => '2026-01-05', 'to' => '2026-02-04', 'due_on' => null], $after->period('2026-03'));
    }

    /** Whatever the changes, every day is paid exactly once. */
    public function testEveryDayIsPaidOnce(): void
    {
        $histories = [
            [], [['0000-00', 20, 10]], [['0000-00', 7, 27], ['2026-01', 4, 24]], [['0000-00', 31, 20], ['2026-03', 1, 21]], [['2026-02', 15, 5]],
            [['0000-00', 3, 23], ['2026-05', 25, 14]], [['0000-00', 25, 14], ['2026-05', 3, 23]], [['0000-00', 10, 30], ['2026-04', null, null], ['2026-08', 29, 9], ['2027-01', 30, null]],
        ];
        foreach ($histories as $history) {
            $cycles = new CardCycles(array_map(static fn (array $c): array => self::cycle(...$c), $history));
            $next = null;
            for ($ym = '2025-06'; $ym <= '2028-04'; $ym = Month::add($ym, 1)) {
                $period = $cycles->period($ym);
                if ($period === null) {
                    continue;
                }
                if ($next !== null) {
                    $this->assertSame($next, $period['from'], json_encode($history) . " $ym");
                }
                $this->assertGreaterThanOrEqual($period['from'], $period['to']);
                $next = (new \DateTimeImmutable($period['to']))->modify('+1 day')->format('Y-m-d');
            }
        }
    }
}

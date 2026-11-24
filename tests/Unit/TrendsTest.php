<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Chart;
use App\Services\Trends;
use PHPUnit\Framework\TestCase;

final class TrendsTest extends TestCase
{
    private static function row(string $ym, float $amount, string $group = 'Food', string $category = 'Groceries', string $kind = 'variable', int $day = 5): array
    {
        return ['ym' => $ym, 'day' => $day, 'group_name' => $group, 'kind' => $kind, 'category' => $category, 'amount' => $amount];
    }

    private static function compute(array $rows, bool $includeFixed = true, string $now = '2026-06', int $day = 10): array
    {
        return Trends::compute($rows, 2026, $now, $day, $includeFixed);
    }

    public function testMedian(): void
    {
        $this->assertNull(Trends::median([]));
        $this->assertSame(5.0, Trends::median([5]));
        $this->assertSame(3.0, Trends::median([9, 1, 3]));
        $this->assertSame(2.5, Trends::median([4, 1, 2, 3]));
    }

    public function testAnOddMonthDoesNotDistortTheTypicalOne(): void
    {
        $d = self::compute([self::row('2026-01', 100), self::row('2026-02', 110), self::row('2026-03', 120), self::row('2026-04', 9000), self::row('2026-05', 130)]);
        $this->assertSame(120.0, $d['typical']);
        $this->assertNull($d['current']);
    }

    public function testTheMonthInProgressDoesNotCountAsTypical(): void
    {
        $d = self::compute([self::row('2026-04', 100), self::row('2026-05', 60), self::row('2026-05', 140, day: 25), self::row('2026-06', 30)]);
        $this->assertSame('2026-06', $d['in_progress']);
        $this->assertSame(2, $d['finished']);
        $this->assertSame(150.0, $d['typical']);
        // By day 10: 100 in April and only 60 in May
        $this->assertSame(['amount' => 30.0, 'typical_by_now' => 80.0, 'day' => 10], $d['current']);
        $this->assertSame(330.0, $d['total']);
    }

    public function testSavingsAreLeftOutAndFixedIsOptional(): void
    {
        $rows = [self::row('2026-05', 100), self::row('2026-05', 500, 'Housing', 'Rent', 'fixed'), self::row('2026-05', 900, 'Savings', 'Fund', 'savings')];
        $this->assertSame(600.0, self::compute($rows)['total']);
        $this->assertSame(100.0, self::compute($rows, false)['total']);
        $this->assertSame(['Food'], array_column(self::compute($rows, false)['groups'], 'name'));
    }

    public function testAMonthWithOnlyFixedStaysAsZero(): void
    {
        $d = self::compute([self::row('2026-04', 500, 'Housing', 'Rent', 'fixed'), self::row('2026-05', 100)], false);
        $this->assertSame(['2026-04', '2026-05'], $d['months']);
        $this->assertSame([0.0, 100.0], $d['totals']);
    }

    public function testFutureMonthsAreIgnored(): void
    {
        $this->assertSame(['2026-05'], self::compute([self::row('2026-05', 1), self::row('2026-07', 50)])['months']);
    }

    public function testGroupChangeNeedsFourFinishedMonths(): void
    {
        $rows = [self::row('2026-03', 100), self::row('2026-04', 100), self::row('2026-05', 100)];
        $this->assertNull(self::compute($rows)['groups'][0]['change']);
        $group = self::compute([self::row('2026-02', 100), ...$rows])['groups'][0];
        $this->assertSame(0.0, $group['change']);
        $group = self::compute([self::row('2026-01', 100), self::row('2026-02', 100), self::row('2026-03', 100), self::row('2026-04', 100), self::row('2026-05', 400)])['groups'][0];
        $this->assertSame([100.0, 200.0, 100.0], [$group['typical'], $group['recent'], $group['change']]);
    }

    public function testChangesCompareWithTheMonthsBefore(): void
    {
        $changes = self::compute([
            self::row('2026-02', 100), self::row('2026-03', 100), self::row('2026-04', 100), self::row('2026-05', 400),
            self::row('2026-04', 300, 'Fun', 'Trips'), self::row('2026-04', 50, 'Fun', 'Games'), self::row('2026-03', 50, 'Fun', 'Games'), self::row('2026-02', 50, 'Fun', 'Games'), self::row('2026-05', 50, 'Fun', 'Games'),
            self::row('2026-06', 999, 'Fun', 'Games'),
        ])['changes'];
        $this->assertSame(['2026-05', ['2026-02', '2026-03', '2026-04']], [$changes['month'], $changes['before']]);
        $this->assertSame([['Groceries', 300.0]], array_map(static fn (array $c): array => [$c['category'], $c['difference']], $changes['up']));
        $this->assertSame([['Trips', -100.0]], array_map(static fn (array $c): array => [$c['category'], $c['difference']], $changes['down']));
    }

    public function testJanuaryComparesWithThePreviousYear(): void
    {
        $d = Trends::compute([self::row('2025-11', 100), self::row('2025-12', 200), self::row('2026-01', 400)], 2026, '2026-02', 3, true);
        $this->assertSame(['2025-11', '2025-12'], $d['changes']['before']);
        $this->assertSame(250.0, $d['changes']['up'][0]['difference']);
        $this->assertSame([['2026-01'], 400.0], [$d['months'], $d['total']]);
        $this->assertSame(400.0, $d['top'][0]['amount']);
    }

    public function testTopIsRankedAndAddsUp(): void
    {
        $top = self::compute([self::row('2026-04', 25), self::row('2026-05', 75, 'Fun', 'Trips')])['top'];
        $this->assertSame([['Trips', 75.0], ['Groceries', 25.0]], array_map(static fn (array $c): array => [$c['category'], $c['share']], $top));
    }

    public function testEmptyYear(): void
    {
        $d = self::compute([]);
        $this->assertSame([[], 0, null, null, []], [$d['months'], $d['total'], $d['typical'], $d['changes'], $d['top']]);
    }

    public function testChartReferenceFitsAndMarksThePartialColumn(): void
    {
        $chart = Chart::columns(['Jan', 'Feb'], ['Spending' => [10.0, 20.0]], ['value' => 90.0, 'label' => 'x'], 1);
        $this->assertGreaterThanOrEqual($chart['top'], $chart['reference']['y']);
        $this->assertSame([false, true], array_column($chart['bars'], 'partial'));
        $mini = Chart::mini([0.0, 5.0], 10.0, null);
        $this->assertSame(4.0, $mini['reference']);
        $this->assertSame(288, $mini['width']);
        $this->assertNull(Chart::columns(['Jan'], ['a' => [1.0]])['reference']);
    }
}

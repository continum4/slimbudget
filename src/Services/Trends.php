<?php
declare(strict_types=1);

namespace App\Services;

use App\Database;

/**
 * Spending trends of a year. Savings are left out. "Typical" is the median of the finished months, so one odd
 * month does not distort it; the month in progress is shown but never counts for it.
 */
final class Trends
{
    private const RECENT = 3;
    private const MIN_FINISHED = 4;

    public function __construct(private Database $db)
    {
    }

    public function year(int $year, bool $includeFixed): array
    {
        // A few months of the previous year, to compare January with
        $rows = $this->db->all(
            'SELECT m.ym, DAY(x.spent_on) day, g.name group_name, g.kind, c.name category, SUM(x.amount) amount
             FROM expenses x JOIN months m ON m.id=x.month_id JOIN categories c ON c.id=x.category_id JOIN category_groups g ON g.id=c.group_id
             WHERE m.ym BETWEEN ? AND ? GROUP BY m.ym, DAY(x.spent_on), g.id, g.name, g.kind, c.id, c.name ORDER BY g.name, c.name',
            [($year - 1) . '-10', "$year-12"]
        );
        return self::compute($rows, $year, date('Y-m'), (int) date('j'), $includeFixed);
    }

    /** Rows come sorted by group and category. @param list<array{ym:string,day:int|string,group_name:string,kind:string,category:string,amount:float|string}> $rows */
    public static function compute(array $rows, int $year, string $currentYm, int $currentDay, bool $includeFixed): array
    {
        $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['ym'] <= $currentYm));
        $allMonths = array_values(array_unique(array_column($rows, 'ym')));
        sort($allMonths);
        $months = array_values(array_filter($allMonths, static fn (string $ym): bool => str_starts_with($ym, "$year-")));
        $finished = array_values(array_filter($months, static fn (string $ym): bool => $ym < $currentYm));
        $rows = array_filter($rows, static fn (array $r): bool => $r['kind'] !== 'savings' && ($includeFixed || $r['kind'] !== 'fixed'));

        $totals = array_fill_keys($months, 0.0);
        $byNow = array_fill_keys($finished, 0.0);
        $groups = $categories = [];
        foreach ($rows as $r) {
            $amount = (float) $r['amount'];
            $key = $r['group_name'] . "\0" . $r['category'];
            $categories[$key][$r['ym']] = ($categories[$key][$r['ym']] ?? 0.0) + $amount;
            if (!isset($totals[$r['ym']])) {
                continue; // previous year, only for the comparison of categories
            }
            $totals[$r['ym']] += $amount;
            $groups[$r['group_name']][$r['ym']] = ($groups[$r['group_name']][$r['ym']] ?? 0.0) + $amount;
            if (isset($byNow[$r['ym']]) && (int) $r['day'] <= $currentDay) {
                $byNow[$r['ym']] += $amount;
            }
        }
        $inProgress = in_array($currentYm, $months, true) ? $currentYm : null;
        $total = array_sum($totals);
        $only = static fn (array $values, array $keys): array => array_map(static fn (string $ym): float => $values[$ym] ?? 0.0, $keys);

        return [
            'months' => $months,
            'finished' => count($finished),
            'in_progress' => $inProgress,
            'totals' => $only($totals, $months),
            'total' => $total,
            'typical' => self::median($only($totals, $finished)),
            'current' => $inProgress ? ['amount' => $totals[$inProgress], 'typical_by_now' => self::median(array_values($byNow)), 'day' => $currentDay] : null,
            'groups' => self::groups($groups, $months, $finished, $only),
            'changes' => self::changes($categories, $allMonths, $finished),
            'top' => self::top($categories, $months, $total),
        ];
    }

    public static function median(array $values): ?float
    {
        if (!$values) {
            return null;
        }
        sort($values);
        $middle = intdiv(count($values), 2);
        return count($values) % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private static function groups(array $groups, array $months, array $finished, callable $only): array
    {
        $list = [];
        foreach ($groups as $name => $values) {
            $typical = self::median($only($values, $finished));
            $last = array_slice($only($values, $finished), -self::RECENT);
            $recent = $last ? array_sum($last) / count($last) : null;
            $list[] = [
                'name' => (string) $name, 'values' => $only($values, $months), 'total' => array_sum($values), 'typical' => $typical, 'recent' => $recent,
                'change' => count($finished) >= self::MIN_FINISHED && $typical > 0 ? ($recent - $typical) / $typical * 100 : null,
            ];
        }
        return $list;
    }

    /** Last finished month against the average of up to three months before it. */
    private static function changes(array $categories, array $allMonths, array $finished): ?array
    {
        $month = end($finished) ?: null;
        $before = array_slice(array_values(array_filter($allMonths, static fn (string $ym): bool => $month !== null && $ym < $month)), -self::RECENT);
        if (!$before) {
            return null;
        }
        $up = $down = [];
        foreach ($categories as $key => $values) {
            $now = $values[$month] ?? 0.0;
            $average = array_sum(array_map(static fn (string $ym): float => $values[$ym] ?? 0.0, $before)) / count($before);
            $difference = round($now - $average, 2);
            if ($difference == 0) {
                continue;
            }
            [$group, $name] = explode("\0", $key, 2);
            $line = ['group' => $group, 'category' => $name, 'now' => $now, 'average' => $average, 'difference' => $difference];
            $difference > 0 ? $up[] = $line : $down[] = $line;
        }
        usort($up, static fn (array $a, array $b): int => $b['difference'] <=> $a['difference']);
        usort($down, static fn (array $a, array $b): int => $a['difference'] <=> $b['difference']);
        return ['month' => $month, 'before' => $before, 'up' => array_slice($up, 0, 5), 'down' => array_slice($down, 0, 5)];
    }

    private static function top(array $categories, array $months, float $total): array
    {
        $list = [];
        foreach ($categories as $key => $values) {
            $sum = array_sum(array_intersect_key($values, array_flip($months)));
            if ($sum > 0) {
                [$group, $name] = explode("\0", $key, 2);
                $list[] = ['group' => $group, 'category' => $name, 'amount' => $sum, 'share' => $total > 0 ? $sum / $total * 100 : 0.0];
            }
        }
        usort($list, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);
        return array_slice($list, 0, 10);
    }
}

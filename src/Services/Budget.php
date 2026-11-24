<?php
declare(strict_types=1);

namespace App\Services;

use App\Database;

/** Monthly budget rules: estimates, spending, bills and installment plans. */
final class Budget
{
    public function __construct(private Database $db)
    {
    }

    public function month(string $ym): ?array
    {
        return $this->db->row('SELECT * FROM months WHERE ym=?', [$ym]);
    }

    /** Month id. A new month copies the previous estimates and salary, and gets the recurring bills. */
    public function ensureMonth(string $ym): int
    {
        if ($month = $this->month($ym)) {
            return (int) $month['id'];
        }
        return $this->db->transaction(function () use ($ym): int {
            $id = $this->db->insert('months', ['ym' => $ym]);
            $previous = (int) ($this->db->value('SELECT id FROM months WHERE ym<? ORDER BY ym DESC LIMIT 1', [$ym]) ?: 0);
            $this->db->run(
                'INSERT INTO estimates (month_id, category_id, mode, amount)
                 SELECT ?, c.id, COALESCE(e.mode, c.mode), COALESCE(e.amount, 0)
                 FROM categories c LEFT JOIN estimates e ON e.category_id=c.id AND e.month_id=? WHERE c.active=1',
                [$id, $previous]
            );
            $this->db->run(
                "INSERT INTO incomes (month_id, kind, description, amount)
                 SELECT ?, kind, description, amount FROM incomes WHERE month_id=? AND kind='salary'",
                [$id, $previous]
            );
            $this->generateBills($ym, $id);
            return $id;
        });
    }

    /** Adds the recurring bills the month does not have yet; returns how many. */
    public function generateBills(string $ym, int $monthId): int
    {
        $missing = $this->db->all(
            'SELECT * FROM bill_templates t WHERE active=1 AND NOT EXISTS (SELECT 1 FROM bills b WHERE b.month_id=? AND b.template_id=t.id)',
            [$monthId]
        );
        foreach ($missing as $t) {
            $this->db->insert('bills', [
                'month_id' => $monthId, 'template_id' => $t['id'], 'name' => $t['name'], 'due_on' => Month::date($ym, (int) $t['day']),
                'amount' => $t['amount'], 'category_id' => $t['category_id'], 'group_id' => $t['group_id'],
            ]);
        }
        return count($missing);
    }

    /** Estimated, paid and balance by group and category, plus the balance of the month. */
    public function summary(string $ym): array
    {
        $id = (int) ($this->month($ym)['id'] ?? 0);
        $rows = $this->db->all(
            "SELECT g.id group_id, g.name group_name, c.id category_id, c.name, COALESCE(e.mode, c.mode) mode,
                    COALESCE(x.paid, 0) paid,
                    CASE COALESCE(e.mode, c.mode)
                      WHEN 'auto' THEN COALESCE(x.paid, 0)
                      WHEN 'installments' THEN COALESCE(d.installment, 0)
                      ELSE COALESCE(e.amount, 0) END estimated
             FROM categories c
             JOIN category_groups g ON g.id = c.group_id
             LEFT JOIN estimates e ON e.category_id = c.id AND e.month_id = ?
             LEFT JOIN (SELECT category_id, SUM(amount) paid FROM expenses WHERE month_id = ? GROUP BY category_id) x ON x.category_id = c.id
             LEFT JOIN (SELECT k.category_id, SUM(p.total_amount / p.installments) installment
                        FROM installment_plans p JOIN cards k ON k.id = p.card_id
                        WHERE p.start_month <= ? AND p.end_month >= ? GROUP BY k.category_id) d ON d.category_id = c.id
             WHERE c.active = 1 OR e.category_id IS NOT NULL OR x.paid IS NOT NULL
             ORDER BY g.name, c.name",
            [$id, $id, $ym, $ym]
        );
        $groups = [];
        $estimated = $paid = 0.0;
        foreach ($rows as $row) {
            $row['estimated'] = round((float) $row['estimated'], 2);
            $row['paid'] = round((float) $row['paid'], 2);
            $key = $row['group_id'];
            $groups[$key] ??= ['id' => $key, 'name' => $row['group_name'], 'categories' => [], 'estimated' => 0.0, 'paid' => 0.0];
            $groups[$key]['categories'][] = $row + ['balance' => round($row['estimated'] - $row['paid'], 2)];
            $groups[$key]['estimated'] = round($groups[$key]['estimated'] + $row['estimated'], 2);
            $groups[$key]['paid'] = round($groups[$key]['paid'] + $row['paid'], 2);
            $estimated += $row['estimated'];
            $paid += $row['paid'];
        }
        foreach ($groups as $key => $group) {
            $groups[$key]['balance'] = round($group['estimated'] - $group['paid'], 2);
            $groups[$key]['status'] = self::status($group['estimated'], $group['paid']);
        }

        $incomes = $this->db->all('SELECT * FROM incomes WHERE month_id=? ORDER BY kind, id', [$id]);
        $bySpending = $this->db->all(
            'SELECT p.name, SUM(x.amount) amount FROM expenses x JOIN people p ON p.id=x.person_id
             WHERE x.month_id=? AND p.spending_is_income=1 GROUP BY p.id, p.name',
            [$id]
        );
        $income = array_sum(array_column($incomes, 'amount')) + array_sum(array_column($bySpending, 'amount'));

        return [
            'open' => $id > 0,
            'groups' => array_values($groups),
            'estimated' => round($estimated, 2),
            'paid' => round($paid, 2),
            'difference' => round($estimated - $paid, 2),
            'status' => self::status($estimated, $paid),
            'incomes' => $incomes,
            'incomes_by_spending' => $bySpending,
            'total_income' => round($income, 2),
            'balance' => round($income - $paid, 2),
        ];
    }

    /** @return array{level:string,icon:string,key:string,percent:?string} */
    public static function status(float $estimated, float $paid): array
    {
        $estimated = round($estimated, 2);
        $paid = round($paid, 2);
        if ($estimated == 0) {
            return $paid == 0
                ? ['level' => 'idle', 'icon' => '○', 'key' => 'unused', 'percent' => null]
                : ['level' => 'critical', 'icon' => '▲', 'key' => 'no_budget', 'percent' => null];
        }
        if ($paid == 0) {
            return ['level' => 'warn', 'icon' => '◔', 'key' => 'no_records', 'percent' => null];
        }
        $percent = $paid / $estimated * 100;
        // 100.2% is not rounded to 100%: it would look exact when it is already over
        $decimals = $paid != $estimated && round($percent) == 100 ? 1 : 0;
        $over = $paid > $estimated;
        return ['level' => $over ? 'critical' : 'good', 'icon' => $over ? '▲' : '✓', 'key' => 'used', 'percent' => number_format($percent, $decimals)];
    }

    public function bills(string $ym): array
    {
        $rows = $this->db->all(
            'SELECT b.*, c.name category, g.name group_name,
                    COALESCE((SELECT SUM(x.amount) FROM expenses x WHERE x.bill_id = b.id), 0) linked,
                    CASE WHEN b.group_id IS NULL THEN 0 ELSE COALESCE((SELECT SUM(x.amount) FROM expenses x JOIN categories k ON k.id = x.category_id
                         WHERE x.month_id = b.month_id AND k.group_id = b.group_id), 0) END group_spent
             FROM bills b JOIN months m ON m.id = b.month_id
             LEFT JOIN categories c ON c.id = b.category_id LEFT JOIN category_groups g ON g.id = b.group_id
             WHERE m.ym = ? ORDER BY b.due_on, b.name',
            [$ym]
        );
        foreach ($rows as &$bill) {
            $bill['paid'] = round($bill['group_id'] ? (float) $bill['group_spent'] : (float) $bill['linked'] + (float) $bill['unlinked_payment'], 2);
            $bill['remaining'] = round(max(0, (float) $bill['amount'] - $bill['paid']), 2);
            $bill['status'] = match (true) {
                (float) $bill['amount'] == 0 => 'no_charge',
                $bill['remaining'] == 0 => 'paid',
                $bill['paid'] > 0 => 'partial',
                $bill['due_on'] < date('Y-m-d') => 'overdue',
                default => 'pending',
            };
        }
        return $rows;
    }

    /** Installment plans; "left" counts the payments after $ym. */
    public function plans(string $ym): array
    {
        return $this->db->all(
            "SELECT p.*, k.name card, ROUND(p.total_amount / p.installments, 2) installment,
                    (p.start_month <= ? AND p.end_month >= ?) active_now,
                    CASE WHEN p.settled = 1 THEN 0
                         ELSE LEAST(p.installments, GREATEST(0, PERIOD_DIFF(REPLACE(p.end_month,'-',''), REPLACE(?,'-','')))) END left_count
             FROM installment_plans p JOIN cards k ON k.id = p.card_id
             ORDER BY p.end_month, k.name, p.concept",
            [$ym, $ym, $ym]
        );
    }

    /**
     * Purchase dates included in the statement paid in $ym.
     * Without a closing day, the statement is the previous calendar month. With one, it closes on that day
     * (included) and is paid the next month, or the same month when the due day comes after the closing day.
     *
     * @return array{0:string,1:string}
     */
    public static function statementPeriod(string $ym, ?int $closingDay, ?int $dueDay): array
    {
        if (!$closingDay) {
            $previous = Month::add($ym, -1);
            return ["$previous-01", Month::date($previous, 31)];
        }
        $closing = $dueDay && $dueDay > $closingDay ? $ym : Month::add($ym, -1);
        $from = new \DateTimeImmutable(Month::date(Month::add($closing, -1), $closingDay));
        return [$from->modify('+1 day')->format('Y-m-d'), Month::date($closing, $closingDay)];
    }

    /**
     * What each card charges in $ym: regular purchases of its statement plus the installments of the month.
     * Informative only: purchases already count as expenses, and installments as debt.
     */
    public function cardStatements(string $ym): array
    {
        $list = [];
        foreach ($this->db->all('SELECT * FROM cards ORDER BY name') as $card) {
            $dueDay = $card['due_day'] === null ? null : (int) $card['due_day'];
            [$from, $to] = self::statementPeriod($ym, $card['closing_day'] === null ? null : (int) $card['closing_day'], $dueDay);
            $regular = round((float) $this->db->value('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE card_id=? AND spent_on BETWEEN ? AND ?', [$card['id'], $from, $to]), 2);
            $installments = round((float) $this->db->value(
                'SELECT COALESCE(SUM(total_amount/installments),0) FROM installment_plans WHERE card_id=? AND start_month<=? AND end_month>=?',
                [$card['id'], $ym, $ym]
            ), 2);
            if ($regular == 0 && $installments == 0) {
                continue;
            }
            $list[] = [
                'id' => $card['id'], 'name' => $card['name'], 'from' => $from, 'to' => $to, 'due_on' => $dueDay ? Month::date($ym, $dueDay) : null,
                'regular' => $regular, 'installments' => $installments, 'total' => round($regular + $installments, 2),
                'still_open' => $to >= date('Y-m-d'),
            ];
        }
        return $list;
    }

    /** Sum of active installments per month, starting at $ym. */
    public function projection(string $ym, int $months = 18): array
    {
        $list = [];
        for ($i = 0; $i < $months; $i++) {
            $month = Month::add($ym, $i);
            $list[] = ['ym' => $month, 'amount' => (float) $this->db->value(
                'SELECT COALESCE(ROUND(SUM(total_amount/installments),2),0) FROM installment_plans WHERE start_month<=? AND end_month>=?',
                [$month, $month]
            )];
        }
        return $list;
    }
}

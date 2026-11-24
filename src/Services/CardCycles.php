<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Statement dates of one card, which can change over time.
 * The statement of a month includes the purchases after the previous closing date, up to its own (included).
 * Without a closing day it closes on the last day of the month. It is paid the next month, or the same month
 * when its due day comes after its closing day.
 */
final class CardCycles
{
    public const BEGINNING = '0000-00';

    /** @param list<array{from_month:string,closing_day:int|string|null,due_day:int|string|null}> $cycles sorted by from_month */
    public function __construct(private array $cycles = [])
    {
    }

    /** @return array{closing_day:?int,due_day:?int} */
    public function at(string $ym): array
    {
        $current = ['closing_day' => null, 'due_day' => null];
        foreach ($this->cycles as $cycle) {
            if ($cycle['from_month'] <= $ym) {
                $current = [
                    'closing_day' => $cycle['closing_day'] === null ? null : (int) $cycle['closing_day'],
                    'due_day' => $cycle['due_day'] === null ? null : (int) $cycle['due_day'],
                ];
            }
        }
        return $current;
    }

    public function closingDate(string $ym): string
    {
        return Month::date($ym, $this->at($ym)['closing_day'] ?? 31);
    }

    /** Month in which the statement closing in $ym is paid. */
    public function paymentMonth(string $ym): string
    {
        $cycle = $this->at($ym);
        return $cycle['closing_day'] && $cycle['due_day'] && $cycle['due_day'] > $cycle['closing_day'] ? $ym : Month::add($ym, 1);
    }

    /**
     * Purchases paid in $ym. A change of dates can leave a month with no statement to pay, or with two in a row.
     *
     * @return array{from:string,to:string,due_on:?string}|null
     */
    public function period(string $ym): ?array
    {
        $statements = array_values(array_filter([Month::add($ym, -1), $ym], fn (string $closing): bool => $this->paymentMonth($closing) === $ym));
        if (!$statements) {
            return null;
        }
        $last = end($statements);
        $previous = new \DateTimeImmutable($this->closingDate(Month::add($statements[0], -1)));
        $dueDay = $this->at($last)['due_day'];
        return [
            'from' => $previous->modify('+1 day')->format('Y-m-d'),
            'to' => $this->closingDate($last),
            'due_on' => $dueDay ? Month::date($ym, $dueDay) : null,
        ];
    }
}

<?php
declare(strict_types=1);

namespace App\Services;

/** Formats and parses amounts for one currency from config/currencies.json. It never converts. */
final class Money
{
    private const MINUS = '−';

    /** @param array{name:string,symbol:string,position:string,decimal:string,thousands:string,decimals:int} $currency */
    public function __construct(private array $currency)
    {
    }

    /** @return array<string,array> */
    public static function currencies(string $file): array
    {
        return json_decode(file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
    }

    public static function fromCode(string $code, string $file): self
    {
        $all = self::currencies($file);
        return new self($all[$code] ?? $all['USD']);
    }

    public function format(float|int|string|null $amount, ?int $decimals = null): string
    {
        $c = $this->currency;
        $n = (float) $amount;
        $text = number_format(abs($n), $decimals ?? $c['decimals'], $c['decimal'], $c['thousands']);
        $text = $c['position'] === 'after' ? $text . $c['symbol'] : $c['symbol'] . $text;
        return ($n < 0 ? self::MINUS : '') . $text;
    }

    /** Value for a text input: no symbol and no thousands separator. */
    public function input(float|int|string|null $amount): string
    {
        return $amount === null || $amount === '' ? '' : number_format((float) $amount, $this->currency['decimals'], $this->currency['decimal'], '');
    }

    /** User input to a decimal string such as "1234.50", or null when it is not a valid amount. */
    public function parse(string $text): ?string
    {
        $c = $this->currency;
        $text = str_replace([trim($c['symbol']), ' ', "\u{a0}"], '', trim($text));
        $t = preg_quote($c['thousands'], '/');
        $fraction = $c['decimals'] ? '(?:' . preg_quote($c['decimal'], '/') . "\\d{1,{$c['decimals']}})?" : '';
        // Separators must group by three, so "1,50" is an error and not 150
        if (!preg_match("/^(?:\\d{1,9}|\\d{1,3}(?:$t\\d{3}){1,2})$fraction$/u", $text)) {
            return null;
        }
        return str_replace([$c['thousands'], $c['decimal']], ['', '.'], $text);
    }

    /** HTML pattern matching what parse() accepts. */
    public function pattern(): string
    {
        $c = $this->currency;
        $t = '\\u{' . dechex(mb_ord($c['thousands'])) . '}';
        $fraction = $c['decimals'] ? '(?:\\u{' . dechex(mb_ord($c['decimal'])) . "}\\d{1,{$c['decimals']}})?" : '';
        return "(?:\\d{1,9}|\\d{1,3}(?:$t\\d{3}){1,2})$fraction";
    }

    public function example(): string
    {
        return $this->input('1234.5');
    }
}

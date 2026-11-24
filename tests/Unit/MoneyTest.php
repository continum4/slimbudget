<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    private const FILE = __DIR__ . '/../../config/currencies.json';

    public static function amounts(): array
    {
        return [
            ['USD', '1,234.50', '1234.50'], ['USD', '1234.5', '1234.5'], ['USD', '$1,234', '1234'], ['USD', '1,50', null], ['USD', '-5', null], ['USD', '1.234', null], ['USD', '', null],
            ['EUR', '1.234,50', '1234.50'], ['EUR', '1,5 €', '1.5'], ['EUR', '1.50', null], ['CLP', '1.234', '1234'], ['CLP', '10,5', null],
        ];
    }

    #[DataProvider('amounts')]
    public function testParse(string $code, string $text, ?string $expected): void
    {
        $this->assertSame($expected, Money::fromCode($code, self::FILE)->parse($text));
    }

    public function testFormat(): void
    {
        $this->assertSame('−$1,234.50', Money::fromCode('USD', self::FILE)->format(-1234.5));
        $this->assertSame('1.234,50 €', Money::fromCode('EUR', self::FILE)->format('1234.50'));
        $this->assertSame('$1.235', Money::fromCode('CLP', self::FILE)->format(1234.5));
        $this->assertSame('$1,235', Money::fromCode('USD', self::FILE)->format(1234.5, 0));
    }

    public function testEveryCurrencyReadsBackWhatItShows(): void
    {
        foreach (array_keys(Money::currencies(self::FILE)) as $code) {
            $money = Money::fromCode($code, self::FILE);
            $this->assertNotNull($money->parse($money->format(98765)), $code);
            $this->assertNotNull($money->parse($money->input(98765)), $code);
        }
    }
}

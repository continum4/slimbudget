<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    private const DIR = __DIR__ . '/../../lang';

    public function testPluralsAndParameters(): void
    {
        $t = new Translator(self::DIR, 'en');
        $this->assertSame('1 expense', $t->t('expenses.count', ['count' => 1]));
        $this->assertSame('0 expenses', $t->t('expenses.count', ['count' => 0]));
        $this->assertSame('November 2026', $t->month('2026-11'));
        $this->assertSame('Nov 5', $t->day('2026-11-05'));
        $t->use('es');
        $this->assertSame('5 nov', $t->day('2026-11-05'));
    }

    public function testUnknownLanguageOrKeyFallsBack(): void
    {
        $t = new Translator(self::DIR, '../config');
        $this->assertSame('en', $t->locale());
        $this->assertSame('no.such.key', $t->t('no.such.key'));
        $this->assertFalse($t->exists('../../etc/passwd'));
    }

    /** Every language file has the same keys and parameters as English. */
    public function testLanguagesAreComplete(): void
    {
        $english = $this->flatten(json_decode(file_get_contents(self::DIR . '/en.json'), true));
        foreach (glob(self::DIR . '/*.json') as $file) {
            $texts = $this->flatten(json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR));
            $this->assertSame([], array_diff_key($english, $texts), basename($file) . ' misses keys');
            $this->assertSame([], array_diff_key($texts, $english), basename($file) . ' has unknown keys');
            foreach ($english as $key => $text) {
                $this->assertSame($this->parameters($text), $this->parameters($texts[$key]), basename($file) . ": $key");
            }
        }
    }

    private function flatten(array $node, string $prefix = ''): array
    {
        $flat = [];
        foreach ($node as $key => $value) {
            $flat += is_array($value) && !array_is_list($value) ? $this->flatten($value, "$prefix$key.") : ["$prefix$key" => is_array($value) ? implode('|', array_fill(0, count($value), '')) : $value];
        }
        return $flat;
    }

    private function parameters(string $text): array
    {
        preg_match_all('/\{(\w+)\}/', $text, $m);
        $names = array_unique($m[1]);
        sort($names);
        return $names;
    }
}

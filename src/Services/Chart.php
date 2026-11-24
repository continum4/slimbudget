<?php
declare(strict_types=1);

namespace App\Services;

/** Geometry for grouped columns; templates/_chart.twig draws the SVG. */
final class Chart
{
    private const HEIGHT = 220;
    private const LEFT = 56;
    private const TOP = 12;

    /** @param array<string,list<float>> $series */
    public static function columns(array $labels, array $series): array
    {
        $n = max(1, count($labels));
        $count = count($series);
        $band = max(56, min(96, intdiv(1060, $n)));
        $width = min(24, (int) (($band - 12 - 2 * ($count - 1)) / $count));
        $max = max([1.0, ...array_merge(...array_values($series) ?: [[]])]);
        $step = self::step($max);
        $limit = ceil($max / $step) * $step;
        $base = self::TOP + self::HEIGHT;
        $y = static fn (float $value): float => round($base - $value / $limit * self::HEIGHT, 1);

        $bars = [];
        $s = 0;
        foreach ($series as $name => $values) {
            foreach ($values as $i => $value) {
                $x = self::LEFT + $i * $band + ($band - ($count * $width + 2 * ($count - 1))) / 2 + $s * ($width + 2);
                $top = $y((float) $value);
                $radius = min(4, $base - $top);
                $bars[] = [
                    'series' => $s + 1, 'name' => $name, 'label' => $labels[$i], 'value' => $value,
                    'd' => sprintf('M%1$s %2$sV%3$sq0 -%4$s %4$s -%4$sh%5$sq%4$s 0 %4$s %4$sV%2$sz', $x, $base, $top + $radius, $radius, $width - 2 * $radius),
                    'hit_x' => $x - 2, 'hit_width' => $width + 4,
                ];
            }
            $s++;
        }
        $ticks = [];
        for ($value = 0.0; $value <= $limit + 0.001; $value += $step) {
            $ticks[] = ['y' => $y($value), 'text' => self::tick($value, $limit)];
        }
        return [
            'width' => self::LEFT + $n * $band + 8, 'height' => $base + 28, 'left' => self::LEFT, 'top' => self::TOP, 'plot' => self::HEIGHT, 'base' => $base,
            'series' => array_keys($series), 'bars' => $bars, 'ticks' => $ticks,
            'axis' => array_map(static fn (int $i): array => ['x' => self::LEFT + $i * $band + $band / 2, 'text' => $labels[$i]], array_keys($labels)),
        ];
    }

    private static function tick(float $value, float $limit): string
    {
        foreach ([[1e6, 'M'], [1e4, 'K']] as [$from, $unit]) {
            if ($limit >= $from) {
                $scaled = $value / ($unit === 'M' ? 1e6 : 1e3);
                return $value ? rtrim(rtrim(number_format($scaled, 1), '0'), '.') . $unit : '0';
            }
        }
        return number_format($value);
    }

    private static function step(float $max): float
    {
        $magnitude = 10 ** floor(log10($max / 4));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($magnitude * $m * 4 >= $max) {
                return $magnitude * $m;
            }
        }
        return $magnitude * 10;
    }
}

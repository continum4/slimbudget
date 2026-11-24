<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Interface texts from lang/<code>.json. Dropping a new file there adds a language.
 * Missing keys fall back to English. "one item | {count} items" picks by {count}.
 */
final class Translator
{
    private const FALLBACK = 'en';
    private array $texts = [];
    private string $locale = self::FALLBACK;

    public function __construct(private string $dir, string $locale = self::FALLBACK)
    {
        $this->use($locale);
    }

    /** @return array<string,string> code => own name of the language */
    public function available(): array
    {
        $list = [];
        foreach (glob($this->dir . '/*.json') as $file) {
            $list[basename($file, '.json')] = $this->load(basename($file, '.json'))['_meta']['name'] ?? basename($file, '.json');
        }
        asort($list);
        return $list;
    }

    public function use(?string $locale): void
    {
        $this->locale = $locale && $this->exists($locale) ? $locale : self::FALLBACK;
    }

    public function exists(string $locale): bool
    {
        return preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale) && is_file("$this->dir/$locale.json");
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function t(string $key, array $params = []): string
    {
        $text = $this->find($this->locale, $key) ?? $this->find(self::FALLBACK, $key) ?? $key;
        if (!is_string($text)) {
            return $key;
        }
        if (isset($params['count']) && str_contains($text, ' | ')) {
            $text = explode(' | ', $text, 2)[(int) $params['count'] === 1 ? 0 : 1];
        }
        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }
        return strtr($text, $replace);
    }

    /** A whole branch, for example the texts the browser needs. */
    public function branch(string $key): array
    {
        return array_replace((array) $this->find(self::FALLBACK, $key), (array) $this->find($this->locale, $key));
    }

    public function month(string $ym): string
    {
        return strtr($this->t('_meta.month_year'), ['{month}' => $this->branch('_meta.months')[(int) substr($ym, 5, 2) - 1], '{year}' => substr($ym, 0, 4)]);
    }

    public function monthShort(string $ym): string
    {
        return $this->branch('_meta.months_short')[(int) substr($ym, 5, 2) - 1] . ' ' . substr($ym, 2, 2);
    }

    public function day(?string $date): string
    {
        if (!$date) {
            return '—';
        }
        return strtr($this->t('_meta.day_month'), ['{day}' => (string) (int) substr($date, 8, 2), '{month}' => $this->branch('_meta.months_short')[(int) substr($date, 5, 2) - 1]]);
    }

    public function dateTime(?string $value): string
    {
        return $value ? date($this->t('_meta.datetime_format'), strtotime($value)) : '—';
    }

    private function load(string $locale): array
    {
        return $this->texts[$locale] ??= json_decode(file_get_contents("$this->dir/$locale.json"), true, 16, JSON_THROW_ON_ERROR);
    }

    private function find(string $locale, string $key): mixed
    {
        $node = $this->load($locale);
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }
        return $node;
    }
}

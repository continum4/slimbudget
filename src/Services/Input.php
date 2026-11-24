<?php
declare(strict_types=1);

namespace App\Services;

/** Reads and validates form data. $label is always already translated. */
final class Input
{
    public function __construct(private array $data, private Translator $translator, private Money $money)
    {
    }

    public function nested(array $data): self
    {
        return new self($data, $this->translator, $this->money);
    }

    public function raw(string $key): string
    {
        return is_string($this->data[$key] ?? null) ? trim($this->data[$key]) : '';
    }

    public function text(string $key, string $label, int $max, bool $required = true): ?string
    {
        $value = $this->raw($key);
        if ($value === '') {
            return $required ? $this->fail('required', $label) : null;
        }
        return mb_strlen($value) > $max ? $this->fail('too_long', $label, ['max' => $max]) : $value;
    }

    public function amount(string $key, string $label, bool $allowZero = false): string
    {
        $value = $this->money->parse($this->raw($key)) ?? $this->fail('amount', $label, ['example' => $this->money->example()]);
        return !$allowZero && (float) $value <= 0 ? $this->fail('positive', $label) : $value;
    }

    public function date(string $key, string $label, bool $required = true): ?string
    {
        $value = $this->raw($key);
        if ($value === '' && !$required) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $ok = $date && $date->format('Y-m-d') === $value && Month::isValid(substr($value, 0, 7));
        return $ok ? $value : $this->fail('date', $label);
    }

    public function month(string $key, string $label): string
    {
        $value = $this->raw($key);
        return Month::isValid($value) ? $value : $this->fail('month', $label);
    }

    public function integer(string $key, string $label, int $min, int $max, bool $required = true): ?int
    {
        if (!$required && $this->raw($key) === '') {
            return null;
        }
        $value = filter_var($this->raw($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        return $value === false ? $this->fail('range', $label, ['min' => $min, 'max' => $max]) : $value;
    }

    public function id(string $key, string $label, bool $required = true): ?int
    {
        $value = filter_var($this->raw($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            return $required ? $this->fail('choose', $label) : null;
        }
        return $value;
    }

    public function option(string $key, string $label, array $options): string
    {
        $value = $this->raw($key);
        return in_array($value, $options, true) ? $value : $this->fail('option', $label);
    }

    public function checkbox(string $key): int
    {
        return $this->raw($key) === '' ? 0 : 1;
    }

    private function fail(string $rule, string $label, array $params = []): never
    {
        throw new ValidationError($this->translator->t("validation.$rule", ['field' => $label] + $params));
    }
}

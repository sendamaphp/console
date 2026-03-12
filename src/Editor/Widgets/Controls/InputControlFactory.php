<?php

namespace Sendama\Console\Editor\Widgets\Controls;

class InputControlFactory
{
    public function create(string $label, mixed $value, int $indentLevel = 1): InputControl
    {
        return match (true) {
            is_bool($value) => new CheckboxInputControl($label, $value, $indentLevel),
            is_int($value), is_float($value) => new NumberInputControl($label, $value, $indentLevel),
            is_array($value) && $this->isVector($value) => new VectorInputControl($label, $value, $indentLevel),
            is_array($value) && array_is_list($value) && $this->containsOnlyScalarValues($value) => new SelectInputControl($label, $value, null, $indentLevel),
            default => new TextInputControl($label, $this->normalizeTextValue($value), $indentLevel),
        };
    }

    private function isVector(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, ['x', 'y', 'z', 'w'], true)) {
                return false;
            }
        }

        return $this->containsOnlyScalarValues($value);
    }

    private function containsOnlyScalarValues(array $value): bool
    {
        foreach ($value as $item) {
            if (!is_scalar($item) && $item !== null) {
                return false;
            }
        }

        return true;
    }

    private function normalizeTextValue(mixed $value): string
    {
        return match (true) {
            is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES) ?: 'None',
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'None',
            default => (string) $value,
        };
    }
}

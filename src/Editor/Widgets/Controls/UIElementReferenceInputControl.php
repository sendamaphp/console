<?php

namespace Sendama\Console\Editor\Widgets\Controls;

class UIElementReferenceInputControl extends InputControl
{
    public function __construct(
        string $label,
        mixed $value,
        protected array $displayLabelsByName = [],
        int $indentLevel = 1,
        bool $isReadOnly = false,
    )
    {
        parent::__construct($label, $this->normalizeValue($value), $indentLevel, $isReadOnly);
    }

    public function setValue(mixed $value): void
    {
        $this->value = $this->normalizeValue($value);
    }

    public function renderLines(): array
    {
        return [
            $this->indentation() . $this->label . ': ' . $this->resolveDisplayValue(),
        ];
    }

    private function normalizeValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalizedValue = trim($value);

        return $normalizedValue !== '' ? $normalizedValue : null;
    }

    private function resolveDisplayValue(): string
    {
        $value = $this->value;

        if (!is_string($value) || $value === '') {
            return 'None';
        }

        return $this->displayLabelsByName[$value] ?? $value;
    }
}

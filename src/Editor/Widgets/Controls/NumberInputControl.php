<?php

namespace Sendama\Console\Editor\Widgets\Controls;

class NumberInputControl extends TextInputControl
{
    protected bool $prefersFloat = false;

    public function __construct(
        string $label,
        mixed $value,
        int $indentLevel = 1,
        bool $isReadOnly = false,
    )
    {
        parent::__construct($label, $value, $indentLevel, $isReadOnly);
        $this->prefersFloat = is_float($value) || (is_string($value) && str_contains($value, '.'));
    }

    public function handleInput(string $input): bool
    {
        if (!$this->isEditing || !preg_match('/^[0-9.\-]$/', $input)) {
            return false;
        }

        if ($input === '.' && str_contains($this->editingValue, '.')) {
            return false;
        }

        if ($input === '-' && $this->cursorPosition !== 0) {
            return false;
        }

        if ($input === '-' && str_contains($this->editingValue, '-')) {
            return false;
        }

        return parent::handleInput($input);
    }

    public function increment(): bool
    {
        if (!$this->isEditing) {
            return false;
        }

        $this->editingValue = $this->formatCommittedNumber($this->parseEditingValue() + 1);
        $this->cursorPosition = mb_strlen($this->editingValue);

        return true;
    }

    public function decrement(): bool
    {
        if (!$this->isEditing) {
            return false;
        }

        $this->editingValue = $this->formatCommittedNumber($this->parseEditingValue() - 1);
        $this->cursorPosition = mb_strlen($this->editingValue);

        return true;
    }

    protected function getRenderedValue(): string
    {
        if ($this->isEditing) {
            return parent::getRenderedValue();
        }

        return $this->formatCommittedNumber($this->value);
    }

    private function parseEditingValue(): float
    {
        if ($this->editingValue === '' || $this->editingValue === '-' || $this->editingValue === '.') {
            return 0.0;
        }

        if (!is_numeric($this->editingValue)) {
            return 0.0;
        }

        return (float) $this->editingValue;
    }

    private function formatCommittedNumber(mixed $value): string
    {
        $numericValue = is_numeric($value) ? $value + 0 : 0;

        if ($this->prefersFloat) {
            $formattedValue = rtrim(rtrim(number_format((float) $numericValue, 6, '.', ''), '0'), '.');

            return $formattedValue === '' ? '0' : $formattedValue;
        }

        return (string) (int) round((float) $numericValue);
    }

    protected function transformCommittedValue(string $editingValue): mixed
    {
        $numericValue = $this->parseEditingValue();

        return $this->prefersFloat
            ? (float) $numericValue
            : (int) round($numericValue);
    }
}

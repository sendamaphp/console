<?php

namespace Sendama\Console\Editor\Widgets\Controls;

abstract class InputControl
{
    protected bool $hasFocus = false;
    protected bool $isEditing = false;

    public function __construct(
        protected string $label,
        protected mixed $value,
        protected int $indentLevel = 1,
        protected bool $isReadOnly = false,
    )
    {
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getIndentLevel(): int
    {
        return $this->indentLevel;
    }

    public function setValue(mixed $value): void
    {
        $this->value = $value;
    }

    public function focus(): void
    {
        $this->hasFocus = true;
    }

    public function blur(): void
    {
        $this->hasFocus = false;
    }

    public function hasFocus(): bool
    {
        return $this->hasFocus;
    }

    public function isEditing(): bool
    {
        return $this->isEditing;
    }

    public function isEditable(): bool
    {
        return !$this->isReadOnly;
    }

    public function enterEditMode(): bool
    {
        if (!$this->isEditable()) {
            return false;
        }

        $this->isEditing = true;

        return true;
    }

    public function commitEdit(): bool
    {
        $this->isEditing = false;

        return true;
    }

    public function cancelEdit(): void
    {
        $this->isEditing = false;
    }

    public function handleInput(string $input): bool
    {
        return false;
    }

    public function deleteBackward(): bool
    {
        return false;
    }

    public function moveCursorLeft(): bool
    {
        return false;
    }

    public function moveCursorRight(): bool
    {
        return false;
    }

    public function increment(): bool
    {
        return false;
    }

    public function decrement(): bool
    {
        return false;
    }

    public function update(): void
    {
    }

    abstract public function renderLines(): array;

    public function renderLineDefinitions(): array
    {
        $state = $this->resolveLineState();

        return array_map(
            fn(string $line) => ['text' => $line, 'state' => $state],
            $this->renderLines(),
        );
    }

    protected function indentation(int $offset = 0): string
    {
        return str_repeat('  ', max(0, $this->indentLevel + $offset));
    }

    protected function formatScalarValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'None',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_SLASHES) ?: 'None',
        };
    }

    protected function resolveLineState(): string
    {
        return match (true) {
            $this->isEditing => 'editing',
            $this->hasFocus => 'selected',
            default => 'normal',
        };
    }

    protected function isPrintableInput(string $input): bool
    {
        return $input !== ''
            && mb_strlen($input) === 1
            && !(function_exists('ctype_cntrl') && ctype_cntrl($input));
    }
}

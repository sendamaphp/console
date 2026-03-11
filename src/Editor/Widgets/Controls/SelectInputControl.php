<?php

namespace Sendama\Console\Editor\Widgets\Controls;

class SelectInputControl extends InputControl
{
    public function __construct(
        string $label,
        protected array $options,
        mixed $value = null,
        int $indentLevel = 1,
        bool $isReadOnly = false,
    )
    {
        parent::__construct(
            $label,
            $value ?? ($options[0] ?? null),
            $indentLevel,
            $isReadOnly,
        );
    }

    public function increment(): bool
    {
        return $this->moveSelection(1);
    }

    public function decrement(): bool
    {
        return $this->moveSelection(-1);
    }

    public function renderLines(): array
    {
        return [
            $this->indentation() . $this->label . ': <' . $this->formatScalarValue($this->value) . '>',
        ];
    }

    private function moveSelection(int $offset): bool
    {
        if (!$this->isEditing || $this->options === []) {
            return false;
        }

        $currentIndex = array_search($this->value, $this->options, true);
        $currentIndex = $currentIndex === false ? 0 : $currentIndex;
        $nextIndex = ($currentIndex + $offset + count($this->options)) % count($this->options);
        $this->value = $this->options[$nextIndex];

        return true;
    }
}

<?php

namespace Sendama\Console\Editor\Widgets\Controls;

class CheckboxInputControl extends InputControl
{
    public function increment(): bool
    {
        if (!$this->isEditing) {
            return false;
        }

        $this->value = true;

        return true;
    }

    public function decrement(): bool
    {
        if (!$this->isEditing) {
            return false;
        }

        $this->value = false;

        return true;
    }

    public function renderLines(): array
    {
        $isChecked = (bool) $this->value;

        return [
            $this->indentation() . $this->label . ': [' . ($isChecked ? 'x' : ' ') . ']',
        ];
    }
}

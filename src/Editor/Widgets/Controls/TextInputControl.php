<?php

namespace Sendama\Console\Editor\Widgets\Controls;

class TextInputControl extends InputControl
{
    protected string $editingValue = '';
    protected int $cursorPosition = 0;

    public function enterEditMode(): bool
    {
        if (!parent::enterEditMode()) {
            return false;
        }

        $this->editingValue = (string) $this->value;
        $this->cursorPosition = mb_strlen($this->editingValue);

        return true;
    }

    public function commitEdit(): bool
    {
        if ($this->isEditing) {
            $this->value = $this->transformCommittedValue($this->editingValue);
        }

        return parent::commitEdit();
    }

    public function cancelEdit(): void
    {
        $this->editingValue = (string) $this->value;
        $this->cursorPosition = mb_strlen($this->editingValue);
        parent::cancelEdit();
    }

    public function handleInput(string $input): bool
    {
        if (!$this->isEditing || !$this->isPrintableInput($input)) {
            return false;
        }

        $beforeCursor = mb_substr($this->editingValue, 0, $this->cursorPosition);
        $afterCursor = mb_substr($this->editingValue, $this->cursorPosition);

        $this->editingValue = $beforeCursor . $input . $afterCursor;
        $this->cursorPosition++;

        return true;
    }

    public function deleteBackward(): bool
    {
        if (!$this->isEditing || $this->cursorPosition <= 0) {
            return false;
        }

        $beforeCursor = mb_substr($this->editingValue, 0, $this->cursorPosition - 1);
        $afterCursor = mb_substr($this->editingValue, $this->cursorPosition);

        $this->editingValue = $beforeCursor . $afterCursor;
        $this->cursorPosition--;

        return true;
    }

    public function moveCursorLeft(): bool
    {
        if (!$this->isEditing || $this->cursorPosition <= 0) {
            return false;
        }

        $this->cursorPosition--;

        return true;
    }

    public function moveCursorRight(): bool
    {
        if (!$this->isEditing || $this->cursorPosition >= mb_strlen($this->editingValue)) {
            return false;
        }

        $this->cursorPosition++;

        return true;
    }

    public function renderLines(): array
    {
        return [
            $this->indentation() . $this->label . ': ' . $this->getRenderedValue(),
        ];
    }

    protected function getRenderedValue(): string
    {
        if (!$this->isEditing) {
            return $this->formatScalarValue($this->value);
        }

        $beforeCursor = mb_substr($this->editingValue, 0, $this->cursorPosition);
        $atCursor = mb_substr($this->editingValue, $this->cursorPosition, 1);
        $afterCursor = mb_substr($this->editingValue, $this->cursorPosition + ($atCursor === '' ? 0 : 1));

        if ($atCursor === '') {
            return $beforeCursor . '|';
        }

        return $beforeCursor . '|' . $atCursor . $afterCursor;
    }

    protected function transformCommittedValue(string $editingValue): mixed
    {
        return $editingValue;
    }
}

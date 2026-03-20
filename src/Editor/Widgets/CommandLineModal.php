<?php

namespace Sendama\Console\Editor\Widgets;

class CommandLineModal extends Widget
{
    protected bool $isVisible = false;
    protected bool $isDirty = false;
    protected string $input = '';
    protected int $cursorPosition = 0;

    public function __construct()
    {
        parent::__construct(
            title: 'Command',
            help: 'Type command  Enter run  Esc cancel',
            position: ['x' => 1, 'y' => 1],
            width: 36,
            height: 3,
        );
    }

    public function show(string $initialValue = ''): void
    {
        $this->input = $initialValue;
        $this->cursorPosition = mb_strlen($initialValue);
        $this->isVisible = true;
        $this->refreshContent();
        $this->markDirty();
    }

    public function hide(): void
    {
        if (!$this->isVisible) {
            return;
        }

        $this->isVisible = false;
        $this->markDirty();
    }

    public function isVisible(): bool
    {
        return $this->isVisible;
    }

    public function isDirty(): bool
    {
        return $this->isDirty;
    }

    public function markClean(): void
    {
        $this->isDirty = false;
    }

    public function getInput(): string
    {
        return $this->input;
    }

    public function submit(): string
    {
        return trim($this->input);
    }

    public function handleInput(string $input): bool
    {
        if (!$this->isVisible || !$this->isPrintableInput($input)) {
            return false;
        }

        $beforeCursor = mb_substr($this->input, 0, $this->cursorPosition);
        $afterCursor = mb_substr($this->input, $this->cursorPosition);

        $this->input = $beforeCursor . $input . $afterCursor;
        $this->cursorPosition++;
        $this->refreshContent();
        $this->markDirty();

        return true;
    }

    public function deleteBackward(): bool
    {
        if (!$this->isVisible || $this->cursorPosition <= 0) {
            return false;
        }

        $beforeCursor = mb_substr($this->input, 0, $this->cursorPosition - 1);
        $afterCursor = mb_substr($this->input, $this->cursorPosition);

        $this->input = $beforeCursor . $afterCursor;
        $this->cursorPosition--;
        $this->refreshContent();
        $this->markDirty();

        return true;
    }

    public function moveCursorLeft(): bool
    {
        if (!$this->isVisible || $this->cursorPosition <= 0) {
            return false;
        }

        $this->cursorPosition--;
        $this->refreshContent();
        $this->markDirty();

        return true;
    }

    public function moveCursorRight(): bool
    {
        if (!$this->isVisible || $this->cursorPosition >= mb_strlen($this->input)) {
            return false;
        }

        $this->cursorPosition++;
        $this->refreshContent();
        $this->markDirty();

        return true;
    }

    public function syncLayout(int $terminalWidth, int $terminalHeight): void
    {
        $desiredWidth = max(28, min($terminalWidth - 2, 52));
        $modalWidth = min($desiredWidth, max(3, $terminalWidth - 2));
        $modalX = max(1, intdiv($terminalWidth - $modalWidth, 2) + 1);
        $modalY = max(1, $terminalHeight - 3);
        $layoutChanged =
            $this->width !== $modalWidth
            || $this->height !== 3
            || $this->x !== $modalX
            || $this->y !== $modalY;

        $this->setDimensions($modalWidth, 3);
        $this->setPosition($modalX, $modalY);
        $this->refreshContent();

        if ($layoutChanged) {
            $this->markDirty();
        }
    }

    public function update(): void
    {
    }

    protected function usesAutomaticVerticalScrolling(): bool
    {
        return false;
    }

    private function refreshContent(): void
    {
        $beforeCursor = mb_substr($this->input, 0, $this->cursorPosition);
        $atCursor = mb_substr($this->input, $this->cursorPosition, 1);
        $afterCursor = mb_substr($this->input, $this->cursorPosition + ($atCursor === '' ? 0 : 1));

        $renderedInput = $atCursor === ''
            ? $beforeCursor . '|'
            : $beforeCursor . '|' . $atCursor . $afterCursor;

        $this->content = [':' . $renderedInput];
    }

    private function markDirty(): void
    {
        $this->isDirty = true;
    }

    private function isPrintableInput(string $input): bool
    {
        return $input !== ''
            && mb_strlen($input) === 1
            && !(function_exists('ctype_cntrl') && ctype_cntrl($input));
    }
}

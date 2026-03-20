<?php

namespace Sendama\Console\Editor\Widgets;

class CommandHelpModal extends Widget
{
    protected bool $isVisible = false;
    protected bool $isDirty = false;

    public function __construct()
    {
        parent::__construct(
            title: 'Editor Help',
            help: 'Up/Down scroll  Enter close  Esc close',
            position: ['x' => 1, 'y' => 1],
            width: 56,
            height: 14,
        );
    }

    public function show(array $lines): void
    {
        $this->content = array_values(array_map(
            static fn(mixed $line): string => (string) $line,
            $lines,
        ));
        $this->verticalScrollOffset = 0;
        $this->isVisible = true;
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

    public function scroll(int $offset): void
    {
        $this->setScrollbarOffset($this->verticalScrollOffset + $offset);
        $this->markDirty();
    }

    public function syncLayout(int $terminalWidth, int $terminalHeight): void
    {
        $longestLineLength = 0;

        foreach ($this->content as $line) {
            $longestLineLength = max($longestLineLength, mb_strlen($line));
        }

        $desiredWidth = max(
            42,
            min($terminalWidth - 2, $longestLineLength + 6),
            mb_strlen($this->title) + 4,
            mb_strlen($this->help) + 4,
        );
        $desiredHeight = max(8, min(max(10, count($this->content) + 2), $terminalHeight - 2));
        $modalWidth = min($desiredWidth, max(3, $terminalWidth - 2));
        $modalHeight = min($desiredHeight, max(3, $terminalHeight - 2));
        $modalX = max(1, intdiv($terminalWidth - $modalWidth, 2) + 1);
        $modalY = max(1, intdiv($terminalHeight - $modalHeight, 2) + 1);
        $layoutChanged =
            $this->width !== $modalWidth
            || $this->height !== $modalHeight
            || $this->x !== $modalX
            || $this->y !== $modalY;

        $this->setDimensions($modalWidth, $modalHeight);
        $this->setPosition($modalX, $modalY);

        if ($layoutChanged) {
            $this->markDirty();
        }
    }

    public function update(): void
    {
    }

    private function markDirty(): void
    {
        $this->isDirty = true;
    }
}

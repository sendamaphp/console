<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\IO\Enumerations\Color;

class OptionListModal extends Widget
{
    private const string SELECTED_ROW_SEQUENCE = "\033[30;46m";

    protected bool $isVisible = false;
    protected bool $isDirty = false;
    protected array $options = [];
    protected int $selectedIndex = 0;

    public function __construct(
        string $title = 'Choose Action',
        string $help = 'Up/Down Move Enter Select Esc Back',
    )
    {
        parent::__construct(
            title: $title,
            help: $help,
            position: ['x' => 1, 'y' => 1],
            width: 28,
            height: 7,
        );
    }

    public function show(array $options, int $selectedIndex = 0): void
    {
        $this->options = array_values($options);
        $optionCount = count($this->options);
        $this->selectedIndex = $optionCount > 0
            ? max(0, min($selectedIndex, $optionCount - 1))
            : 0;
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

    public function moveSelection(int $offset): void
    {
        $optionCount = count($this->options);

        if ($optionCount === 0) {
            return;
        }

        $this->selectedIndex = ($this->selectedIndex + $offset + $optionCount) % $optionCount;
        $this->refreshContent();
        $this->markDirty();
    }

    public function getSelectedOption(): ?string
    {
        return $this->options[$this->selectedIndex] ?? null;
    }

    public function syncLayout(int $terminalWidth, int $terminalHeight): void
    {
        $longestOptionLength = 0;

        foreach ($this->options as $option) {
            $longestOptionLength = max($longestOptionLength, mb_strlen($option));
        }

        $desiredWidth = max(
            24,
            $longestOptionLength + 6,
            mb_strlen($this->title) + 4,
            mb_strlen($this->help) + 4,
        );
        $modalWidth = min(
            $desiredWidth,
            max(3, $terminalWidth - 2)
        );
        $modalHeight = min(max(5, count($this->options) + 2), max(3, $terminalHeight - 2));
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

    protected function decorateContentLine(string $line, ?Color $contentColor, int $lineIndex): string
    {
        $selectedLineIndex = $this->padding->topPadding + $this->selectedIndex;

        if ($lineIndex !== $selectedLineIndex) {
            return parent::decorateContentLine($line, $contentColor, $lineIndex);
        }

        $visibleLine = mb_substr($line, 0, $this->width);
        $visibleLength = mb_strlen($visibleLine);

        if ($visibleLength <= 1) {
            return parent::decorateContentLine($line, $contentColor, $lineIndex);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleLength > 2 ? mb_substr($visibleLine, 1, $visibleLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);

        return $this->wrapWithColor($leftBorder, $contentColor)
            . $this->wrapWithSequence($middle, self::SELECTED_ROW_SEQUENCE)
            . $this->wrapWithColor($rightBorder, $contentColor);
    }

    private function refreshContent(): void
    {
        $this->content = array_map(
            fn(string $option, int $index) => (($index === $this->selectedIndex) ? '>' : ' ') . ' ' . $option,
            $this->options,
            array_keys($this->options),
        );
    }

    private function markDirty(): void
    {
        $this->isDirty = true;
    }
}

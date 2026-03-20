<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\IO\Enumerations\Color;
use Sendama\Console\Editor\EditorColorScheme;

class OptionListModal extends Widget
{
    private const string SELECTED_ROW_SEQUENCE = EditorColorScheme::SELECTED_ROW_SEQUENCE;

    protected bool $isVisible = false;
    protected bool $isDirty = false;
    protected array $options = [];
    protected int $selectedIndex = 0;
    protected int $scrollOffset = 0;

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

    public function show(array $options, int $selectedIndex = 0, ?string $title = null): void
    {
        if (is_string($title) && $title !== '') {
            $this->title = $title;
        }

        $this->options = array_values($options);
        $optionCount = count($this->options);
        $this->selectedIndex = $optionCount > 0
            ? max(0, min($selectedIndex, $optionCount - 1))
            : 0;
        $this->scrollOffset = 0;
        $this->isVisible = true;
        $this->syncScrollOffset();
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
        $this->syncScrollOffset();
        $this->refreshContent();
        $this->markDirty();
    }

    public function getSelectedOption(): ?string
    {
        return $this->options[$this->selectedIndex] ?? null;
    }

    public function clickOptionAtPoint(int $x, int $y): ?string
    {
        if (!$this->isVisible || !$this->containsPoint($x, $y)) {
            return null;
        }

        $optionIndex = $this->resolveOptionIndexFromPoint($y);

        if ($optionIndex === null) {
            return null;
        }

        $this->selectedIndex = $optionIndex;
        $this->syncScrollOffset();
        $this->refreshContent();
        $this->markDirty();

        return $this->getSelectedOption();
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
        $this->syncScrollOffset();
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

    protected function setScrollbarOffset(int $offset): void
    {
        $visibleOptionCount = $this->getVisibleOptionCount();
        $maxScrollOffset = max(0, count($this->options) - $visibleOptionCount);
        $this->scrollOffset = max(0, min($offset, $maxScrollOffset));
        $this->refreshContent();
        $this->markDirty();
    }

    protected function resolveVerticalScrollbarState(): ?array
    {
        $visibleOptionCount = $this->getVisibleOptionCount();
        $optionCount = count($this->options);

        if ($visibleOptionCount <= 0 || $optionCount <= $visibleOptionCount) {
            return null;
        }

        return [
            'offset' => $this->scrollOffset,
            'visible' => $visibleOptionCount,
            'total' => $optionCount,
            'start' => 0,
        ];
    }

    protected function decorateContentLine(string $line, ?Color $contentColor, int $lineIndex): string
    {
        $selectedVisibleIndex = $this->selectedIndex - $this->scrollOffset;
        $selectedLineIndex = $this->padding->topPadding + $selectedVisibleIndex;

        if ($selectedVisibleIndex < 0 || $lineIndex !== $selectedLineIndex) {
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
        $visibleOptions = array_slice(
            $this->options,
            $this->scrollOffset,
            $this->getVisibleOptionCount(),
        );

        $this->content = array_map(
            fn(string $option, int $index) => (
                (($this->scrollOffset + $index) === $this->selectedIndex) ? '>' : ' '
            ) . ' ' . $option,
            $visibleOptions,
            array_keys($visibleOptions),
        );
    }

    private function markDirty(): void
    {
        $this->isDirty = true;
    }

    private function getVisibleOptionCount(): int
    {
        return max(1, $this->innerHeight - $this->padding->topPadding - $this->padding->bottomPadding);
    }

    private function syncScrollOffset(): void
    {
        $optionCount = count($this->options);

        if ($optionCount <= 0) {
            $this->scrollOffset = 0;
            return;
        }

        $visibleOptionCount = $this->getVisibleOptionCount();
        $maxScrollOffset = max(0, $optionCount - $visibleOptionCount);
        $this->scrollOffset = max(0, min($this->scrollOffset, $maxScrollOffset));

        if ($this->selectedIndex < $this->scrollOffset) {
            $this->scrollOffset = $this->selectedIndex;
            return;
        }

        $visibleEnd = $this->scrollOffset + $visibleOptionCount - 1;

        if ($this->selectedIndex > $visibleEnd) {
            $this->scrollOffset = $this->selectedIndex - $visibleOptionCount + 1;
        }
    }

    private function resolveOptionIndexFromPoint(int $y): ?int
    {
        $lineIndex = $y - $this->getContentAreaTop();

        if ($lineIndex < 0) {
            return null;
        }

        $optionIndex = $this->scrollOffset + $lineIndex;

        if (!isset($this->options[$optionIndex])) {
            return null;
        }

        return $optionIndex;
    }
}

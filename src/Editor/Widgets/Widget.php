<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\Events\MouseEvent;
use Atatusoft\Termutil\IO\Enumerations\Color;
use Atatusoft\Termutil\UI\Windows\Enumerations\HorizontalAlignment;
use Atatusoft\Termutil\UI\Windows\Window;
use Sendama\Console\Editor\FocusTargetContext;
use Sendama\Console\Editor\Interfaces\FocusableInterface;

/**
 *
 */
abstract class Widget extends Window implements FocusableInterface
{
    private const string SCROLLBAR_TRACK_CHARACTER = '░';
    private const string SCROLLBAR_THUMB_CHARACTER = '█';

    protected ?Widget $topSibling = null;
    protected ?Widget $rightSibling = null;
    protected ?Widget $bottomSibling = null;
    protected ?Widget $leftSibling = null;

    /**
     * @var int
     */
    public int $x {
        get {
            return $this->position["x"] ?? 0;
        }

        set {
            $this->position["x"] = $value;
        }
    }
    /**
     * @var int
     */
    public int $y {
        get {
            return $this->position["y"] ?? 0;
        }

        set {
            $this->position["y"] = $value;
        }
    }
    protected(set) bool $isEnabled = true;
    protected bool $hasFocus = false;
    protected Color $focusBorderColor = Color::LIGHT_CYAN;
    protected int $verticalScrollOffset = 0;
    protected bool $isScrollbarDragging = false;

    /**
     * Enables the widget.
     *
     * @return void
     */
    public function enable(): void
    {
        $this->isEnabled = true;
    }

    /**
     * Disables the widget.
     *
     * @return void
     */
    public function disable(): void
    {
        $this->isEnabled = false;
    }

    public function hasFocus(): bool
    {
        return $this->hasFocus;
    }

    public function containsPoint(int $x, int $y): bool
    {
        $left = $this->position['x'] ?? 0;
        $top = $this->position['y'] ?? 0;
        $right = $left + $this->width - 1;
        $bottom = $top + $this->height - 1;

        return $x >= $left && $x <= $right && $y >= $top && $y <= $bottom;
    }

    public function setPosition(int $x, int $y): void
    {
        $this->position = ['x' => $x, 'y' => $y];
    }

    public function setDimensions(int $width, int $height): void
    {
        $this->width = max(3, $width);
        $this->height = max(3, $height);
    }

    public function getDisplayName(): string
    {
        if (!empty($this->title)) {
            return $this->title;
        }

        $className = substr(strrchr(static::class, '\\') ?: static::class, 1) ?: static::class;

        return preg_replace('/Panel$/', '', $className) ?: $className;
    }

    public function handleMouseClick(int $x, int $y): void
    {
    }

    public function handleMouseDrag(int $x, int $y): void
    {
    }

    public function handleMouseRelease(int $x, int $y): void
    {
    }

    public function handleMouseEvent(MouseEvent $mouseEvent): void
    {
        if ($this->handleScrollbarMouseEvent($mouseEvent)) {
            return;
        }

        if ($mouseEvent->action === 'Pressed') {
            $this->handleMouseClick($mouseEvent->x, $mouseEvent->y);
            return;
        }

        if ($mouseEvent->action === 'Dragged') {
            $this->handleMouseDrag($mouseEvent->x, $mouseEvent->y);
            return;
        }

        if ($mouseEvent->action === 'Released') {
            $this->handleMouseRelease($mouseEvent->x, $mouseEvent->y);
        }
    }

    public function handleScrollbarMouseEvent(MouseEvent $mouseEvent): bool
    {
        if ($this->isScrollbarDragging) {
            if (in_array($mouseEvent->action, ['Dragged', 'Released'], true)) {
                $this->applyScrollbarPointerPosition($mouseEvent->y);

                if ($mouseEvent->action === 'Released') {
                    $this->isScrollbarDragging = false;
                }

                return true;
            }

            if ($mouseEvent->action === 'Pressed' && $mouseEvent->buttonIndex !== 0) {
                $this->isScrollbarDragging = false;
                return false;
            }
        }

        if (
            $mouseEvent->buttonIndex === 0
            && $mouseEvent->action === 'Pressed'
            && $this->containsScrollbarPoint($mouseEvent->x, $mouseEvent->y)
        ) {
            $this->isScrollbarDragging = true;
            $this->applyScrollbarPointerPosition($mouseEvent->y);

            return true;
        }

        return false;
    }

    public function containsScrollbarPoint(int $x, int $y): bool
    {
        $scrollbarState = $this->resolveVerticalScrollbarState();

        if (
            $scrollbarState === null
            || !$this->containsPoint($x, $y)
            || $x !== $this->getScrollbarColumnX()
        ) {
            return false;
        }

        $visibleLineIndex = $y - $this->getContentAreaTop();
        $contentAreaRowIndex = $visibleLineIndex - $this->padding->topPadding;

        if (!is_int($contentAreaRowIndex) || $contentAreaRowIndex < 0) {
            return false;
        }

        $start = max(0, (int) ($scrollbarState['start'] ?? 0));
        $visible = max(0, (int) ($scrollbarState['visible'] ?? 0));

        return $contentAreaRowIndex >= $start && $contentAreaRowIndex < ($start + $visible);
    }

    public function isScrollbarDragging(): bool
    {
        return $this->isScrollbarDragging;
    }

    public function cycleFocusForward(): bool
    {
        return false;
    }

    public function cycleFocusBackward(): bool
    {
        return false;
    }

    public function hasActiveModal(): bool
    {
        return false;
    }

    public function isModalDirty(): bool
    {
        return false;
    }

    public function markModalClean(): void
    {
    }

    public function syncModalLayout(int $terminalWidth, int $terminalHeight): void
    {
    }

    public function renderActiveModal(): void
    {
    }

    public function handleModalMouseEvent(MouseEvent $mouseEvent): bool
    {
        return false;
    }

    public function consumeModalBackgroundRefreshRequest(): bool
    {
        return false;
    }

    public function setTopSibling(?Widget $widget): void
    {
        $this->topSibling = $widget;
    }

    public function getTopSibling(): ?Widget
    {
        return $this->topSibling;
    }

    public function setRightSibling(?Widget $widget): void
    {
        $this->rightSibling = $widget;
    }

    public function getRightSibling(): ?Widget
    {
        return $this->rightSibling;
    }

    public function setBottomSibling(?Widget $widget): void
    {
        $this->bottomSibling = $widget;
    }

    public function getBottomSibling(): ?Widget
    {
        return $this->bottomSibling;
    }

    public function setLeftSibling(?Widget $widget): void
    {
        $this->leftSibling = $widget;
    }

    public function getLeftSibling(): ?Widget
    {
        return $this->leftSibling;
    }

    public function setSiblings(
        ?Widget $top = null,
        ?Widget $right = null,
        ?Widget $bottom = null,
        ?Widget $left = null,
    ): void
    {
        $this->topSibling = $top;
        $this->rightSibling = $right;
        $this->bottomSibling = $bottom;
        $this->leftSibling = $left;
    }

    public function getSibling(string $direction): ?Widget
    {
        return match ($direction) {
            'top' => $this->topSibling,
            'right' => $this->rightSibling,
            'bottom' => $this->bottomSibling,
            'left' => $this->leftSibling,
            default => null,
        };
    }

    protected function getContentAreaTop(): int
    {
        return ($this->position['y'] ?? 0) + 1;
    }

    protected function getContentAreaLeft(): int
    {
        return ($this->position['x'] ?? 0) + 1 + $this->padding->leftPadding;
    }

    /**
     * @inheritDoc
     */
    public function focus(FocusTargetContext $context): void
    {
        $this->hasFocus = true;
    }

    /**
     * @inheritDoc
     */
    public function blur(FocusTargetContext $context): void
    {
        $this->hasFocus = false;
    }

    public function renderAt(?int $x = null, ?int $y = null): void
    {
        $position = $this->position;
        $positionX = $position["x"] ?? $position[0];
        $positionY = $position["y"] ?? $position[1];

        $leftMargin = $positionX + ($x ?? 0);
        $topMargin = $positionY + ($y ?? 0);
        $contentColor = $this->foregroundColor;

        $this->foregroundColor = null;
        $linesOfContent = $this->buildRenderedContentLines();
        $this->foregroundColor = $contentColor;
        $topBorder = $this->buildBorderLine($this->title, true);
        $bottomBorder = $this->buildBorderLine($this->help, false);

        if (!$linesOfContent) {
            $linesOfContent = [''];
        }

        $this->cursor->moveTo($leftMargin, $topMargin);
        echo $this->decorateBorderLine($topBorder, $contentColor);

        foreach ($linesOfContent as $index => $line) {
            $this->cursor->moveTo($leftMargin, $topMargin + $index + 1);
            echo $this->decorateContentLine($line, $contentColor, $index);
        }

        $this->cursor->moveTo($leftMargin, $topMargin + count($linesOfContent) + 1);
        echo $this->decorateBorderLine($bottomBorder, $contentColor);
    }

    protected function decorateBorderLine(string $line, ?Color $contentColor): string
    {
        $visibleLine = mb_substr($line, 0, $this->width);
        $borderColor = $this->hasFocus ? $this->focusBorderColor : $contentColor;

        return $this->wrapWithColor($visibleLine, $borderColor);
    }

    protected function decorateContentLine(string $line, ?Color $contentColor, int $lineIndex): string
    {
        $visibleLine = mb_substr($line, 0, $this->width);

        if (!$this->hasFocus) {
            return $this->wrapWithColor($visibleLine, $contentColor);
        }

        $visibleLength = mb_strlen($visibleLine);

        if ($visibleLength <= 1) {
            return $this->wrapWithColor($visibleLine, $this->focusBorderColor);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleLength > 2 ? mb_substr($visibleLine, 1, $visibleLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);

        return $this->wrapWithColor($leftBorder, $this->focusBorderColor)
            . $this->wrapWithColor($middle, $contentColor)
            . $this->wrapWithColor($rightBorder, $this->focusBorderColor);
    }

    protected function buildRenderedContentLines(): array
    {
        $innerWidth = max(1, $this->innerWidth);
        $innerHeight = max(1, $this->innerHeight);
        $scrollbarState = $this->resolveVerticalScrollbarState();
        $textInnerWidth = max(0, $innerWidth - ($scrollbarState !== null ? 1 : 0));
        $lines = [];
        $contentAreaRowIndex = 0;

        for ($row = 0; $row < $this->padding->topPadding && count($lines) < $innerHeight; $row++) {
            $lines[] = $this->buildViewportLine('', $textInnerWidth, $scrollbarState, null);
        }

        $contentLineLimit = max(0, $innerHeight - $this->padding->bottomPadding);
        $contentLines = $this->resolveRenderableContentLines();

        foreach ($contentLines as $lineOfContent) {
            if (count($lines) >= $contentLineLimit) {
                break;
            }

            $lines[] = $this->buildViewportLine((string) $lineOfContent, $textInnerWidth, $scrollbarState, $contentAreaRowIndex);
            $contentAreaRowIndex++;
        }

        while (count($lines) < $contentLineLimit) {
            $lines[] = $this->buildViewportLine('', $textInnerWidth, $scrollbarState, $contentAreaRowIndex);
            $contentAreaRowIndex++;
        }

        while (count($lines) < $innerHeight) {
            $lines[] = $this->buildViewportLine('', $textInnerWidth, $scrollbarState, null);
        }

        return $lines;
    }

    protected function usesAutomaticVerticalScrolling(): bool
    {
        return true;
    }

    protected function getVerticalScrollViewportLineCount(): int
    {
        return max(1, $this->innerHeight - $this->padding->topPadding - $this->padding->bottomPadding);
    }

    protected function ensureContentLineVisible(?int $contentIndex): void
    {
        if (!$this->usesAutomaticVerticalScrolling() || !is_int($contentIndex) || $contentIndex < 0) {
            return;
        }

        $visibleLineCount = $this->getVerticalScrollViewportLineCount();

        if ($visibleLineCount <= 0) {
            $this->verticalScrollOffset = 0;
            return;
        }

        $this->clampVerticalScrollOffset();

        if ($contentIndex < $this->verticalScrollOffset) {
            $this->verticalScrollOffset = $contentIndex;
        } elseif ($contentIndex >= $this->verticalScrollOffset + $visibleLineCount) {
            $this->verticalScrollOffset = $contentIndex - $visibleLineCount + 1;
        }

        $this->clampVerticalScrollOffset();
    }

    protected function getContentIndexForLineIndex(int $lineIndex): ?int
    {
        $contentRowIndex = $lineIndex - $this->padding->topPadding;

        if ($contentRowIndex < 0) {
            return null;
        }

        $contentIndex = $this->usesAutomaticVerticalScrolling()
            ? $this->getClampedVerticalScrollOffset() + $contentRowIndex
            : $contentRowIndex;

        if (!array_key_exists($contentIndex, $this->content)) {
            return null;
        }

        return $contentIndex;
    }

    protected function getRenderedLineIndexForContentIndex(int $contentIndex): ?int
    {
        if ($contentIndex < 0) {
            return null;
        }

        if ($this->usesAutomaticVerticalScrolling()) {
            $scrollOffset = $this->getClampedVerticalScrollOffset();
            $visibleLineCount = $this->getVerticalScrollViewportLineCount();

            if ($contentIndex < $scrollOffset || $contentIndex >= $scrollOffset + $visibleLineCount) {
                return null;
            }

            return $this->padding->topPadding + ($contentIndex - $scrollOffset);
        }

        return array_key_exists($contentIndex, $this->content)
            ? $this->padding->topPadding + $contentIndex
            : null;
    }

    protected function resolveContentIndexFromPointY(int $y): ?int
    {
        return $this->getContentIndexForLineIndex($y - $this->getContentAreaTop());
    }

    protected function setScrollbarOffset(int $offset): void
    {
        $this->verticalScrollOffset = max(0, $offset);
        $this->clampVerticalScrollOffset();
        $this->handleScrollbarOffsetChanged();
    }

    protected function handleScrollbarOffsetChanged(): void
    {
    }

    /**
     * @return array{offset:int, visible:int, total:int, start:int}|null
     */
    protected function resolveVerticalScrollbarState(): ?array
    {
        if (!$this->usesAutomaticVerticalScrolling()) {
            return null;
        }

        $visibleLineCount = $this->getVerticalScrollViewportLineCount();
        $totalLineCount = count($this->content);

        if ($visibleLineCount <= 0 || $totalLineCount <= $visibleLineCount) {
            return null;
        }

        return [
            'offset' => $this->getClampedVerticalScrollOffset(),
            'visible' => $visibleLineCount,
            'total' => $totalLineCount,
            'start' => 0,
        ];
    }

    protected function buildContentLine(string $content, int $innerWidth): string
    {
        $leftPadding = max(0, $this->padding->leftPadding);
        $rightPadding = max(0, $this->padding->rightPadding);
        $availableTextWidth = max(0, $innerWidth - $leftPadding - $rightPadding);
        $visibleContent = $this->clipContentToWidth($content, $availableTextWidth);
        $visibleLength = $this->getDisplayWidth($visibleContent);

        $contentArea = match ($this->alignment->horizontalAlignment) {
            HorizontalAlignment::CENTER => $this->buildCenteredContentArea(
                $visibleContent,
                $visibleLength,
                $innerWidth,
                $leftPadding,
                $rightPadding,
            ),
            HorizontalAlignment::RIGHT => $this->buildRightAlignedContentArea(
                $visibleContent,
                $visibleLength,
                $innerWidth,
                $rightPadding,
            ),
            default => $this->buildLeftAlignedContentArea(
                $visibleContent,
                $visibleLength,
                $innerWidth,
                $leftPadding,
            ),
        };

        return $this->borderPack->vertical . $contentArea . $this->borderPack->vertical;
    }

    protected function buildLeftAlignedContentArea(
        string $visibleContent,
        int $visibleLength,
        int $innerWidth,
        int $leftPadding,
    ): string
    {
        $contentArea = str_repeat(' ', min($leftPadding, $innerWidth)) . $visibleContent;

        return $this->padContentArea($contentArea, $innerWidth, STR_PAD_RIGHT);
    }

    protected function buildCenteredContentArea(
        string $visibleContent,
        int $visibleLength,
        int $innerWidth,
        int $leftPadding,
        int $rightPadding,
    ): string
    {
        $availableWidth = max(0, $innerWidth - $leftPadding - $rightPadding);
        $remainingWidth = max(0, $availableWidth - $visibleLength);
        $leftExtraPadding = intdiv($remainingWidth, 2);
        $rightExtraPadding = $remainingWidth - $leftExtraPadding;
        $contentArea = str_repeat(' ', min($leftPadding + $leftExtraPadding, $innerWidth))
            . $visibleContent
            . str_repeat(' ', $rightPadding + $rightExtraPadding);

        return $this->padContentArea($contentArea, $innerWidth, STR_PAD_BOTH);
    }

    protected function buildRightAlignedContentArea(
        string $visibleContent,
        int $visibleLength,
        int $innerWidth,
        int $rightPadding,
    ): string
    {
        $leftSpace = max(0, $innerWidth - $rightPadding - $visibleLength);
        $contentArea = str_repeat(' ', $leftSpace) . $visibleContent;

        return $this->padContentArea($contentArea, $innerWidth, STR_PAD_RIGHT);
    }

    protected function padContentArea(string $contentArea, int $innerWidth, int $direction): string
    {
        $visibleArea = $this->clipContentToWidth($contentArea, $innerWidth);
        $visibleLength = $this->getDisplayWidth($visibleArea);

        if ($visibleLength >= $innerWidth) {
            return $visibleArea;
        }

        $paddingWidth = $innerWidth - $visibleLength;

        return match ($direction) {
            STR_PAD_LEFT => str_repeat(' ', $paddingWidth) . $visibleArea,
            STR_PAD_BOTH => str_repeat(' ', intdiv($paddingWidth, 2))
                . $visibleArea
                . str_repeat(' ', $paddingWidth - intdiv($paddingWidth, 2)),
            default => $visibleArea . str_repeat(' ', $paddingWidth),
        };
    }

    protected function clipContentToWidth(string $content, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return '';
        }

        if ($this->getDisplayWidth($content) <= $maxWidth) {
            return $content;
        }

        return mb_strimwidth($content, 0, $maxWidth, '', 'UTF-8');
    }

    /**
     * @return array{before: string, highlight: string, after: string}
     */
    protected function splitContentByDisplayWidth(string $content, int $highlightStart, int $highlightLength): array
    {
        $highlightStart = max(0, $highlightStart);
        $highlightLength = max(0, $highlightLength);

        if ($content === '' || $highlightLength === 0) {
            return [
                'before' => $content,
                'highlight' => '',
                'after' => '',
            ];
        }

        $characters = preg_split('//u', $content, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($characters) || $characters === []) {
            return [
                'before' => $content,
                'highlight' => '',
                'after' => '',
            ];
        }

        $before = '';
        $highlight = '';
        $after = '';
        $currentWidth = 0;
        $highlightEnd = $highlightStart + $highlightLength;

        foreach ($characters as $character) {
            $characterWidth = max(1, $this->getDisplayWidth($character));
            $characterStart = $currentWidth;
            $characterEnd = $currentWidth + $characterWidth;

            if ($characterEnd <= $highlightStart) {
                $before .= $character;
            } elseif ($characterStart >= $highlightEnd) {
                $after .= $character;
            } else {
                $highlight .= $character;
            }

            $currentWidth = $characterEnd;
        }

        return [
            'before' => $before,
            'highlight' => $highlight,
            'after' => $after,
        ];
    }

    protected function buildBorderLine(string $label, bool $isTopBorder): string
    {
        $availableLabelWidth = max(0, $this->width - 3);
        $visibleLabel = $this->clipContentToWidth($label, $availableLabelWidth);
        $labelWidth = $this->getDisplayWidth($visibleLabel);
        $remainderWidth = max(0, $this->width - $labelWidth - 3);

        $leftCorner = $isTopBorder ? $this->borderPack->topLeft : $this->borderPack->bottomLeft;
        $rightCorner = $isTopBorder ? $this->borderPack->topRight : $this->borderPack->bottomRight;

        return $leftCorner
            . $this->borderPack->horizontal
            . $visibleLabel
            . str_repeat($this->borderPack->horizontal, $remainderWidth)
            . $rightCorner;
    }

    protected function getDisplayWidth(string $content): int
    {
        if ($content === '') {
            return 0;
        }

        return function_exists('mb_strwidth')
            ? mb_strwidth($content, 'UTF-8')
            : mb_strlen($content);
    }

    protected function wrapWithColor(string $content, ?Color $color): string
    {
        return $this->wrapWithSequence($content, $color?->value);
    }

    protected function wrapWithSequence(string $content, ?string $sequence): string
    {
        if ($content === '' || $sequence === null) {
            return $content;
        }

        return $sequence . $content . Color::RESET->value;
    }

    private function resolveRenderableContentLines(): array
    {
        if (!$this->usesAutomaticVerticalScrolling()) {
            return $this->content;
        }

        $this->clampVerticalScrollOffset();

        return array_slice(
            $this->content,
            $this->verticalScrollOffset,
            $this->getVerticalScrollViewportLineCount(),
        );
    }

    private function clampVerticalScrollOffset(): void
    {
        $this->verticalScrollOffset = $this->getClampedVerticalScrollOffset();
    }

    private function getScrollbarColumnX(): int
    {
        return ($this->position['x'] ?? 0) + $this->innerWidth;
    }

    private function applyScrollbarPointerPosition(int $y): void
    {
        $scrollbarState = $this->resolveVerticalScrollbarState();

        if ($scrollbarState === null) {
            $this->isScrollbarDragging = false;
            return;
        }

        $visibleLineIndex = $y - $this->getContentAreaTop();
        $contentAreaRowIndex = $visibleLineIndex - $this->padding->topPadding;
        $scrollbarStart = max(0, (int) ($scrollbarState['start'] ?? 0));
        $visibleLineCount = max(0, (int) ($scrollbarState['visible'] ?? 0));
        $totalLineCount = max(0, (int) ($scrollbarState['total'] ?? 0));

        if ($visibleLineCount <= 0 || $totalLineCount <= 0) {
            return;
        }

        $relativeRow = max(0, min($visibleLineCount - 1, $contentAreaRowIndex - $scrollbarStart));
        $maxScrollOffset = max(0, $totalLineCount - $visibleLineCount);

        if ($maxScrollOffset === 0) {
            $this->setScrollbarOffset(0);
            return;
        }

        $ratio = $visibleLineCount <= 1 ? 0.0 : ($relativeRow / ($visibleLineCount - 1));
        $this->setScrollbarOffset((int) round($ratio * $maxScrollOffset));
    }

    private function getClampedVerticalScrollOffset(): int
    {
        if (!$this->usesAutomaticVerticalScrolling()) {
            return 0;
        }

        $maxScrollOffset = max(0, count($this->content) - $this->getVerticalScrollViewportLineCount());

        return max(0, min($this->verticalScrollOffset, $maxScrollOffset));
    }

    /**
     * @param array{offset:int, visible:int, total:int, start:int}|null $scrollbarState
     */
    private function buildViewportLine(
        string $content,
        int $textInnerWidth,
        ?array $scrollbarState,
        ?int $contentAreaRowIndex,
    ): string
    {
        $line = $this->buildContentLine($content, $textInnerWidth);

        if ($scrollbarState === null) {
            return $line;
        }

        $visibleLength = mb_strlen($line);

        if ($visibleLength === 0) {
            return $line;
        }

        $lineWithoutRightBorder = mb_substr($line, 0, $visibleLength - 1);
        $rightBorder = mb_substr($line, -1);

        return $lineWithoutRightBorder
            . $this->resolveScrollbarCharacter($scrollbarState, $contentAreaRowIndex)
            . $rightBorder;
    }

    /**
     * @param array{offset:int, visible:int, total:int, start:int} $scrollbarState
     */
    private function resolveScrollbarCharacter(array $scrollbarState, ?int $contentAreaRowIndex): string
    {
        if (!is_int($contentAreaRowIndex)) {
            return ' ';
        }

        $scrollbarStart = max(0, (int) ($scrollbarState['start'] ?? 0));
        $visibleLineCount = max(0, (int) ($scrollbarState['visible'] ?? 0));
        $totalLineCount = max(0, (int) ($scrollbarState['total'] ?? 0));

        if (
            $visibleLineCount === 0
            || $totalLineCount === 0
            || $contentAreaRowIndex < $scrollbarStart
            || $contentAreaRowIndex >= ($scrollbarStart + $visibleLineCount)
        ) {
            return ' ';
        }

        $relativeRow = $contentAreaRowIndex - $scrollbarStart;
        $scrollOffset = max(0, (int) ($scrollbarState['offset'] ?? 0));
        $thumbHeight = max(1, (int) ceil(($visibleLineCount * $visibleLineCount) / max(1, $totalLineCount)));
        $maxThumbStart = max(0, $visibleLineCount - $thumbHeight);
        $maxScrollOffset = max(0, $totalLineCount - $visibleLineCount);
        $thumbStart = $maxScrollOffset === 0
            ? 0
            : (int) round(($scrollOffset / $maxScrollOffset) * $maxThumbStart);

        return ($relativeRow >= $thumbStart && $relativeRow < ($thumbStart + $thumbHeight))
            ? self::SCROLLBAR_THUMB_CHARACTER
            : self::SCROLLBAR_TRACK_CHARACTER;
    }

    /**
     * @return void
     */
    public abstract function update(): void;
}

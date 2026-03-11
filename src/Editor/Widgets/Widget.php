<?php

namespace Sendama\Console\Editor\Widgets;

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
        $blankLine = $this->borderPack->vertical . str_repeat(' ', $innerWidth) . $this->borderPack->vertical;
        $lines = [];

        for ($row = 0; $row < $this->padding->topPadding && count($lines) < $innerHeight; $row++) {
            $lines[] = $blankLine;
        }

        $contentLineLimit = max(0, $innerHeight - $this->padding->bottomPadding);

        foreach ($this->content as $lineOfContent) {
            if (count($lines) >= $contentLineLimit) {
                break;
            }

            $lines[] = $this->buildContentLine((string) $lineOfContent, $innerWidth);
        }

        while (count($lines) < $contentLineLimit) {
            $lines[] = $blankLine;
        }

        while (count($lines) < $innerHeight) {
            $lines[] = $blankLine;
        }

        return $lines;
    }

    protected function buildContentLine(string $content, int $innerWidth): string
    {
        $leftPadding = max(0, $this->padding->leftPadding);
        $rightPadding = max(0, $this->padding->rightPadding);
        $availableTextWidth = max(0, $innerWidth - $leftPadding - $rightPadding);
        $visibleContent = $this->clipContentToWidth($content, $availableTextWidth);
        $visibleLength = mb_strlen($visibleContent);

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
        $visibleLength = mb_strlen($visibleArea);

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

        if (mb_strlen($content) <= $maxWidth) {
            return $content;
        }

        return mb_substr($content, 0, $maxWidth);
    }

    protected function buildBorderLine(string $label, bool $isTopBorder): string
    {
        $availableLabelWidth = max(0, $this->width - 3);
        $visibleLabel = mb_substr($label, 0, $availableLabelWidth);
        $labelWidth = mb_strlen($visibleLabel);
        $remainderWidth = max(0, $this->width - $labelWidth - 3);

        $leftCorner = $isTopBorder ? $this->borderPack->topLeft : $this->borderPack->bottomLeft;
        $rightCorner = $isTopBorder ? $this->borderPack->topRight : $this->borderPack->bottomRight;

        return $leftCorner
            . $this->borderPack->horizontal
            . $visibleLabel
            . str_repeat($this->borderPack->horizontal, $remainderWidth)
            . $rightCorner;
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

    /**
     * @return void
     */
    public abstract function update(): void;
}

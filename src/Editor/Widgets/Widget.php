<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\IO\Enumerations\Color;
use Atatusoft\Termutil\UI\Windows\Window;
use Sendama\Console\Editor\FocusTargetContext;
use Sendama\Console\Editor\Interfaces\FocusableInterface;

/**
 *
 */
abstract class Widget extends Window implements FocusableInterface
{
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
        $topBorder = $this->topBorder;
        $linesOfContent = $this->linesOfContent;
        $bottomBorder = $this->bottomBorder;
        $this->foregroundColor = $contentColor;

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

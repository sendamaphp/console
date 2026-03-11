<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\IO\Enumerations\Color;
use Sendama\Console\Editor\IO\Enumerations\KeyCode;
use Sendama\Console\Editor\IO\Input;

class ConsolePanel extends Widget
{
    private const int INITIAL_TAIL_LINE_COUNT = 3;

    protected array $messages = [];
    protected int $scrollOffset = 0;
    protected bool $isPlayModeActive = false;

    public function __construct(
        array $position = ['x' => 37, 'y' => 22],
        int $width = 96,
        int $height = 8,
        protected ?string $logFilePath = null
    )
    {
        parent::__construct('Console', '', $position, $width, $height);
        $this->loadInitialLogTail();
        $this->update();
    }

    public function append(string $message): void
    {
        $this->messages[] = $message;
        $this->scrollToRecentLines();
        $this->refreshVisibleContent();
    }

    public function clear(): void
    {
        $this->messages = [];
        $this->scrollOffset = 0;
        $this->refreshVisibleContent();
    }

    public function setPlayModeActive(bool $isPlayModeActive): void
    {
        $this->isPlayModeActive = $isPlayModeActive;
    }

    public function scrollUp(): void
    {
        if ($this->messages === []) {
            return;
        }

        $this->scrollOffset = max(0, $this->scrollOffset - 1);
        $this->refreshVisibleContent();
    }

    public function scrollDown(): void
    {
        if ($this->messages === []) {
            return;
        }

        $this->scrollOffset = min(count($this->messages) - 1, $this->scrollOffset + 1);
        $this->refreshVisibleContent();
    }

    public function update(): void
    {
        if ($this->hasFocus() && !$this->isPlayModeActive) {
            if (Input::isKeyDown(KeyCode::UP)) {
                $this->scrollUp();
                return;
            }

            if (Input::isKeyDown(KeyCode::DOWN)) {
                $this->scrollDown();
                return;
            }
        }

        $this->refreshVisibleContent();
    }

    protected function decorateContentLine(string $line, ?Color $contentColor, int $lineIndex): string
    {
        $visibleLine = mb_substr($line, 0, $this->width);
        $visibleLength = mb_strlen($visibleLine);

        if ($visibleLength <= 1) {
            return parent::decorateContentLine($line, $contentColor, $lineIndex);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleLength > 2 ? mb_substr($visibleLine, 1, $visibleLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);
        $borderColor = $this->hasFocus() ? $this->focusBorderColor : $contentColor;

        return $this->wrapWithColor($leftBorder, $borderColor)
            . $this->colorizeLogTag($middle)
            . $this->wrapWithColor($rightBorder, $borderColor);
    }

    private function loadInitialLogTail(): void
    {
        if ($this->logFilePath === null || !is_file($this->logFilePath)) {
            return;
        }

        $lines = file($this->logFilePath, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return;
        }

        $this->messages = $lines;
        $this->scrollToRecentLines();
    }

    private function colorizeLogTag(string $content): string
    {
        if (preg_match('/\[(ERROR|INFO|WARN|WARNING|DEBUG)\]/', $content, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $content;
        }

        $tag = $matches[0][0];
        $tagOffset = $matches[0][1];
        $level = $matches[1][0];
        $beforeTag = substr($content, 0, $tagOffset);
        $afterTag = substr($content, $tagOffset + strlen($tag));

        return $beforeTag
            . $this->wrapWithColor($tag, $this->resolveLogLevelColor($level))
            . $afterTag;
    }

    private function resolveLogLevelColor(string $level): ?Color
    {
        return match ($level) {
            'ERROR' => Color::LIGHT_RED,
            'INFO' => Color::LIGHT_BLUE,
            'WARN', 'WARNING' => Color::YELLOW,
            'DEBUG' => Color::LIGHT_GRAY,
            default => null,
        };
    }

    private function scrollToRecentLines(): void
    {
        $messageCount = count($this->messages);

        if ($messageCount === 0) {
            $this->scrollOffset = 0;
            return;
        }

        $this->scrollOffset = max(0, $messageCount - self::INITIAL_TAIL_LINE_COUNT);
    }

    private function clampScrollOffset(): void
    {
        if ($this->messages === []) {
            $this->scrollOffset = 0;
            return;
        }

        $this->scrollOffset = max(0, min($this->scrollOffset, count($this->messages) - 1));
    }

    private function refreshVisibleContent(): void
    {
        $this->clampScrollOffset();
        $this->content = array_slice($this->messages, $this->scrollOffset, $this->innerHeight);
    }
}

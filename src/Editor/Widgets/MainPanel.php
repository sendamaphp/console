<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\IO\Enumerations\Color;

class MainPanel extends Widget
{
    private const string DIVIDER_LINE_CHARACTER = '─';
    private const string TAB_DIVIDER_LINE_CHARACTER = '■';
    private const array TAB_TITLES = ['Scene', 'Game', 'Sprite'];
    private const string GAME_IDLE_PATTERN_CHARACTER = '/';
    private const string GAME_IDLE_PROMPT = 'Shift+5 to Play';
    private const Color DEFAULT_FOCUS_COLOR = Color::LIGHT_CYAN;
    private const Color PLAY_MODE_FOCUS_COLOR = Color::BROWN;

    protected int $activeTabIndex = 0;
    protected int $activeTabOffset = 0;
    protected int $activeTabLength = 0;
    protected Color $activeIndicatorColor = Color::LIGHT_CYAN;
    protected bool $isPlayModeActive = false;
    protected array $gameIdleContentIndexes = [];
    protected ?int $gameIdlePromptContentIndex = null;

    public function __construct(
        array $position = ['x' => 37, 'y' => 1],
        int $width = 96,
        int $height = 21
    )
    {
        parent::__construct('', '', $position, $width, $height);
        $this->focusBorderColor = self::DEFAULT_FOCUS_COLOR;

        $this->refreshContent();
    }

    public function getActiveTab(): string
    {
        return self::TAB_TITLES[$this->activeTabIndex];
    }

    public function activateNextTab(): void
    {
        $this->activeTabIndex = ($this->activeTabIndex + 1) % count(self::TAB_TITLES);
        $this->refreshContent();
    }

    public function activatePreviousTab(): void
    {
        $this->activeTabIndex = ($this->activeTabIndex - 1 + count(self::TAB_TITLES)) % count(self::TAB_TITLES);
        $this->refreshContent();
    }

    public function selectTab(string $tabTitle): void
    {
        $tabIndex = array_search($tabTitle, self::TAB_TITLES, true);

        if ($tabIndex === false) {
            return;
        }

        $this->activeTabIndex = $tabIndex;
        $this->refreshContent();
    }

    public function cycleFocusForward(): bool
    {
        $this->activateNextTab();

        return true;
    }

    public function cycleFocusBackward(): bool
    {
        $this->activatePreviousTab();

        return true;
    }

    public function setPlayModeActive(bool $isPlayModeActive): void
    {
        if ($this->isPlayModeActive === $isPlayModeActive) {
            return;
        }

        $this->isPlayModeActive = $isPlayModeActive;
        $this->focusBorderColor = $isPlayModeActive
            ? self::PLAY_MODE_FOCUS_COLOR
            : self::DEFAULT_FOCUS_COLOR;
        $this->refreshContent();
    }

    public function update(): void
    {
        $this->refreshContent();
    }

    public function handleMouseClick(int $x, int $y): void
    {
        if (!$this->containsPoint($x, $y) || $y !== $this->getContentAreaTop()) {
            return;
        }

        $currentX = $this->getContentAreaLeft();

        foreach (self::TAB_TITLES as $index => $tabTitle) {
            if ($index > 0) {
                $currentX += 2;
            }

            $tabStart = $currentX;
            $tabEnd = $tabStart + mb_strlen($tabTitle) - 1;

            if ($x >= $tabStart && $x <= $tabEnd) {
                $this->activeTabIndex = $index;
                $this->refreshContent();
                return;
            }

            $currentX = $tabEnd + 1;
        }
    }

    protected function decorateContentLine(string $line, ?Color $contentColor, int $lineIndex): string
    {
        $contentIndex = $lineIndex - $this->padding->topPadding;

        if ($lineIndex !== 1) {
            if (!in_array($contentIndex, $this->gameIdleContentIndexes, true)) {
                return parent::decorateContentLine($line, $contentColor, $lineIndex);
            }

            return $this->decorateGameIdleLine($line, $contentColor, $contentIndex);
        }

        $visibleLine = mb_substr($line, 0, $this->width);
        $visibleLength = mb_strlen($visibleLine);

        if ($visibleLength <= 1) {
            return parent::decorateContentLine($line, $contentColor, $lineIndex);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleLength > 2 ? mb_substr($visibleLine, 1, $visibleLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);
        $borderColor = $this->hasFocus() ? $this->focusBorderColor : $contentColor;
        $indicatorStart = $this->padding->leftPadding + $this->activeTabOffset;
        $indicatorLength = $this->activeTabLength;
        $beforeIndicator = mb_substr($middle, 0, $indicatorStart);
        $indicator = mb_substr($middle, $indicatorStart, $indicatorLength);
        $afterIndicator = mb_substr($middle, $indicatorStart + $indicatorLength);

        return $this->wrapWithColor($leftBorder, $borderColor)
            . $this->wrapWithColor($beforeIndicator, $contentColor)
            . $this->wrapWithColor($indicator, $this->activeIndicatorColor)
            . $this->wrapWithColor($afterIndicator, $contentColor)
            . $this->wrapWithColor($rightBorder, $borderColor);
    }

    private function decorateGameIdleLine(string $line, ?Color $contentColor, int $contentIndex): string
    {
        $visibleLine = mb_substr($line, 0, $this->width);
        $visibleLength = mb_strlen($visibleLine);

        if ($visibleLength <= 1) {
            return parent::decorateContentLine($line, $contentColor, $contentIndex);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleLength > 2 ? mb_substr($visibleLine, 1, $visibleLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);
        $borderColor = $this->hasFocus() ? $this->focusBorderColor : $contentColor;
        $decoratedMiddle = $this->colorizeGameIdleMiddle($middle, $contentIndex === $this->gameIdlePromptContentIndex);

        return $this->wrapWithColor($leftBorder, $borderColor)
            . $decoratedMiddle
            . $this->wrapWithColor($rightBorder, $borderColor);
    }

    private function colorizeGameIdleMiddle(string $middle, bool $isPromptLine): string
    {
        $output = '';
        $promptLength = mb_strlen(self::GAME_IDLE_PROMPT);
        $promptStart = $isPromptLine
            ? max(0, intdiv(mb_strlen($middle) - $promptLength, 2))
            : -1;
        $promptEnd = $promptStart >= 0 ? $promptStart + $promptLength : -1;

        for ($index = 0; $index < mb_strlen($middle); $index++) {
            $character = mb_substr($middle, $index, 1);

            if ($isPromptLine && $index >= $promptStart && $index < $promptEnd) {
                $output .= $this->wrapWithColor($character, Color::LIGHT_GRAY);
                continue;
            }

            if ($character === self::GAME_IDLE_PATTERN_CHARACTER) {
                $output .= $this->wrapWithColor($character, Color::BLUE);
                continue;
            }

            $output .= $character;
        }

        return $output;
    }

    private function refreshContent(): void
    {
        $tabsLine = '';
        $this->activeTabOffset = 0;
        $this->gameIdleContentIndexes = [];
        $this->gameIdlePromptContentIndex = null;

        foreach (self::TAB_TITLES as $index => $tabTitle) {
            if ($index > 0) {
                $tabsLine .= '  ';
            }

            if ($index === $this->activeTabIndex) {
                $this->activeTabOffset = mb_strlen($tabsLine);
            }

            $tabsLine .= $tabTitle;
        }

        $dividerWidth = max(0, $this->innerWidth - 2);
        $activeTabTitle = self::TAB_TITLES[$this->activeTabIndex];
        $this->activeTabLength = mb_strlen($activeTabTitle);
        $dividerLine = $this->buildDividerLine($dividerWidth);
        $content = [$tabsLine, $dividerLine];

        if ($this->shouldRenderIdleGameView()) {
            $contentWidth = max(0, $this->innerWidth - $this->padding->leftPadding - $this->padding->rightPadding);
            $idleRows = max(0, $this->innerHeight - count($content));
            $promptRow = $idleRows > 0 ? intdiv($idleRows, 2) : null;

            for ($row = 0; $row < $idleRows; $row++) {
                $content[] = $this->buildGameIdleLine(
                    $contentWidth,
                    $row,
                    $promptRow !== null && $row === $promptRow,
                );
                $contentIndex = count($content) - 1;
                $this->gameIdleContentIndexes[] = $contentIndex;

                if ($promptRow !== null && $row === $promptRow) {
                    $this->gameIdlePromptContentIndex = $contentIndex;
                }
            }
        }

        $this->content = $content;
    }

    private function shouldRenderIdleGameView(): bool
    {
        return $this->getActiveTab() === 'Game' && !$this->isPlayModeActive;
    }

    private function buildGameIdleLine(int $width, int $row, bool $includePrompt): string
    {
        if ($width <= 0) {
            return '';
        }

        $characters = array_fill(0, $width, ' ');

        for ($column = 0; $column < $width; $column++) {
            if ((($column + ($row * 2)) % 3) === 0) {
                $characters[$column] = self::GAME_IDLE_PATTERN_CHARACTER;
            }
        }

        if (!$includePrompt) {
            return implode('', $characters);
        }

        $promptLength = mb_strlen(self::GAME_IDLE_PROMPT);
        $promptStart = max(0, intdiv($width - $promptLength, 2));
        $clearStart = max(0, $promptStart - 2);
        $clearEnd = min($width, $promptStart + $promptLength + 2);

        for ($index = $clearStart; $index < $clearEnd; $index++) {
            $characters[$index] = ' ';
        }

        for ($index = 0; $index < $promptLength && ($promptStart + $index) < $width; $index++) {
            $characters[$promptStart + $index] = mb_substr(self::GAME_IDLE_PROMPT, $index, 1);
        }

        return implode('', $characters);
    }

    private function buildDividerLine(int $dividerWidth): string
    {
        if ($dividerWidth <= 0) {
            return '';
        }

        $characters = array_fill(0, $dividerWidth, self::DIVIDER_LINE_CHARACTER);

        for ($index = 0; $index < $this->activeTabLength; $index++) {
            $characterIndex = $this->activeTabOffset + $index;

            if (!isset($characters[$characterIndex])) {
                break;
            }

            $characters[$characterIndex] = self::TAB_DIVIDER_LINE_CHARACTER;
        }

        return implode('', $characters);
    }
}

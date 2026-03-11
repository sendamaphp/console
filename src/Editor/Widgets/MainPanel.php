<?php

namespace Sendama\Console\Editor\Widgets;

use Sendama\Console\Editor\IO\Enumerations\KeyCode;
use Sendama\Console\Editor\IO\Input;

class MainPanel extends Widget
{
    private const array TAB_TITLES = ['Scene', 'Game', 'Sprite'];

    protected int $activeTabIndex = 0;

    public function __construct(
        array $position = ['x' => 37, 'y' => 1],
        int $width = 96,
        int $height = 21
    )
    {
        parent::__construct('', '', $position, $width, $height);

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

    public function update(): void
    {
        if ($this->hasFocus()) {
            if (Input::isKeyDown(KeyCode::RIGHT)) {
                $this->activateNextTab();
                return;
            }

            if (Input::isKeyDown(KeyCode::LEFT)) {
                $this->activatePreviousTab();
                return;
            }
        }

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
            $tabEnd = $tabStart + strlen($tabTitle) - 1;

            if ($x >= $tabStart && $x <= $tabEnd) {
                $this->activeTabIndex = $index;
                $this->refreshContent();
                return;
            }

            $currentX = $tabEnd + 1;
        }
    }

    private function refreshContent(): void
    {
        $tabsLine = '';
        $activeTabOffset = 0;

        foreach (self::TAB_TITLES as $index => $tabTitle) {
            if ($index > 0) {
                $tabsLine .= '  ';
            }

            if ($index === $this->activeTabIndex) {
                $activeTabOffset = strlen($tabsLine);
            }

            $tabsLine .= $tabTitle;
        }

        $dividerWidth = max(0, $this->innerWidth - 2);
        $dividerLine = str_repeat('-', $dividerWidth);
        $activeTabTitle = self::TAB_TITLES[$this->activeTabIndex];

        if ($dividerWidth > 0) {
            $dividerLine = substr_replace(
                $dividerLine,
                str_repeat('=', strlen($activeTabTitle)),
                $activeTabOffset,
                strlen($activeTabTitle)
            );
        }

        $this->content = [$tabsLine, $dividerLine];
    }
}

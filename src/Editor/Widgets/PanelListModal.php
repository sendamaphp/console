<?php

namespace Sendama\Console\Editor\Widgets;

class PanelListModal extends Widget
{
    protected bool $isVisible = false;
    protected bool $isDirty = false;
    protected array $panelNames = [];
    protected int $selectedIndex = 0;

    public function __construct()
    {
        parent::__construct(
            title: 'Panels',
            help: 'Enter Select Esc Close',
            position: ['x' => 1, 'y' => 1],
            width: 28,
            height: 7,
        );
    }

    public function show(array $panelNames, int $selectedIndex = 0): void
    {
        $this->panelNames = array_values($panelNames);
        $panelCount = count($this->panelNames);
        $this->selectedIndex = $panelCount > 0
            ? max(0, min($selectedIndex, $panelCount - 1))
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

    public function getSelectedIndex(): int
    {
        return $this->selectedIndex;
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
        $panelCount = count($this->panelNames);

        if ($panelCount === 0) {
            return;
        }

        $this->selectedIndex = ($this->selectedIndex + $offset + $panelCount) % $panelCount;
        $this->refreshContent();
        $this->markDirty();
    }

    public function syncLayout(int $terminalWidth, int $terminalHeight): void
    {
        $longestNameLength = 0;

        foreach ($this->panelNames as $panelName) {
            $longestNameLength = max($longestNameLength, strlen($panelName));
        }

        $desiredWidth = max(
            strlen($this->title) + 4,
            strlen($this->help) + 4,
            $longestNameLength + 6
        );
        $modalWidth = min(max(18, $desiredWidth), max(18, $terminalWidth - 2));
        $modalHeight = min(max(4, count($this->panelNames) + 2), max(4, $terminalHeight - 2));
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

    private function refreshContent(): void
    {
        $this->content = array_map(function (string $panelName, int $index) {
            $icon = $index === $this->selectedIndex ? '>' : ' ';
            return "$icon $panelName";
        }, $this->panelNames, array_keys($this->panelNames));
    }

    private function markDirty(): void
    {
        $this->isDirty = true;
    }
}

<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\Events\Interfaces\ObservableInterface;
use Atatusoft\Termutil\Events\Traits\ObservableTrait;
use Sendama\Console\Editor\Events\EditorEvent;
use Sendama\Console\Editor\Events\Enumerations\EventType;

/**
 * HierarchyPanel class.
 *
 * @package
 */
class HierarchyPanel extends Widget implements ObservableInterface
{
    use ObservableTrait;

    protected array $hierarchy = [];
    protected ?int $selectedIndex = null;

    public function __construct(
        array $position = ['x' => 1, 'y' => 1],
        int $width = 35,
        int $height = 14,
        array $hierarchy = []
    )
    {
        $this->initializeObservers();
        parent::__construct('Hierarchy', '', $position, $width, $height);
        $this->setHierarchy($hierarchy);
    }

    public function getHierarchy(): array
    {
        return $this->hierarchy;
    }

    public function setHierarchy(array $hierarchy): void
    {
        $this->hierarchy = $hierarchy;
        $this->refreshContent();

        $this->notify(new EditorEvent(EventType::HIERARCHY_CHANGED->value, $this));
    }

    public function getSelectedHierarchyObject(): ?array
    {
        if ($this->selectedIndex === null) {
            return null;
        }

        return $this->hierarchy[$this->selectedIndex] ?? null;
    }

    public function handleMouseClick(int $x, int $y): void
    {
        if (!$this->containsPoint($x, $y)) {
            return;
        }

        $index = $y - $this->getContentAreaTop();

        if (!isset($this->hierarchy[$index])) {
            return;
        }

        $this->selectedIndex = $index;
        $this->refreshContent();
    }

    /**
     * @inheritDoc
     */
    public function update(): void
    {
        // TODO: Implement update() method.
    }

    private function refreshContent(): void
    {
        $this->content = array_map(function (array $item, int $index) {
            $objectName = $item['name'] ?? 'Unnamed Object';
            $icon = $index === $this->selectedIndex ? '>' : '►';
            return "$icon $objectName";
        }, $this->hierarchy, array_keys($this->hierarchy));
    }
}

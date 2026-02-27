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

    public array $hierarchy {
        get {
            return $this->hierarchy;
        }

        set {
            $this->hierarchy = $value;
            $this->notify(new EditorEvent(EventType::HIERARCHY_CHANGED->value, $this, ));
        }
    }

    public function __construct(
        array $position = ['x' => 1, 'y' => 1],
        int $width = 35,
        int $height = 14,
        array $hierarchy = [
            [
                "name" => "Level Manager"
            ],
            [
                "name" => "Game Object"
            ]
        ]
    )
    {
        $this->initializeObservers();
        $this->hierarchy = $hierarchy;
        parent::__construct('Hierarchy', '', $position, $width, $height);

        // Bind hierarchy to content for display
        $this->content = array_map(function (array $item) {
            $objectName = $item['name'] ?? 'Unnamed Object';
            $icon = "►"; // TODO: Determine icon based on object type
            // TODO: Add indentation based on hierarchy level
            return "$icon $objectName";
        }, $this->hierarchy);
    }

    /**
     * @inheritDoc
     */
    public function update(): void
    {
        // TODO: Implement update() method.
    }
}
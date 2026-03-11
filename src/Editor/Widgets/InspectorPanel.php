<?php

namespace Sendama\Console\Editor\Widgets;

use Sendama\Console\Editor\Widgets\Widget;

class InspectorPanel extends Widget
{
    protected ?array $inspectionTarget = null;

    public function __construct(
        array $position = ['x' => 135, 'y' => 1],
        int $width = 35,
        int $height = 29
    )
    {
        parent::__construct('Inspector', '', $position, $width, $height);
    }

    public function inspectTarget(?array $target): void
    {
        $this->inspectionTarget = $target;
        $selectedItemType = $target['type'] ?? null;
        $selectedItemName = $target['name'] ?? null;
        $content = [];

        if ($selectedItemType !== null) {
            $content[] = "Type: {$selectedItemType}";
        }

        if ($selectedItemName !== null) {
            $content[] = "Name: {$selectedItemName}";
        }

        $this->content = $content;
    }

    /**
     * @inheritDoc
     */
    public function update(): void
    {
        // TODO: Implement update() method.
    }
}

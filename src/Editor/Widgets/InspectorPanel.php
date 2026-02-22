<?php

namespace Sendama\Console\Editor\Widgets;

use Sendama\Console\Editor\Widgets\Widget;

class InspectorPanel extends Widget
{
    public function __construct(
        array $position = ['x' => 135, 'y' => 1],
        int $width = 35,
        int $height = 29
    )
    {
        parent::__construct('Inspector', '', $position, $width, $height);
    }

    /**
     * @inheritDoc
     */
    public function update(): void
    {
        // TODO: Implement update() method.
    }
}
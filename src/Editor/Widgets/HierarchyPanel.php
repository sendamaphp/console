<?php

namespace Sendama\Console\Editor\Widgets;


use Sendama\Console\Debug\Debug;

class HierarchyPanel extends Widget
{
    public function __construct(
        array $position = ['x' => 1, 'y' => 1],
        int $width = 35,
        int $height = 14
    )
    {
        parent::__construct('Hierarchy', '', $position, $width, $height);
    }

    /**
     * @inheritDoc
     */
    public function update(): void
    {
        // TODO: Implement update() method.
    }
}
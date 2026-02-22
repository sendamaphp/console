<?php

namespace Sendama\Console\Editor\Widgets;

class AssetsPanel extends Widget
{
    public function __construct(
        array $position = ['x' => 1, 'y' => 15],
        int $width = 35,
        int $height = 14
    )
    {
        parent::__construct('Assets', '', $position, $width, $height);
    }

    /**
     * @inheritDoc
     */
    public function update(): void
    {
        // TODO: Implement update() method.
    }
}
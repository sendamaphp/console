<?php

namespace Sendama\Console\Editor\IO;

use Sendama\Console\Editor\IO\Enumerations\KeyCode;

/**
 *
 */
final class VirtualAxis
{
    /**
     * @var string The name of the axis.
     */
    protected(set) string $name {
        get {
            return $this->name;
        }
    }

    /**
     * @var KeyCode[] Positive axis buttons
     */
    protected(set) array $positiveButtons {
        get {
            return $this->positiveButtons;
        }
    }

    /**
     * @var KeyCode[] Negative axis buttons
     */
    protected(set) array $negativeButtons {
        get {
            return $this->negativeButtons;
        }
    }

    /**
     * @var float The value of the axis give current input
     */
    public float $value {
        get {
            return match (true) {
                Input::isAnyKeyPressed($this->negativeButtons) => -1,
                Input::isAnyKeyPressed($this->positiveButtons) => 1,
                default => 0
            };
        }
    }

    /**
     * Build a new virtual axis.
     *
     * @param string $name The name of this axis.
     * @param KeyCode[] $positiveButtons The positive buttons for this axis.
     * @param KeyCode[] $negativeButtons The negative buttons for this axis.
     */
    public function __construct(
        string $name,
        array  $positiveButtons = [],
        array $negativeButtons = []
    )
    {
        $this->name = $name;
        $this->positiveButtons = $positiveButtons;
        $this->negativeButtons = $negativeButtons;
    }
}
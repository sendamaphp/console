<?php

namespace Sendama\Console\Editor\IO;

use Sendama\Console\Editor\IO\Enumerations\KeyCode;

final class Button
{
    protected(set) string $name {
        get {
            return $this->name;
        }
    }

    /**
     * @var KeyCode[] Positive button keys.
     */
    protected(set) array $positiveKeys {
        get {
            return $this->positiveKeys;
        }
    }

    /**
     * @var KeyCode[] Negative button keys.
     */
    protected(set) array $negativeKeys {
        get {
            return $this->negativeKeys;
        }
    }

    /**
     * @var float The value of the button give current input
     */
    public float $value {
        get {
            return match (true) {
                Input::isAnyKeyPressed($this->negativeKeys) => -1,
                Input::isAnyKeyPressed($this->positiveKeys) => 1,
                default => 0
            };
        }
    }

    /**
     * Constructs a button.
     *
     * @param string $name The name of this button.
     * @param array $positiveKeys The positive keys for this button.
     * @param array $negativeKeys The negative keys for this button.
     */
    public function __construct(
        string $name,
        array $positiveKeys = [],
        array $negativeKeys = []
    )
    {
        $this->name = $name;
        $this->positiveKeys = $positiveKeys;
        $this->negativeKeys = $negativeKeys;
    }
}
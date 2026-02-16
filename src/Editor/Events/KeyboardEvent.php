<?php

namespace Sendama\Console\Editor\Events;

use Atatusoft\Termutil\Events\Event;
use Sendama\Console\Editor\Events\Enumerations\EventType;

/**
 *
 */
class KeyboardEvent extends Event
{
    protected(set) string $key {
        get {
            return $this->key;
        }
    }
    protected(set) bool $ctrlKey {
        get {
            return $this->ctrlKey;
        }
    }

    protected(set) bool $shiftKey {
        get {
            return $this->shiftKey;
        }
    }

    protected(set) bool $altKey {
        get {
            return $this->altKey;
        }
    }

    protected(set) bool $metaKey {
        get {
            return $this->metaKey;
        }
    }

    /**
     * @param string $key
     * @param bool $ctrlKey
     * @param bool $shiftKey
     * @param bool $altKey
     * @param bool $metaKey
     */
    public function __construct(
        string $key,
        bool $ctrlKey = false,
        bool $shiftKey = false,
        bool $altKey = false,
        bool $metaKey = false,
    )
    {
        $this->key = $key;
        $this->ctrlKey = $ctrlKey;
        $this->shiftKey = $shiftKey;
        $this->altKey = $altKey;
        $this->metaKey = $metaKey;

        parent::__construct(EventType::KEYBOARD_INPUT->value, null, get_object_vars($this));
    }
}
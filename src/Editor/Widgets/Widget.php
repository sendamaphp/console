<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\UI\Windows\Window;
use Sendama\Console\Editor\FocusTargetContext;
use Sendama\Console\Editor\Interfaces\FocusableInterface;

/**
 *
 */
abstract class Widget extends Window implements FocusableInterface
{
    /**
     * @var int
     */
    public int $x {
        get {
            return $this->position["x"] ?? 0;
        }

        set {
            $this->position["y"] = $value;
        }
    }
    /**
     * @var int
     */
    public int $y {
        get {
            return $this->position["y"] ?? 0;
        }

        set {
            $this->position["y"] = $value;
        }
    }
    protected(set) bool $isEnabled = true;

    /**
     * Enables the widget.
     *
     * @return void
     */
    public function enable(): void
    {
        $this->isEnabled = true;
    }

    /**
     * Disables the widget.
     *
     * @return void
     */
    public function disable(): void
    {
        $this->isEnabled = false;
    }

    /**
     * @inheritDoc
     */
    public function focus(FocusTargetContext $context): void
    {
        // TODO: Implement focus() method.
    }

    /**
     * @inheritDoc
     */
    public function blur(FocusTargetContext $context): void
    {
        // TODO: Implement blur() method.
    }

    /**
     * @return void
     */
    public abstract function update(): void;
}
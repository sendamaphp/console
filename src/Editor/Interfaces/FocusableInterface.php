<?php

namespace Sendama\Console\Editor\Interfaces;

use Sendama\Console\Editor\FocusTargetContext;

/**
 * FocusableInterface interface.
 *
 * This interface defines the contract for all elements that can receive and lose focus.
 */
interface FocusableInterface
{
    /**
     * Called when the element receives focus.
     *
     * @param FocusTargetContext $context
     * @return void
     */
    public function focus(FocusTargetContext $context): void;

    /**
     * Called when the element loses focus.
     *
     * @param FocusTargetContext $context
     * @return void
     */
    public function blur(FocusTargetContext $context): void;
}
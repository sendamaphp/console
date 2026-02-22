<?php

namespace Sendama\Console\Editor\Interfaces;

use Sendama\Console\Editor\ExecutionContext;

/**
 * ExecutableInterface interface.
 *
 * This interface defines the contract for all executable items within the Editor.
 */
interface ExecutableInterface
{
    /**
     * @param ExecutionContext $context
     * @return int
     */
    public function execute(ExecutionContext $context): int;

    public function undo(ExecutionContext $context): int;
}
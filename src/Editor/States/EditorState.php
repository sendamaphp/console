<?php

namespace Sendama\Console\Editor\States;

use Sendama\Console\Editor\Editor;
use Sendama\Console\Editor\Interfaces\EditorStateInterface;

/**
 * The EditorState class is an abstract base class for all editor states. It provides common functionality and
 * structure for managing state transitions and interactions with the Editor.
 *
 * @package Sendama\Console\Editor\States
 */
abstract class EditorState implements EditorStateInterface
{
    public function __construct(protected Editor $editor)
    {
    }

    /**
     * @inheritDoc
     */
    public function enter(EditorStateContext $context): void
    {
        // Do nothing. Will be overridden by each state.
    }

    /**
     * @inheritDoc
     */
    public abstract function update(): void;

    /**
     * @inheritDoc
     */
    public abstract function render(): void;

    /**
     * @inheritDoc
     */
    public function exit(EditorStateContext $context): void
    {
        // Do nothing. Will be overridden by each state.
    }

    /**
     * @inheritDoc
     */
    public function setState(EditorStateInterface $state): void
    {
        $this->editor->setState($state);
    }
}
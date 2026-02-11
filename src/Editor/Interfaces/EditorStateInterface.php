<?php

namespace Sendama\Console\Editor\Interfaces;

use Sendama\Console\Editor\States\EditorStateContext;

/**
 * Interface EditorStateInterface
 *
 * This interface defines the contract for all editor states in the console editor.
 * Each state must implement the methods for entering, updating, rendering, and exiting the state.
 */
interface EditorStateInterface
{
    /**
     * Called when the state is entered. This is where you can initialize any necessary data or setup for the state.
     *
     * @param EditorStateContext $context The context of the editor state, which can be used to access shared data and methods.
     */
    public function enter(EditorStateContext $context): void;

    /**
     * Called on each update cycle while the state is active. This is where you can implement the logic for the state, such as handling user input or updating the state of the editor.
     *
     * @return void
     */
    public function update(): void;

    /**
     * Called on each render cycle while the state is active. This is where you can implement the logic for rendering the state of the editor, such as displaying information or drawing UI elements.
     *
     * @return void
     */
    public function render(): void;

    /**
     * Called when the state is exited. This is where you can clean up any resources or reset any data that was used in the state.
     *
     * @param EditorStateContext $context The context of the editor state, which can be used to access shared data and methods.
     */
    public function exit(EditorStateContext $context): void;

    /**
     * Sets the current state of the editor. This method can be used to transition to a different state.
     *
     * @param EditorStateInterface $state The new state to transition to.
     */
    public function setState(EditorStateInterface $state): void;
}
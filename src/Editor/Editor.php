<?php

namespace Sendama\Console\Editor;

use Sendama\Console\Editor\States\EditorState;

class Editor
{
    protected bool $isRunning = false;

    protected ?EditorState $editorState = null;

    public function __construct(
        public string $name,
    )
    {
    }

    public function start(): void
    {
        $this->isRunning = true;
    }

    public function finish(): void
    {
        // TODO: Implement finish() method.
    }

    public function run(): void
    {
        $this->start();

        while (!$this->isRunning) {
            $this->processInput();
            $this->update();
            $this->render();
        }

        $this->finish();
    }

    public function setState(EditorState $editorState): void
    {
        $this->editorState?->exit();
        $this->editorState = $editorState;
    }
}
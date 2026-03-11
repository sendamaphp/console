<?php

namespace Sendama\Console\Editor\Widgets;

class ConsolePanel extends Widget
{
    protected array $messages = [];

    public function __construct(
        array $position = ['x' => 37, 'y' => 22],
        int $width = 96,
        int $height = 8
    )
    {
        parent::__construct('Console', '', $position, $width, $height);
        $this->update();
    }

    public function append(string $message): void
    {
        $this->messages[] = $message;
        $this->update();
    }

    public function clear(): void
    {
        $this->messages = [];
        $this->update();
    }

    public function update(): void
    {
        $this->content = array_slice($this->messages, -$this->innerHeight);
    }
}

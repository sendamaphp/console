<?php

namespace Sendama\Console\Editor\States;

use Atatusoft\Termutil\IO\Console\Console;
use Atatusoft\Termutil\IO\Console\Cursor;
use Sendama\Console\Editor\GameSettings;

readonly class EditorStateContext
{
    public Cursor $cursor;

    public function __construct(
        public GameSettings $settings,
    )
    {
        $this->cursor = Console::cursor();
    }
}
<?php

namespace Sendama\Console\Editor\States;

use Atatusoft\Termutil\IO\Console\Console;
use Atatusoft\Termutil\IO\Console\Cursor;
use Sendama\Console\Editor\EditorSettings;
use Sendama\Console\Editor\GameSettings;
use Sendama\Console\Editor\Widgets\Widget;

readonly class EditorStateContext
{
    public Cursor $cursor;

    /**
     * @param EditorSettings $editorSettings
     * @param GameSettings|null $gameSettings
     * @param Widget[] $panels
     */
    public function __construct(
        public EditorSettings $editorSettings,
        public ?GameSettings $gameSettings,
        public array $panels,
    )
    {
        $this->cursor = Console::cursor();
    }
}
<?php

namespace Sendama\Console\Editor;

readonly class FocusTargetContext
{
    public function __construct(
        public Editor $editor,
        public GameSettings $gameSettings
    )
    {
    }
}
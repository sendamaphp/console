<?php

namespace Sendama\Console\Editor;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract readonly class ExecutionContext
{
    public function __construct(
        public Editor $editor,
        protected InputInterface $input,
        protected OutputInterface $output,
        protected EditorSettings $editorSettings,
        protected GameSettings $gameSettings
    )
    {
    }
}
<?php

namespace Sendama\Console\Editor\Widgets\Controls;

use Sendama\Console\Util\Path;

class PathInputControl extends TextInputControl
{
    public function __construct(
        string $label,
        mixed $value,
        protected string $workingDirectory,
        int $indentLevel = 1,
        bool $isReadOnly = false,
    )
    {
        parent::__construct($label, $value, $indentLevel, $isReadOnly);
        $this->workingDirectory = Path::normalize($workingDirectory);
    }

    public function getWorkingDirectory(): string
    {
        return $this->workingDirectory;
    }

    public function setValueFromRelativePath(string $relativePath): void
    {
        $normalizedPath = str_replace('\\', '/', $relativePath);
        $normalizedPath = ltrim($normalizedPath, '/');
        $this->setValue($normalizedPath);
    }
}

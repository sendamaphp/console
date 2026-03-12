<?php

namespace Sendama\Console\Editor\Widgets\Controls;

use Sendama\Console\Util\Path;

class PathInputControl extends TextInputControl
{
    public function __construct(
        string $label,
        mixed $value,
        protected string $workingDirectory,
        protected array $allowedExtensions = [],
        int $indentLevel = 1,
        bool $isReadOnly = false,
    )
    {
        parent::__construct($label, $value, $indentLevel, $isReadOnly);
        $this->workingDirectory = Path::normalize($workingDirectory);
        $this->allowedExtensions = array_values(array_filter(array_map(
            static fn(string $extension): string => ltrim(strtolower($extension), '.'),
            array_filter($allowedExtensions, 'is_string'),
        )));
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

    public function getAllowedExtensions(): array
    {
        return $this->allowedExtensions;
    }
}

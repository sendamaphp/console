<?php

namespace Sendama\Console\Editor\Widgets\Controls;

class PreviewWindowControl extends InputControl
{
    public function __construct(
        string $label,
        mixed $value,
        int $indentLevel = 1,
        bool $isReadOnly = true,
    )
    {
        parent::__construct($label, $value, $indentLevel, $isReadOnly);
    }

    public function renderLines(): array
    {
        $lines = [
            $this->indentation() . $this->label . ':',
        ];

        $previewLines = is_array($this->value) ? $this->value : [];

        if ($previewLines === []) {
            $previewLines = ['[unavailable]'];
        }

        foreach ($previewLines as $previewLine) {
            $lines[] = $this->indentation(1) . (string) $previewLine;
        }

        return $lines;
    }
}

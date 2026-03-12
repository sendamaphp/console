<?php

namespace Sendama\Console\Editor\Widgets\Controls;

class SectionControl extends InputControl
{
    private const string COLLAPSED_ICON = '▶';
    private const string EXPANDED_ICON = '▼';

    public function __construct(
        string $label,
        int $indentLevel = 0,
        protected bool $isCollapsed = false,
    )
    {
        parent::__construct($label, null, $indentLevel, true);
    }

    public function isCollapsed(): bool
    {
        return $this->isCollapsed;
    }

    public function toggleCollapsed(): void
    {
        $this->isCollapsed = !$this->isCollapsed;
    }

    public function renderLines(): array
    {
        return [
            $this->indentation() . ($this->isCollapsed ? self::COLLAPSED_ICON : self::EXPANDED_ICON) . ' ' . $this->label,
        ];
    }

    public function renderLineDefinitions(): array
    {
        return [[
            'text' => $this->renderLines()[0],
            'state' => $this->isEditing() ? 'editing' : ($this->hasFocus() ? 'selected' : 'normal'),
            'kind' => 'section_header',
        ]];
    }
}

<?php

namespace Sendama\Console\Editor\Widgets\Controls;

abstract class CompoundInputControl extends InputControl
{
    /**
     * @var array<InputControl>
     */
    protected array $controls = [];
    protected ?int $selectedControlIndex = null;
    protected bool $isSelectingProperty = false;

    /**
     * @param array<InputControl> $controls
     */
    public function __construct(
        string $label,
        mixed $value,
        array $controls,
        int $indentLevel = 1,
        bool $isReadOnly = false,
    )
    {
        parent::__construct($label, $value, $indentLevel, $isReadOnly);
        $this->controls = $controls;
    }

    /**
     * @return array<InputControl>
     */
    public function getControls(): array
    {
        return $this->controls;
    }

    public function getValue(): mixed
    {
        $this->synchronizeValueFromChildren();

        return $this->value;
    }

    public function beginPropertySelection(): bool
    {
        if ($this->controls === []) {
            return false;
        }

        $this->isSelectingProperty = true;
        $this->selectedControlIndex ??= 0;
        $this->applyPropertySelection();

        return true;
    }

    public function endPropertySelection(): void
    {
        $this->isSelectingProperty = false;

        foreach ($this->controls as $control) {
            $control->blur();

            if ($control->isEditing()) {
                $control->cancelEdit();
            }
        }
    }

    public function isSelectingProperty(): bool
    {
        return $this->isSelectingProperty;
    }

    public function movePropertySelection(int $offset): bool
    {
        if (!$this->isSelectingProperty || $this->controls === []) {
            return false;
        }

        $this->selectedControlIndex ??= 0;
        $this->selectedControlIndex = ($this->selectedControlIndex + $offset + count($this->controls))
            % count($this->controls);

        $this->applyPropertySelection();

        return true;
    }

    public function enterSelectedPropertyEdit(): bool
    {
        $selectedControl = $this->getSelectedPropertyControl();

        if (!$selectedControl instanceof InputControl) {
            return false;
        }

        return $selectedControl->enterEditMode();
    }

    public function commitActiveEdit(): bool
    {
        $selectedControl = $this->getSelectedPropertyControl();

        if (!$selectedControl instanceof InputControl) {
            return false;
        }

        $didCommit = $selectedControl->commitEdit();

        if ($didCommit) {
            $this->synchronizeValueFromChildren();
        }

        return $didCommit;
    }

    public function cancelActiveEdit(): void
    {
        $selectedControl = $this->getSelectedPropertyControl();
        $selectedControl?->cancelEdit();
    }

    public function handleInput(string $input): bool
    {
        $selectedControl = $this->getSelectedPropertyControl();

        if (!$selectedControl instanceof InputControl) {
            return false;
        }

        return $selectedControl->handleInput($input);
    }

    public function deleteBackward(): bool
    {
        $selectedControl = $this->getSelectedPropertyControl();

        if (!$selectedControl instanceof InputControl) {
            return false;
        }

        return $selectedControl->deleteBackward();
    }

    public function moveCursorLeft(): bool
    {
        $selectedControl = $this->getSelectedPropertyControl();

        if (!$selectedControl instanceof InputControl) {
            return false;
        }

        return $selectedControl->moveCursorLeft();
    }

    public function moveCursorRight(): bool
    {
        $selectedControl = $this->getSelectedPropertyControl();

        if (!$selectedControl instanceof InputControl) {
            return false;
        }

        return $selectedControl->moveCursorRight();
    }

    public function increment(): bool
    {
        $selectedControl = $this->getSelectedPropertyControl();

        if (!$selectedControl instanceof InputControl) {
            return false;
        }

        return $selectedControl->increment();
    }

    public function decrement(): bool
    {
        $selectedControl = $this->getSelectedPropertyControl();

        if (!$selectedControl instanceof InputControl) {
            return false;
        }

        return $selectedControl->decrement();
    }

    public function renderLines(): array
    {
        return array_column($this->renderLineDefinitions(), 'text');
    }

    public function renderLineDefinitions(): array
    {
        $lineDefinitions = [[
            'text' => $this->indentation() . $this->label . ':',
            'state' => $this->hasFocus && !$this->isSelectingProperty ? 'selected' : 'normal',
        ]];

        foreach ($this->controls as $index => $control) {
            foreach ($control->renderLineDefinitions() as $lineDefinition) {
                $lineDefinitions[] = [
                    'text' => $lineDefinition['text'],
                    'state' => $this->resolveChildLineState($control, $index, $lineDefinition['state'] ?? 'normal'),
                ];
            }
        }

        return $lineDefinitions;
    }

    private function resolveChildLineState(InputControl $control, int $index, string $defaultState): string
    {
        if (!$this->isSelectingProperty || $this->selectedControlIndex !== $index) {
            return 'normal';
        }

        if ($control->isEditing()) {
            return 'editing';
        }

        return $defaultState === 'editing' ? 'editing' : 'selected';
    }

    private function getSelectedPropertyControl(): ?InputControl
    {
        if ($this->selectedControlIndex === null) {
            return null;
        }

        return $this->controls[$this->selectedControlIndex] ?? null;
    }

    private function applyPropertySelection(): void
    {
        foreach ($this->controls as $index => $control) {
            if ($index === $this->selectedControlIndex) {
                $control->focus();
                continue;
            }

            $control->blur();

            if ($control->isEditing()) {
                $control->cancelEdit();
            }
        }
    }

    private function synchronizeValueFromChildren(): void
    {
        $value = [];

        foreach ($this->controls as $control) {
            $value[mb_strtolower($control->getLabel())] = $control->getValue();
        }

        if ($value !== []) {
            $this->value = $value;
        }
    }
}

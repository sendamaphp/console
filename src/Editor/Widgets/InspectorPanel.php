<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\IO\Enumerations\Color;
use Sendama\Console\Editor\FocusTargetContext;
use Sendama\Console\Editor\IO\Enumerations\KeyCode;
use Sendama\Console\Editor\IO\Input;
use Sendama\Console\Editor\Widgets\Controls\CompoundInputControl;
use Sendama\Console\Editor\Widgets\Controls\InputControl;
use Sendama\Console\Editor\Widgets\Controls\InputControlFactory;
use Sendama\Console\Editor\Widgets\Controls\NumberInputControl;
use Sendama\Console\Editor\Widgets\Controls\PathInputControl;
use Sendama\Console\Editor\Widgets\Controls\PreviewWindowControl;
use Sendama\Console\Editor\Widgets\Controls\TextInputControl;
use Sendama\Console\Editor\Widgets\Controls\VectorInputControl;

class InspectorPanel extends Widget
{
    private const string STATE_CONTROL_SELECTION = 'control_selection';
    private const string STATE_PROPERTY_SELECTION = 'property_selection';
    private const string STATE_CONTROL_EDIT = 'control_edit';
    private const string STATE_PATH_INPUT_ACTION_SELECTION = 'path_input_action_selection';
    private const string STATE_PATH_INPUT_FILE_DIALOG = 'path_input_file_dialog';
    private const string SECTION_ICON = '▼';
    private const string SECTION_HEADER_SEQUENCE = "\033[30;47m";
    private const string SELECTED_CONTROL_SEQUENCE = "\033[30;46m";
    private const string SELECTED_CONTROL_ACTIVE_SEQUENCE = "\033[5;30;46m";
    private const string EDITING_CONTROL_SEQUENCE = "\033[30;43m";
    private const string EDITING_CONTROL_ACTIVE_SEQUENCE = "\033[5;30;43m";

    protected ?array $inspectionTarget = null;
    protected array $elements = [];
    protected array $focusableControls = [];
    protected ?int $selectedControlIndex = null;
    protected array $lineKinds = [];
    protected array $lineStates = [];
    protected string $interactionState = self::STATE_CONTROL_SELECTION;
    protected InputControlFactory $inputControlFactory;
    protected ?PathInputControl $rendererTextureControl = null;
    protected ?VectorInputControl $rendererOffsetControl = null;
    protected ?VectorInputControl $rendererSizeControl = null;
    protected ?PreviewWindowControl $rendererPreviewControl = null;
    protected OptionListModal $pathInputActionModal;
    protected FileDialogModal $fileDialogModal;
    protected ?PathInputControl $activePathInputControl = null;
    protected array $controlBindings = [];
    protected ?array $pendingHierarchyMutation = null;
    protected string $projectDirectory;

    public function __construct(
        array $position = ['x' => 135, 'y' => 1],
        int $width = 35,
        int $height = 29,
        ?string $workingDirectory = null,
    )
    {
        parent::__construct('Inspector', '', $position, $width, $height);
        $this->inputControlFactory = new InputControlFactory();
        $this->pathInputActionModal = new OptionListModal(title: 'Path Input');
        $this->fileDialogModal = new FileDialogModal();
        $this->projectDirectory = is_string($workingDirectory) && $workingDirectory !== ''
            ? $workingDirectory
            : (getcwd() ?: '.');
    }

    public function inspectTarget(?array $target): void
    {
        $this->inspectionTarget = $target;
        $this->elements = [];
        $this->focusableControls = [];
        $this->selectedControlIndex = null;
        $this->interactionState = self::STATE_CONTROL_SELECTION;
        $this->rendererTextureControl = null;
        $this->rendererOffsetControl = null;
        $this->rendererSizeControl = null;
        $this->rendererPreviewControl = null;
        $this->pathInputActionModal->hide();
        $this->fileDialogModal->hide();
        $this->activePathInputControl = null;
        $this->controlBindings = [];
        $this->pendingHierarchyMutation = null;

        if ($target === null) {
            $this->content = [];
            $this->lineKinds = [];
            $this->lineStates = [];
            return;
        }

        $context = $target['context'] ?? null;
        $value = $target['value'] ?? null;

        if ($context === 'hierarchy' && is_array($value)) {
            $this->buildHierarchyControls($target, $value);
        } elseif ($context === 'scene' && is_array($value)) {
            $this->buildSceneControls($target, $value);
        } else {
            $this->buildGenericControls($target);
        }

        if ($this->focusableControls !== []) {
            $this->selectedControlIndex = 0;
            $this->applyControlSelection();
        }

        $this->refreshContent();
    }

    public function focus(FocusTargetContext $context): void
    {
        parent::focus($context);
        $this->interactionState = self::STATE_CONTROL_SELECTION;

        if ($this->selectedControlIndex === null && $this->focusableControls !== []) {
            $this->selectedControlIndex = 0;
        }

        $this->applyControlSelection();
        $this->refreshContent();
    }

    public function blur(FocusTargetContext $context): void
    {
        $this->resetInteractionState();
        parent::blur($context);
        $this->refreshContent();
    }

    public function hasActiveModal(): bool
    {
        return $this->pathInputActionModal->isVisible() || $this->fileDialogModal->isVisible();
    }

    public function isModalDirty(): bool
    {
        return $this->pathInputActionModal->isDirty() || $this->fileDialogModal->isDirty();
    }

    public function markModalClean(): void
    {
        $this->pathInputActionModal->markClean();
        $this->fileDialogModal->markClean();
    }

    public function syncModalLayout(int $terminalWidth, int $terminalHeight): void
    {
        $this->pathInputActionModal->syncLayout($terminalWidth, $terminalHeight);
        $this->fileDialogModal->syncLayout($terminalWidth, $terminalHeight);
    }

    public function renderActiveModal(): void
    {
        if ($this->pathInputActionModal->isVisible()) {
            $this->pathInputActionModal->render();
        }

        if ($this->fileDialogModal->isVisible()) {
            $this->fileDialogModal->render();
        }
    }

    public function consumeHierarchyMutation(): ?array
    {
        $pendingHierarchyMutation = $this->pendingHierarchyMutation;
        $this->pendingHierarchyMutation = null;

        return $pendingHierarchyMutation;
    }

    public function syncHierarchyTarget(string $path, array $value): void
    {
        if (
            !is_array($this->inspectionTarget)
            || ($this->inspectionTarget['context'] ?? null) !== 'hierarchy'
            || ($this->inspectionTarget['path'] ?? null) !== $path
        ) {
            return;
        }

        $target = $this->inspectionTarget;
        $target['name'] = $value['name'] ?? ($target['name'] ?? 'Unnamed Object');
        $target['type'] = $this->resolveDisplayType($target, $value);
        $target['value'] = $value;

        $this->inspectTarget($target);
    }

    public function syncSceneTarget(array $value): void
    {
        if (
            !is_array($this->inspectionTarget)
            || ($this->inspectionTarget['context'] ?? null) !== 'scene'
        ) {
            return;
        }

        $target = $this->inspectionTarget;
        $target['name'] = $value['name'] ?? ($target['name'] ?? 'Scene');
        $target['type'] = 'Scene';
        $target['path'] = 'scene';
        $target['value'] = $value;

        $this->inspectTarget($target);
    }

    public function cycleFocusForward(): bool
    {
        if ($this->interactionState !== self::STATE_CONTROL_SELECTION || $this->focusableControls === []) {
            return false;
        }

        $this->moveControlSelection(1);

        return true;
    }

    public function cycleFocusBackward(): bool
    {
        if ($this->interactionState !== self::STATE_CONTROL_SELECTION || $this->focusableControls === []) {
            return false;
        }

        $this->moveControlSelection(-1);

        return true;
    }

    public function update(): void
    {
        if (!$this->hasFocus()) {
            return;
        }

        if ($this->interactionState === self::STATE_PATH_INPUT_ACTION_SELECTION) {
            $this->handlePathInputActionInput();
            return;
        }

        if ($this->interactionState === self::STATE_PATH_INPUT_FILE_DIALOG) {
            $this->handlePathInputFileDialogInput();
            return;
        }

        if ($this->selectedControlIndex === null) {
            return;
        }

        $selectedControl = $this->getSelectedControl();

        if (!$selectedControl instanceof InputControl) {
            return;
        }

        match ($this->interactionState) {
            self::STATE_CONTROL_SELECTION => $this->handleControlSelectionInput($selectedControl),
            self::STATE_PROPERTY_SELECTION => $this->handlePropertySelectionInput($selectedControl),
            self::STATE_CONTROL_EDIT => $this->handleControlEditInput($selectedControl),
            default => null,
        };

        $selectedControl->update();
        $this->refreshContent();
    }

    public function renderAt(?int $x = null, ?int $y = null): void
    {
        parent::renderAt($x, $y);
    }

    protected function decorateContentLine(string $line, ?Color $contentColor, int $lineIndex): string
    {
        $contentIndex = $lineIndex - $this->padding->topPadding;
        $lineKind = $this->lineKinds[$contentIndex] ?? null;

        if ($lineKind === 'section_header') {
            return $this->decorateSectionHeaderLine($line, $contentColor, $lineIndex);
        }

        $lineState = $this->lineStates[$contentIndex] ?? 'normal';

        return match ($lineState) {
            'selected' => $this->decorateStatefulControlLine($line, $contentColor, $lineIndex, false),
            'editing' => $this->decorateStatefulControlLine($line, $contentColor, $lineIndex, true),
            default => parent::decorateContentLine($line, $contentColor, $lineIndex),
        };
    }

    private function decorateSectionHeaderLine(string $line, ?Color $contentColor, int $lineIndex): string
    {
        $visibleLine = mb_substr($line, 0, $this->width);
        $visibleLength = mb_strlen($visibleLine);

        if ($visibleLength <= 1) {
            return parent::decorateContentLine($line, $contentColor, $lineIndex);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleLength > 2 ? mb_substr($visibleLine, 1, $visibleLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);
        $borderColor = $this->hasFocus() ? $this->focusBorderColor : $contentColor;

        return $this->wrapWithColor($leftBorder, $borderColor)
            . $this->wrapWithSequence($middle, self::SECTION_HEADER_SEQUENCE)
            . $this->wrapWithColor($rightBorder, $borderColor);
    }

    private function decorateStatefulControlLine(
        string $line,
        ?Color $contentColor,
        int $lineIndex,
        bool $isEditing,
    ): string
    {
        $visibleLine = mb_substr($line, 0, $this->width);
        $visibleLength = mb_strlen($visibleLine);

        if ($visibleLength <= 1) {
            return parent::decorateContentLine($line, $contentColor, $lineIndex);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleLength > 2 ? mb_substr($visibleLine, 1, $visibleLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);
        $borderColor = $this->hasFocus() ? $this->focusBorderColor : $contentColor;
        $selectionSequence = match (true) {
            $isEditing && $this->hasFocus() => self::EDITING_CONTROL_ACTIVE_SEQUENCE,
            $isEditing => self::EDITING_CONTROL_SEQUENCE,
            $this->hasFocus() => self::SELECTED_CONTROL_ACTIVE_SEQUENCE,
            default => self::SELECTED_CONTROL_SEQUENCE,
        };

        return $this->wrapWithColor($leftBorder, $borderColor)
            . $this->wrapWithSequence($middle, $selectionSequence)
            . $this->wrapWithColor($rightBorder, $borderColor);
    }

    private function buildHierarchyControls(array $target, array $item): void
    {
        $this->addControl(new TextInputControl('Type', $this->resolveDisplayType($target, $item), 0, true));
        $this->addBoundControl(
            new TextInputControl('Name', $item['name'] ?? $target['name'] ?? 'Unnamed Object', 0),
            ['name'],
        );
        $this->addBoundControl(
            new TextInputControl('Tag', $item['tag'] ?? 'None', 0),
            ['tag'],
        );

        $this->addSectionHeader('Transform');
        $this->addBoundControl(
            new VectorInputControl('Position', $this->normalizeVector($item['position'] ?? null), 1),
            ['position'],
        );
        $this->addBoundControl(
            new VectorInputControl('Rotation', $this->normalizeVector($item['rotation'] ?? null), 1),
            ['rotation'],
        );
        $this->addBoundControl(
            new VectorInputControl('Scale', $this->normalizeVector($item['scale'] ?? ['x' => 1, 'y' => 1]), 1),
            ['scale'],
        );

        if (isset($item['size']) && is_array($item['size'])) {
            $this->addBoundControl(
                new VectorInputControl('Size', $this->normalizeVector($item['size']), 1),
                ['size'],
            );
        }

        $this->addSectionHeader('Renderer');
        $this->addRendererControls($item);
        $this->addScriptComponents($item['components'] ?? []);
    }

    private function buildSceneControls(array $target, array $scene): void
    {
        $this->addControl(new TextInputControl('Type', 'Scene', 0, true));
        $this->addBoundControl(
            new TextInputControl('Name', $scene['name'] ?? $target['name'] ?? 'Scene', 0),
            ['name'],
        );
        $this->addBoundControl(
            new NumberInputControl('Width', $scene['width'] ?? DEFAULT_TERMINAL_WIDTH, 0),
            ['width'],
        );
        $this->addBoundControl(
            new NumberInputControl('Height', $scene['height'] ?? DEFAULT_TERMINAL_HEIGHT, 0),
            ['height'],
        );
        $this->addBoundControl(
            new PathInputControl(
                'Environment Tile Map',
                $scene['environmentTileMapPath'] ?? 'Maps/example',
                $this->resolveAssetsWorkingDirectory(),
                0,
            ),
            ['environmentTileMapPath'],
        );
    }

    private function buildGenericControls(array $target): void
    {
        if (isset($target['type'])) {
            $this->addControl(new TextInputControl('Type', $target['type'], 0, true));
        }

        if (isset($target['name'])) {
            $this->addControl(new TextInputControl('Name', $target['name'], 0, true));
        }
    }

    private function addRendererControls(array $item): void
    {
        $sprite = is_array($item['sprite'] ?? null) ? $item['sprite'] : [];
        $texture = is_array($sprite['texture'] ?? null) ? $sprite['texture'] : [];
        $texturePath = is_string($texture['path'] ?? null) && $texture['path'] !== ''
            ? $texture['path']
            : 'None';
        $offset = $this->normalizeVector($texture['position'] ?? null);
        $size = $this->normalizeVector($texture['size'] ?? null);

        $this->rendererTextureControl = new PathInputControl(
            'Texture',
            $texturePath,
            $this->resolveAssetsWorkingDirectory(),
            1,
        );
        $this->rendererOffsetControl = new VectorInputControl('Offset', $offset, 1);
        $this->rendererSizeControl = new VectorInputControl('Size', $size, 1);
        $this->rendererPreviewControl = new PreviewWindowControl(
            'Preview',
            $this->buildTexturePreviewLines($texturePath, $offset, $size),
            1,
        );

        $this->addBoundControl($this->rendererTextureControl, ['sprite', 'texture', 'path']);
        $this->addBoundControl($this->rendererOffsetControl, ['sprite', 'texture', 'position']);
        $this->addBoundControl($this->rendererSizeControl, ['sprite', 'texture', 'size']);
        $this->addControl($this->rendererPreviewControl);

        if (array_key_exists('text', $item)) {
            $this->addBoundControl(new TextInputControl('Text', $item['text'], 1), ['text']);
        }
    }

    private function addScriptComponents(mixed $components): void
    {
        if (!is_array($components)) {
            return;
        }

        foreach ($components as $componentIndex => $component) {
            if (!is_array($component)) {
                continue;
            }

            $this->addSectionHeader($this->resolveClassName($component['class'] ?? null, 'Component'));

            foreach ($component as $key => $value) {
                if ($key === 'class') {
                    continue;
                }

                $this->addBoundControl(
                    $this->inputControlFactory->create(
                        $this->humanizeKey((string) $key),
                        $value,
                        1,
                    ),
                    ['components', $componentIndex, $key],
                );
            }
        }
    }

    private function addSectionHeader(string $title): void
    {
        $this->elements[] = [
            'kind' => 'section_header',
            'text' => self::SECTION_ICON . ' ' . $title,
        ];
    }

    private function addControl(InputControl $control): void
    {
        $this->elements[] = [
            'kind' => 'control',
            'control' => $control,
        ];
        $this->focusableControls[] = $control;
    }

    private function addBoundControl(InputControl $control, array $valuePath): void
    {
        $this->controlBindings[spl_object_id($control)] = $valuePath;
        $this->addControl($control);
    }

    private function refreshContent(): void
    {
        $this->refreshDerivedControls();
        $content = [];
        $lineKinds = [];
        $lineStates = [];

        foreach ($this->elements as $element) {
            $kind = $element['kind'] ?? 'plain';

            if ($kind === 'section_header') {
                $content[] = $element['text'] ?? '';
                $lineKinds[] = 'section_header';
                $lineStates[] = 'normal';
                continue;
            }

            $control = $element['control'] ?? null;

            if (!$control instanceof InputControl) {
                continue;
            }

            foreach ($control->renderLineDefinitions() as $lineDefinition) {
                $content[] = $lineDefinition['text'] ?? '';
                $lineKinds[] = 'control';
                $lineStates[] = $lineDefinition['state'] ?? 'normal';
            }
        }

        $this->content = $content;
        $this->lineKinds = $lineKinds;
        $this->lineStates = $lineStates;
    }

    private function refreshDerivedControls(): void
    {
        if (
            !$this->rendererTextureControl instanceof PathInputControl
            || !$this->rendererOffsetControl instanceof VectorInputControl
            || !$this->rendererSizeControl instanceof VectorInputControl
            || !$this->rendererPreviewControl instanceof PreviewWindowControl
        ) {
            return;
        }

        $texturePath = (string) $this->rendererTextureControl->getValue();
        $offset = $this->rendererOffsetControl->getValue();
        $size = $this->rendererSizeControl->getValue();

        if (!is_array($offset) || !is_array($size)) {
            return;
        }

        $this->rendererPreviewControl->setValue(
            $this->buildTexturePreviewLines($texturePath, $offset, $size)
        );
    }

    private function applyControlSelection(): void
    {
        foreach ($this->focusableControls as $index => $control) {
            if ($index === $this->selectedControlIndex) {
                $control->focus();
                continue;
            }

            $control->blur();

            if ($control instanceof CompoundInputControl) {
                $control->endPropertySelection();
            }

            if ($control->isEditing()) {
                $control->cancelEdit();
            }
        }
    }

    private function getSelectedControl(): ?InputControl
    {
        if ($this->selectedControlIndex === null) {
            return null;
        }

        return $this->focusableControls[$this->selectedControlIndex] ?? null;
    }

    private function moveControlSelection(int $offset): void
    {
        if ($this->focusableControls === []) {
            return;
        }

        $this->selectedControlIndex ??= 0;
        $this->selectedControlIndex = ($this->selectedControlIndex + $offset + count($this->focusableControls))
            % count($this->focusableControls);
        $this->applyControlSelection();
        $this->refreshContent();
    }

    private function handleControlSelectionInput(InputControl $selectedControl): void
    {
        if (Input::isKeyDown(KeyCode::UP)) {
            $this->moveControlSelection(-1);
            return;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $this->moveControlSelection(1);
            return;
        }

        if (!Input::isKeyDown(KeyCode::ENTER)) {
            return;
        }

        if ($selectedControl instanceof PathInputControl) {
            $this->showPathInputActionModal($selectedControl);
            return;
        }

        if ($selectedControl instanceof CompoundInputControl) {
            if ($selectedControl->beginPropertySelection()) {
                $this->interactionState = self::STATE_PROPERTY_SELECTION;
            }

            return;
        }

        if ($selectedControl->enterEditMode()) {
            $this->interactionState = self::STATE_CONTROL_EDIT;
        }
    }

    private function handlePropertySelectionInput(InputControl $selectedControl): void
    {
        if (!$selectedControl instanceof CompoundInputControl) {
            $this->interactionState = self::STATE_CONTROL_SELECTION;
            return;
        }

        if (Input::isKeyDown(KeyCode::ESCAPE)) {
            $selectedControl->endPropertySelection();
            $this->interactionState = self::STATE_CONTROL_SELECTION;
            return;
        }

        if (Input::isKeyDown(KeyCode::UP)) {
            $selectedControl->movePropertySelection(-1);
            return;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $selectedControl->movePropertySelection(1);
            return;
        }

        if (Input::isKeyDown(KeyCode::ENTER) && $selectedControl->enterSelectedPropertyEdit()) {
            $this->interactionState = self::STATE_CONTROL_EDIT;
        }
    }

    private function handleControlEditInput(InputControl $selectedControl): void
    {
        if (Input::isKeyDown(KeyCode::ENTER)) {
            $this->commitSelectedEdit($selectedControl);
            return;
        }

        if (Input::isKeyDown(KeyCode::ESCAPE)) {
            $this->cancelSelectedEdit($selectedControl);
            return;
        }

        if (Input::isKeyDown(KeyCode::BACKSPACE)) {
            $selectedControl->deleteBackward();
            return;
        }

        if (Input::isKeyDown(KeyCode::LEFT)) {
            $selectedControl->moveCursorLeft();
            return;
        }

        if (Input::isKeyDown(KeyCode::RIGHT)) {
            $selectedControl->moveCursorRight();
            return;
        }

        if (Input::isKeyDown(KeyCode::UP) && $selectedControl->increment()) {
            return;
        }

        if (Input::isKeyDown(KeyCode::DOWN) && $selectedControl->decrement()) {
            return;
        }

        $selectedControl->handleInput(Input::getCurrentInput());
    }

    private function commitSelectedEdit(InputControl $selectedControl): void
    {
        if ($selectedControl instanceof CompoundInputControl) {
            if ($selectedControl->commitActiveEdit()) {
                $this->applyControlValueToInspectionTarget($selectedControl);
            }

            $this->interactionState = self::STATE_PROPERTY_SELECTION;
            return;
        }

        if ($selectedControl->commitEdit()) {
            $this->applyControlValueToInspectionTarget($selectedControl);
        }

        if ($selectedControl instanceof PathInputControl) {
            $this->activePathInputControl = null;
        }

        $this->interactionState = self::STATE_CONTROL_SELECTION;
    }

    private function cancelSelectedEdit(InputControl $selectedControl): void
    {
        if ($selectedControl instanceof CompoundInputControl) {
            $selectedControl->cancelActiveEdit();
            $this->interactionState = self::STATE_PROPERTY_SELECTION;
            return;
        }

        $selectedControl->cancelEdit();

        if ($selectedControl instanceof PathInputControl) {
            $this->activePathInputControl = null;
        }

        $this->interactionState = self::STATE_CONTROL_SELECTION;
    }

    private function resetInteractionState(): void
    {
        $this->closePathInputModals();
        $selectedControl = $this->getSelectedControl();

        if ($selectedControl instanceof CompoundInputControl) {
            if ($this->interactionState === self::STATE_CONTROL_EDIT) {
                $selectedControl->cancelActiveEdit();
            }

            $selectedControl->endPropertySelection();
        } elseif ($selectedControl?->isEditing()) {
            $selectedControl->cancelEdit();
        }

        $this->interactionState = self::STATE_CONTROL_SELECTION;
    }

    private function handlePathInputActionInput(): void
    {
        if (Input::isKeyDown(KeyCode::ESCAPE)) {
            $this->closePathInputModals();
            $this->interactionState = self::STATE_CONTROL_SELECTION;
            $this->refreshContent();
            return;
        }

        if (Input::isKeyDown(KeyCode::UP)) {
            $this->pathInputActionModal->moveSelection(-1);
            return;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $this->pathInputActionModal->moveSelection(1);
            return;
        }

        if (!Input::isKeyDown(KeyCode::ENTER)) {
            return;
        }

        $selectedOption = $this->pathInputActionModal->getSelectedOption();

        if ($selectedOption === 'Choose file') {
            $this->pathInputActionModal->hide();

            if ($this->activePathInputControl instanceof PathInputControl) {
                $this->fileDialogModal->show(
                    $this->activePathInputControl->getWorkingDirectory(),
                    (string) $this->activePathInputControl->getValue(),
                );
                $this->interactionState = self::STATE_PATH_INPUT_FILE_DIALOG;
            }

            return;
        }

        if ($selectedOption === 'Edit path' && $this->activePathInputControl instanceof PathInputControl) {
            $this->pathInputActionModal->hide();

            if ($this->activePathInputControl->enterEditMode()) {
                $this->interactionState = self::STATE_CONTROL_EDIT;
            } else {
                $this->closePathInputModals();
                $this->interactionState = self::STATE_CONTROL_SELECTION;
            }
        }
    }

    private function handlePathInputFileDialogInput(): void
    {
        if (Input::isKeyDown(KeyCode::ESCAPE)) {
            $this->fileDialogModal->hide();

            if ($this->activePathInputControl instanceof PathInputControl) {
                $this->pathInputActionModal->show(['Choose file', 'Edit path'], 0);
                $this->interactionState = self::STATE_PATH_INPUT_ACTION_SELECTION;
            } else {
                $this->interactionState = self::STATE_CONTROL_SELECTION;
            }

            return;
        }

        if (Input::isKeyDown(KeyCode::UP)) {
            $this->fileDialogModal->moveSelection(-1);
            return;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $this->fileDialogModal->moveSelection(1);
            return;
        }

        if (Input::isKeyDown(KeyCode::RIGHT)) {
            $this->fileDialogModal->expandSelection();
            return;
        }

        if (Input::isKeyDown(KeyCode::LEFT)) {
            $this->fileDialogModal->collapseSelection();
            return;
        }

        if (!Input::isKeyDown(KeyCode::ENTER)) {
            return;
        }

        $selectedPath = $this->fileDialogModal->submitSelection();

        if ($selectedPath === null || !$this->activePathInputControl instanceof PathInputControl) {
            return;
        }

        $this->activePathInputControl->setValueFromRelativePath($selectedPath);
        $this->applyControlValueToInspectionTarget($this->activePathInputControl);
        $this->closePathInputModals();
        $this->interactionState = self::STATE_CONTROL_SELECTION;
        $this->refreshContent();
    }

    private function showPathInputActionModal(PathInputControl $control): void
    {
        $this->activePathInputControl = $control;
        $this->pathInputActionModal->show(['Choose file', 'Edit path']);
        $this->interactionState = self::STATE_PATH_INPUT_ACTION_SELECTION;
        $terminalSize = get_max_terminal_size();
        $terminalWidth = $terminalSize['width'] ?? DEFAULT_TERMINAL_WIDTH;
        $terminalHeight = $terminalSize['height'] ?? DEFAULT_TERMINAL_HEIGHT;
        $this->syncModalLayout($terminalWidth, $terminalHeight);
    }

    private function closePathInputModals(): void
    {
        $this->pathInputActionModal->hide();
        $this->fileDialogModal->hide();
        $this->activePathInputControl = null;
    }

    private function buildTexturePreviewLines(string $texturePath, array $offset, array $size): array
    {
        if ($texturePath === 'None') {
            return ['[unavailable]'];
        }

        if ((int) $size['x'] <= 0 || (int) $size['y'] <= 0) {
            return ['[unavailable]'];
        }

        $resolvedTextureFilePath = $this->resolveTextureFilePath($texturePath);

        if ($resolvedTextureFilePath === null) {
            return ['[missing texture]'];
        }

        $textureContents = file_get_contents($resolvedTextureFilePath);

        if ($textureContents === false || $textureContents === '') {
            return ['[empty texture]'];
        }

        $textureRows = preg_split('/\R/u', rtrim($textureContents, "\r\n"));

        if ($textureRows === false) {
            return ['[unavailable]'];
        }

        if (count($textureRows) <= 1) {
            $textureRows = $this->expandSingleLineTexture(
                $textureRows[0] ?? '',
                (int) $size['x']
            );
        }

        $previewWidth = (int) $size['x'];
        $previewHeight = (int) $size['y'];
        $offsetX = max(0, (int) $offset['x']);
        $offsetY = max(0, (int) $offset['y']);
        $previewLines = [];

        for ($rowIndex = 0; $rowIndex < $previewHeight; $rowIndex++) {
            $sourceRow = $textureRows[$offsetY + $rowIndex] ?? '';
            $previewLine = mb_substr($sourceRow, $offsetX, $previewWidth);

            if ($previewLine === '') {
                $previewLine = str_repeat(' ', $previewWidth);
            }

            $previewLines[] = $previewLine;
        }

        return $previewLines === [] ? ['[unavailable]'] : $previewLines;
    }

    private function resolveDisplayType(array $target, array $item): string
    {
        $displayType = $target['type'] ?? null;

        if (is_string($displayType) && $displayType !== '') {
            return $displayType;
        }

        return $this->resolveClassName($item['type'] ?? null, 'Unknown');
    }

    private function resolveClassName(mixed $classReference, string $default = 'Unknown'): string
    {
        if (!is_string($classReference) || $classReference === '') {
            return $default;
        }

        $normalizedClassReference = ltrim($classReference, '\\');
        $normalizedClassReference = preg_replace('/::class$/', '', $normalizedClassReference)
            ?? $normalizedClassReference;
        $classSegments = explode('\\', $normalizedClassReference);

        return end($classSegments) ?: $default;
    }

    private function resolveTextureFilePath(string $texturePath): ?string
    {
        $normalizedTexturePath = str_replace('\\', '/', $texturePath);
        $candidatePaths = [];

        if ($this->hasFileExtension($normalizedTexturePath)) {
            $candidatePaths[] = $normalizedTexturePath;
        } else {
            $candidatePaths[] = $normalizedTexturePath . '.texture';
        }

        $workingDirectory = $this->projectDirectory;
        $assetsRoots = [
            $workingDirectory,
            $workingDirectory . '/Assets',
            $workingDirectory . '/assets',
        ];

        foreach ($assetsRoots as $assetsRoot) {
            $trimmedAssetsRoot = rtrim($assetsRoot, '/');

            foreach ($candidatePaths as $candidatePath) {
                $resolvedPath = $trimmedAssetsRoot . '/' . ltrim($candidatePath, '/');

                if (is_file($resolvedPath)) {
                    return $resolvedPath;
                }
            }
        }

        return null;
    }

    private function hasFileExtension(string $path): bool
    {
        return pathinfo($path, PATHINFO_EXTENSION) !== '';
    }

    private function normalizeVector(mixed $value): array
    {
        if (!is_array($value)) {
            return ['x' => 0, 'y' => 0];
        }

        return [
            'x' => $this->normalizeNumericValue($value['x'] ?? 0),
            'y' => $this->normalizeNumericValue($value['y'] ?? 0),
        ];
    }

    private function normalizeNumericValue(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        return 0;
    }

    private function expandSingleLineTexture(string $textureContents, int $rowWidth): array
    {
        if ($textureContents === '') {
            return [];
        }

        $characters = preg_split('//u', $textureContents, -1, PREG_SPLIT_NO_EMPTY);

        if ($characters === false || $characters === []) {
            return [];
        }

        if ($rowWidth <= 1) {
            return $characters;
        }

        $rows = [];

        for ($index = 0; $index < count($characters); $index += $rowWidth) {
            $rows[] = implode('', array_slice($characters, $index, $rowWidth));
        }

        return $rows;
    }

    private function humanizeKey(string $key): string
    {
        $spacedKey = preg_replace('/(?<!^)([A-Z])/', ' $1', $key) ?? $key;
        $spacedKey = str_replace(['_', '-'], ' ', $spacedKey);

        return ucwords(trim($spacedKey));
    }

    private function applyControlValueToInspectionTarget(InputControl $control): void
    {
        if (
            !is_array($this->inspectionTarget)
            || !in_array($this->inspectionTarget['context'] ?? null, ['hierarchy', 'scene'], true)
            || !isset($this->inspectionTarget['value'])
            || !is_array($this->inspectionTarget['value'])
        ) {
            return;
        }

        $valuePath = $this->controlBindings[spl_object_id($control)] ?? null;

        if (!is_array($valuePath) || $valuePath === []) {
            return;
        }

        $inspectionValue = $this->inspectionTarget['value'];
        $this->setNestedValue($inspectionValue, $valuePath, $control->getValue());
        $this->inspectionTarget['value'] = $inspectionValue;

        if ($valuePath === ['name']) {
            $this->inspectionTarget['name'] = (string) $control->getValue();
        }

        $hierarchyPath = $this->inspectionTarget['path'] ?? null;

        if (!is_string($hierarchyPath) || $hierarchyPath === '') {
            return;
        }

        $this->pendingHierarchyMutation = [
            'path' => $hierarchyPath,
            'value' => $inspectionValue,
        ];
    }

    private function setNestedValue(array &$value, array $path, mixed $nextValue): void
    {
        $current = &$value;
        $lastIndex = count($path) - 1;

        foreach ($path as $index => $segment) {
            if ($index === $lastIndex) {
                $current[$segment] = $nextValue;
                return;
            }

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }
    }

    private function resolveAssetsWorkingDirectory(): string
    {
        $workingDirectory = $this->projectDirectory;
        $assetRoots = [
            $workingDirectory . '/Assets',
            $workingDirectory . '/assets',
            $workingDirectory,
        ];

        foreach ($assetRoots as $assetRoot) {
            if (is_dir($assetRoot)) {
                return $assetRoot;
            }
        }

        return $workingDirectory;
    }

}

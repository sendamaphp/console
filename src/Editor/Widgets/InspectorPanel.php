<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\Events\MouseEvent;
use Atatusoft\Termutil\IO\Enumerations\Color;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Sendama\Engine\IO\Enumerations\Color as EngineColor;
use Sendama\Console\Editor\EditorColorScheme;
use Sendama\Console\Editor\PrefabLoader;
use Sendama\Console\Editor\ProjectAutoloadLoader;
use Throwable;
use Sendama\Console\Editor\FocusTargetContext;
use Sendama\Console\Editor\IO\Enumerations\KeyCode;
use Sendama\Console\Editor\IO\Input;
use Sendama\Console\Util\Path;
use Sendama\Console\Editor\Widgets\Controls\CompoundInputControl;
use Sendama\Console\Editor\Widgets\Controls\InputControl;
use Sendama\Console\Editor\Widgets\Controls\InputControlFactory;
use Sendama\Console\Editor\Widgets\Controls\NumberInputControl;
use Sendama\Console\Editor\Widgets\Controls\PathInputControl;
use Sendama\Console\Editor\Widgets\Controls\PrefabReferenceInputControl;
use Sendama\Console\Editor\Widgets\Controls\PreviewWindowControl;
use Sendama\Console\Editor\Widgets\Controls\SectionControl;
use Sendama\Console\Editor\Widgets\Controls\SelectInputControl;
use Sendama\Console\Editor\Widgets\Controls\SliderInputControl;
use Sendama\Console\Editor\Widgets\Controls\TextInputControl;
use Sendama\Console\Editor\Widgets\Controls\UIElementReferenceInputControl;
use Sendama\Console\Editor\Widgets\Controls\VectorInputControl;

class InspectorPanel extends Widget
{
    private const float DOUBLE_CLICK_THRESHOLD_SECONDS = 0.35;
    private const string STATE_CONTROL_SELECTION = 'control_selection';
    private const string STATE_PROPERTY_SELECTION = 'property_selection';
    private const string STATE_CONTROL_EDIT = 'control_edit';
    private const string STATE_PATH_INPUT_ACTION_SELECTION = 'path_input_action_selection';
    private const string STATE_PATH_INPUT_FILE_DIALOG = 'path_input_file_dialog';
    private const string STATE_PREFAB_REFERENCE_SELECTION = 'prefab_reference_selection';
    private const string STATE_UI_ELEMENT_REFERENCE_SELECTION = 'ui_element_reference_selection';
    private const string SECTION_HEADER_SEQUENCE = EditorColorScheme::SURFACE_SEQUENCE;
    private const string SECTION_HEADER_SELECTED_SEQUENCE = EditorColorScheme::SELECTED_ROW_SEQUENCE;
    private const string SELECTED_CONTROL_SEQUENCE = EditorColorScheme::SELECTED_ROW_SEQUENCE;
    private const string SELECTED_CONTROL_ACTIVE_SEQUENCE = EditorColorScheme::SELECTED_ROW_FOCUSED_SEQUENCE;
    private const string EDITING_CONTROL_SEQUENCE = EditorColorScheme::EDITING_SEQUENCE;
    private const string EDITING_CONTROL_ACTIVE_SEQUENCE = EditorColorScheme::EDITING_FOCUSED_SEQUENCE;
    private const array DEFAULT_COMPONENT_CANDIDATES = [
        'Sendama\\Engine\\Core\\Behaviours\\SimpleQuitListener',
        'Sendama\\Engine\\Core\\Behaviours\\SimpleBackListener',
        'Sendama\\Engine\\Core\\Behaviours\\CharacterMovement',
        'Sendama\\Engine\\Physics\\Rigidbody',
        'Sendama\\Engine\\Physics\\Collider',
        'Sendama\\Engine\\Physics\\CharacterController',
        'Sendama\\Engine\\Animation\\AnimationController',
    ];

    protected ?array $inspectionTarget = null;
    protected array $elements = [];
    protected array $focusableControls = [];
    protected ?int $selectedControlIndex = null;
    protected array $lineKinds = [];
    protected array $lineStates = [];
    protected array $lineControlIndexes = [];
    protected string $interactionState = self::STATE_CONTROL_SELECTION;
    protected InputControlFactory $inputControlFactory;
    protected array $texturePreviewRegistrations = [];
    protected OptionListModal $pathInputActionModal;
    protected FileDialogModal $fileDialogModal;
    protected OptionListModal $addComponentModal;
    protected OptionListModal $deleteComponentModal;
    protected OptionListModal $prefabReferenceModal;
    protected OptionListModal $uiElementReferenceModal;
    protected ?PathInputControl $activePathInputControl = null;
    protected ?PrefabReferenceInputControl $activePrefabReferenceControl = null;
    protected ?UIElementReferenceInputControl $activeUIElementReferenceControl = null;
    protected array $controlBindings = [];
    protected array $controlMetadata = [];
    protected ?array $pendingHierarchyMutation = null;
    protected ?array $pendingPrefabMutation = null;
    protected ?array $pendingAssetMutation = null;
    protected string $projectDirectory;
    protected array $sceneHierarchy = [];
    protected array $componentMenuDefinitions = [];
    protected ?array $cachedProjectComponentCandidates = null;
    protected bool $isComponentMoveModeActive = false;
    protected ?int $pendingComponentDeletionIndex = null;
    protected array $prefabReferenceOptions = [];
    protected array $uiElementReferenceOptions = [];
    protected string $modeHelpLabel = '';
    protected bool $shouldRefreshModalBackground = false;
    protected ?int $lastClickedControlIndex = null;
    protected float $lastClickedControlAt = 0.0;
    protected array $classImportAliasCache = [];
    private const string GUI_TEXTURE_TYPE = 'Sendama\\Engine\\UI\\GUITexture\\GUITexture';
    private const string UI_ELEMENT_TYPE = 'Sendama\\Engine\\UI\\UIElement';
    private const string UI_ELEMENT_INTERFACE_TYPE = 'Sendama\\Engine\\UI\\Interfaces\\UIElementInterface';
    private const array GUI_TEXTURE_COLOR_OPTIONS = [
        'Black',
        'Dark Gray',
        'Blue',
        'Light Blue',
        'Green',
        'Light Green',
        'Cyan',
        'Light Cyan',
        'Red',
        'Light Red',
        'Purple',
        'Light Purple',
        'Brown',
        'Yellow',
        'Light Gray',
        'White',
    ];

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
        $this->addComponentModal = new OptionListModal(title: 'Add Component');
        $this->deleteComponentModal = new OptionListModal(title: 'Remove Component');
        $this->prefabReferenceModal = new OptionListModal(title: 'Choose Prefab');
        $this->uiElementReferenceModal = new OptionListModal(title: 'Choose UI Element');
        $this->projectDirectory = is_string($workingDirectory) && $workingDirectory !== ''
            ? $workingDirectory
            : (getcwd() ?: '.');
    }

    public function setSceneHierarchy(array $hierarchy): void
    {
        $this->sceneHierarchy = $hierarchy;
    }

    public function handleMouseClick(int $x, int $y): void
    {
        if (!$this->containsPoint($x, $y) || $this->hasActiveModal()) {
            return;
        }

        $controlIndex = $this->resolveControlIndexFromPoint($x, $y);

        if (!is_int($controlIndex)) {
            return;
        }

        if ($this->interactionState !== self::STATE_CONTROL_SELECTION) {
            $this->resetInteractionState();
        }

        $this->selectControlByIndex($controlIndex);
        $selectedControl = $this->getSelectedControl();

        if (!$selectedControl instanceof InputControl) {
            return;
        }

        if (!$this->registerControlClickAndCheckDoubleClick($controlIndex)) {
            return;
        }

        $this->activateSelectedControl($selectedControl);
    }

    public function inspectTarget(?array $target): void
    {
        $preserveSelectedControl = $this->shouldPreserveSelectedControl($this->inspectionTarget, $target);
        $selectedControlSnapshot = $preserveSelectedControl
            ? $this->captureSelectedControlSnapshot($this->getSelectedControl())
            : [];

        $this->inspectionTarget = $target;
        $this->elements = [];
        $this->focusableControls = [];
        $this->selectedControlIndex = null;
        $this->interactionState = self::STATE_CONTROL_SELECTION;
        $this->lastClickedControlIndex = null;
        $this->lastClickedControlAt = 0.0;
        $this->texturePreviewRegistrations = [];
        $this->pathInputActionModal->hide();
        $this->fileDialogModal->hide();
        $this->addComponentModal->hide();
        $this->deleteComponentModal->hide();
        $this->prefabReferenceModal->hide();
        $this->uiElementReferenceModal->hide();
        $this->activePathInputControl = null;
        $this->activePrefabReferenceControl = null;
        $this->activeUIElementReferenceControl = null;
        $this->controlBindings = [];
        $this->controlMetadata = [];
        $this->pendingHierarchyMutation = null;
        $this->pendingPrefabMutation = null;
        $this->pendingAssetMutation = null;
        $this->componentMenuDefinitions = [];
        $this->isComponentMoveModeActive = false;
        $this->pendingComponentDeletionIndex = null;
        $this->prefabReferenceOptions = [];
        $this->uiElementReferenceOptions = [];

        if ($target === null) {
            $this->content = [];
            $this->lineKinds = [];
            $this->lineStates = [];
            return;
        }

        $context = $target['context'] ?? null;
        $value = $target['value'] ?? null;

        if ($context === 'prefab' && is_array($value)) {
            $this->buildPrefabControls($target, $value);
        } elseif ($context === 'hierarchy' && is_array($value)) {
            $this->buildHierarchyControls($target, $value);
        } elseif ($context === 'scene' && is_array($value)) {
            $this->buildSceneControls($target, $value);
        } elseif ($context === 'asset' && is_array($value)) {
            $this->buildAssetControls($target, $value);
        } else {
            $this->buildGenericControls($target);
        }

        if ($this->focusableControls !== []) {
            $this->selectedControlIndex = 0;
            $this->applyControlSelection();
        }

        if ($preserveSelectedControl) {
            $this->restoreSelectedControlSnapshot($selectedControlSnapshot);
        }

        $this->refreshContent();
    }

    public function getInspectionTarget(): ?array
    {
        return $this->inspectionTarget;
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
        $this->lastClickedControlIndex = null;
        $this->lastClickedControlAt = 0.0;
        parent::blur($context);
        $this->refreshContent();
    }

    public function hasActiveModal(): bool
    {
        return $this->pathInputActionModal->isVisible()
            || $this->fileDialogModal->isVisible()
            || $this->addComponentModal->isVisible()
            || $this->deleteComponentModal->isVisible()
            || $this->prefabReferenceModal->isVisible()
            || $this->uiElementReferenceModal->isVisible();
    }

    public function isModalDirty(): bool
    {
        return $this->pathInputActionModal->isDirty()
            || $this->fileDialogModal->isDirty()
            || $this->addComponentModal->isDirty()
            || $this->deleteComponentModal->isDirty()
            || $this->prefabReferenceModal->isDirty()
            || $this->uiElementReferenceModal->isDirty();
    }

    public function markModalClean(): void
    {
        $this->pathInputActionModal->markClean();
        $this->fileDialogModal->markClean();
        $this->addComponentModal->markClean();
        $this->deleteComponentModal->markClean();
        $this->prefabReferenceModal->markClean();
        $this->uiElementReferenceModal->markClean();
    }

    public function syncModalLayout(int $terminalWidth, int $terminalHeight): void
    {
        $this->pathInputActionModal->syncLayout($terminalWidth, $terminalHeight);
        $this->fileDialogModal->syncLayout($terminalWidth, $terminalHeight);
        $this->addComponentModal->syncLayout($terminalWidth, $terminalHeight);
        $this->deleteComponentModal->syncLayout($terminalWidth, $terminalHeight);
        $this->prefabReferenceModal->syncLayout($terminalWidth, $terminalHeight);
        $this->uiElementReferenceModal->syncLayout($terminalWidth, $terminalHeight);
    }

    public function renderActiveModal(): void
    {
        if ($this->pathInputActionModal->isVisible()) {
            $this->pathInputActionModal->render();
        }

        if ($this->fileDialogModal->isVisible()) {
            $this->fileDialogModal->render();
        }

        if ($this->addComponentModal->isVisible()) {
            $this->addComponentModal->render();
        }

        if ($this->deleteComponentModal->isVisible()) {
            $this->deleteComponentModal->render();
        }

        if ($this->prefabReferenceModal->isVisible()) {
            $this->prefabReferenceModal->render();
        }

        if ($this->uiElementReferenceModal->isVisible()) {
            $this->uiElementReferenceModal->render();
        }
    }

    public function handleModalMouseEvent(MouseEvent $mouseEvent): bool
    {
        if ($this->addComponentModal->isVisible()) {
            if ($this->addComponentModal->handleScrollbarMouseEvent($mouseEvent)) {
                return true;
            }

            $isWithinModal = $this->addComponentModal->containsPoint($mouseEvent->x, $mouseEvent->y);

            if ($mouseEvent->buttonIndex !== 0 || $mouseEvent->action !== 'Pressed') {
                return $isWithinModal;
            }

            $selection = $this->addComponentModal->clickOptionAtPoint($mouseEvent->x, $mouseEvent->y);

            if (is_string($selection) && $selection !== '') {
                $this->applyAddComponentSelection($selection);
            }

            return $isWithinModal;
        }

        if ($this->deleteComponentModal->isVisible()) {
            if ($this->deleteComponentModal->handleScrollbarMouseEvent($mouseEvent)) {
                return true;
            }

            $isWithinModal = $this->deleteComponentModal->containsPoint($mouseEvent->x, $mouseEvent->y);

            if ($mouseEvent->buttonIndex !== 0 || $mouseEvent->action !== 'Pressed') {
                return $isWithinModal;
            }

            $selection = $this->deleteComponentModal->clickOptionAtPoint($mouseEvent->x, $mouseEvent->y);

            if (is_string($selection) && $selection !== '') {
                $this->applyDeleteComponentSelection($selection);
            }

            return $isWithinModal;
        }

        if ($this->prefabReferenceModal->isVisible()) {
            if ($this->prefabReferenceModal->handleScrollbarMouseEvent($mouseEvent)) {
                return true;
            }

            $isWithinModal = $this->prefabReferenceModal->containsPoint($mouseEvent->x, $mouseEvent->y);

            if ($mouseEvent->buttonIndex !== 0 || $mouseEvent->action !== 'Pressed') {
                return $isWithinModal;
            }

            $selection = $this->prefabReferenceModal->clickOptionAtPoint($mouseEvent->x, $mouseEvent->y);

            if (is_string($selection) && $selection !== '') {
                $this->applyPrefabReferenceSelection($selection);
            }

            return $isWithinModal;
        }

        if ($this->uiElementReferenceModal->isVisible()) {
            if ($this->uiElementReferenceModal->handleScrollbarMouseEvent($mouseEvent)) {
                return true;
            }

            $isWithinModal = $this->uiElementReferenceModal->containsPoint($mouseEvent->x, $mouseEvent->y);

            if ($mouseEvent->buttonIndex !== 0 || $mouseEvent->action !== 'Pressed') {
                return $isWithinModal;
            }

            $selection = $this->uiElementReferenceModal->clickOptionAtPoint($mouseEvent->x, $mouseEvent->y);

            if (is_string($selection) && $selection !== '') {
                $this->applyUIElementReferenceSelection($selection);
            }

            return $isWithinModal;
        }

        if ($this->pathInputActionModal->isVisible()) {
            if ($this->pathInputActionModal->handleScrollbarMouseEvent($mouseEvent)) {
                return true;
            }

            $isWithinModal = $this->pathInputActionModal->containsPoint($mouseEvent->x, $mouseEvent->y);

            if ($mouseEvent->buttonIndex !== 0 || $mouseEvent->action !== 'Pressed') {
                return $isWithinModal;
            }

            $selection = $this->pathInputActionModal->clickOptionAtPoint($mouseEvent->x, $mouseEvent->y);

            if (is_string($selection) && $selection !== '') {
                $this->applyPathInputActionSelection($selection);
            }

            return $isWithinModal;
        }

        if ($this->fileDialogModal->isVisible()) {
            if ($this->fileDialogModal->handleScrollbarMouseEvent($mouseEvent)) {
                return true;
            }

            $isWithinModal = $this->fileDialogModal->containsPoint($mouseEvent->x, $mouseEvent->y);

            if ($mouseEvent->buttonIndex !== 0 || $mouseEvent->action !== 'Pressed') {
                return $isWithinModal;
            }

            $selectedPath = $this->fileDialogModal->clickEntryAtPoint($mouseEvent->x, $mouseEvent->y);

            if (is_string($selectedPath) && $selectedPath !== '') {
                $this->applyPathInputFileSelection($selectedPath);
            }

            return $isWithinModal;
        }

        return false;
    }

    public function consumeHierarchyMutation(): ?array
    {
        $pendingHierarchyMutation = $this->pendingHierarchyMutation;
        $this->pendingHierarchyMutation = null;

        return $pendingHierarchyMutation;
    }

    public function consumePrefabMutation(): ?array
    {
        $pendingPrefabMutation = $this->pendingPrefabMutation;
        $this->pendingPrefabMutation = null;

        return $pendingPrefabMutation;
    }

    public function consumeAssetMutation(): ?array
    {
        $pendingAssetMutation = $this->pendingAssetMutation;
        $this->pendingAssetMutation = null;

        return $pendingAssetMutation;
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

        $selectedControl = $this->getSelectedControl();
        $selectedControlMetadata = $this->getSelectedControlMetadata($selectedControl);
        $selectedComponentIndex = is_int($selectedControlMetadata['componentIndex'] ?? null)
            ? $selectedControlMetadata['componentIndex']
            : null;
        $shouldPreserveComponentMoveMode = $this->isComponentMoveModeActive
            && $this->isSelectedComponentHeader($selectedControl);
        $selectedControlSnapshot = $this->captureSelectedControlSnapshot($selectedControl);

        $target = $this->inspectionTarget;
        $target['name'] = $value['name'] ?? ($target['name'] ?? 'Unnamed Object');
        $target['type'] = $this->resolveDisplayType($target, $value);
        $target['value'] = $value;

        $this->inspectTarget($target);

        if (is_int($selectedComponentIndex)) {
            $componentCount = is_array($value['components'] ?? null)
                ? count($value['components'])
                : 0;

            if ($componentCount > 0) {
                $this->focusComponentHeaderByIndex(min($selectedComponentIndex, $componentCount - 1));
            }
        } else {
            $this->restoreSelectedControlSnapshot($selectedControlSnapshot);
        }

        if ($shouldPreserveComponentMoveMode) {
            $selectedControl = $this->getSelectedControl();
            $this->isComponentMoveModeActive = $this->isSelectedComponentHeader($selectedControl);
        }
    }

    public function syncSceneTarget(array $value): void
    {
        if (
            !is_array($this->inspectionTarget)
            || ($this->inspectionTarget['context'] ?? null) !== 'scene'
        ) {
            return;
        }

        $selectedControlSnapshot = $this->captureSelectedControlSnapshot($this->getSelectedControl());
        $target = $this->inspectionTarget;
        $target['name'] = $value['name'] ?? ($target['name'] ?? 'Scene');
        $target['type'] = 'Scene';
        $target['path'] = 'scene';
        $target['value'] = $value;

        $this->inspectTarget($target);
        $this->restoreSelectedControlSnapshot($selectedControlSnapshot);
    }

    public function syncAssetTarget(array $value): void
    {
        if (
            !is_array($this->inspectionTarget)
            || ($this->inspectionTarget['context'] ?? null) !== 'asset'
        ) {
            return;
        }

        $selectedControlSnapshot = $this->captureSelectedControlSnapshot($this->getSelectedControl());
        $target = $this->inspectionTarget;
        $target['name'] = $value['name'] ?? ($target['name'] ?? 'Unnamed Asset');
        $target['type'] = ($value['isDirectory'] ?? false) ? 'Folder' : 'File';
        $target['value'] = $value;

        $this->inspectTarget($target);
        $this->restoreSelectedControlSnapshot($selectedControlSnapshot);
    }

    public function consumeModalBackgroundRefreshRequest(): bool
    {
        $shouldRefreshModalBackground = $this->shouldRefreshModalBackground;
        $this->shouldRefreshModalBackground = false;

        return $shouldRefreshModalBackground;
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

        if ($this->addComponentModal->isVisible()) {
            $this->handleAddComponentModalInput();
            return;
        }

        if ($this->deleteComponentModal->isVisible()) {
            $this->handleDeleteComponentModalInput();
            return;
        }

        if ($this->prefabReferenceModal->isVisible()) {
            $this->handlePrefabReferenceModalInput();
            return;
        }

        if ($this->uiElementReferenceModal->isVisible()) {
            $this->handleUIElementReferenceModalInput();
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
        $contentIndex = $this->getContentIndexForLineIndex($lineIndex);

        if (!is_int($contentIndex)) {
            return parent::decorateContentLine($line, $contentColor, $lineIndex);
        }

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

    protected function buildBorderLine(string $label, bool $isTopBorder): string
    {
        if ($isTopBorder) {
            return parent::buildBorderLine($label, true);
        }

        return $this->buildSplitHelpBorder($this->help, $this->modeHelpLabel);
    }

    private function decorateSectionHeaderLine(string $line, ?Color $contentColor, int $lineIndex): string
    {
        $contentIndex = $this->getContentIndexForLineIndex($lineIndex);

        if (!is_int($contentIndex)) {
            return parent::decorateContentLine($line, $contentColor, $lineIndex);
        }

        $lineState = $this->lineStates[$contentIndex] ?? 'normal';
        $visibleLine = mb_substr($line, 0, $this->width);
        $visibleLength = mb_strlen($visibleLine);

        if ($visibleLength <= 1) {
            return parent::decorateContentLine($line, $contentColor, $lineIndex);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleLength > 2 ? mb_substr($visibleLine, 1, $visibleLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);
        $borderColor = $this->hasFocus() ? $this->focusBorderColor : $contentColor;
        $selectedControl = $this->getSelectedControl();
        $sectionSequence = match (true) {
            $lineState === 'selected'
                && $this->hasFocus()
                && $this->isComponentMoveModeActive
                && $this->isSelectedComponentHeader($selectedControl) => self::EDITING_CONTROL_ACTIVE_SEQUENCE,
            $lineState === 'selected'
                && $this->isComponentMoveModeActive
                && $this->isSelectedComponentHeader($selectedControl) => self::EDITING_CONTROL_SEQUENCE,
            $lineState === 'selected' && $this->hasFocus() => self::SECTION_HEADER_SELECTED_SEQUENCE,
            default => self::SECTION_HEADER_SEQUENCE,
        };

        return $this->wrapWithColor($leftBorder, $borderColor)
            . $this->wrapWithSequence($middle, $sectionSequence)
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

        $this->addControl($this->addSectionHeader('Transform'));
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

        $sizeControl = null;

        if (isset($item['size']) && is_array($item['size'])) {
            $sizeControl = new VectorInputControl('Size', $this->resolveInspectableSize($item), 1);
            $this->addBoundControl($sizeControl, ['size']);
        }

        if ($this->isGuiTextureItem($item)) {
            $this->addControl($this->addSectionHeader('Texture'));
            $this->addGuiTextureControls($item, $sizeControl instanceof VectorInputControl ? $sizeControl : null);
        } else {
            $this->addControl($this->addSectionHeader('Renderer'));
            $this->addRendererControls($item);
        }

        $this->addScriptComponents($item['components'] ?? []);
    }

    private function buildPrefabControls(array $target, array $item): void
    {
        $asset = is_array($target['asset'] ?? null) ? $target['asset'] : [];
        $assetName = $asset['name'] ?? basename((string) ($asset['path'] ?? ''));

        $this->addControl(new TextInputControl('Type', $this->resolveDisplayType($target, $item), 0, true));
        $this->addControl(
            new TextInputControl('File Name', $assetName, 0),
            ['kind' => 'prefab_file_name'],
        );
        $this->addBoundControl(
            new TextInputControl('Name', $item['name'] ?? $target['name'] ?? 'Unnamed Object', 0),
            ['name'],
        );
        $this->addBoundControl(
            new TextInputControl('Tag', $item['tag'] ?? 'None', 0),
            ['tag'],
        );

        $this->addControl($this->addSectionHeader('Transform'));
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

        $sizeControl = null;

        if (isset($item['size']) && is_array($item['size'])) {
            $sizeControl = new VectorInputControl('Size', $this->resolveInspectableSize($item), 1);
            $this->addBoundControl($sizeControl, ['size']);
        }

        if ($this->isGuiTextureItem($item)) {
            $this->addControl($this->addSectionHeader('Texture'));
            $this->addGuiTextureControls($item, $sizeControl instanceof VectorInputControl ? $sizeControl : null);
        } else {
            $this->addControl($this->addSectionHeader('Renderer'));
            $this->addRendererControls($item);
        }

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
                'Map',
                $scene['environmentTileMapPath'] ?? 'Maps/example',
                $this->resolveAssetsWorkingDirectory(),
                ['tmap'],
                0,
            ),
            ['environmentTileMapPath'],
        );
        $this->addBoundControl(
            new PathInputControl(
                'Collider',
                $scene['environmentCollisionMapPath'] ?? '',
                $this->resolveAssetsWorkingDirectory(),
                ['tmap'],
                0
            ),
            ['environmentCollisionMapPath']
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

    private function buildAssetControls(array $target, array $asset): void
    {
        $isDirectory = (bool) ($asset['isDirectory'] ?? false);
        $assetName = $asset['name'] ?? $target['name'] ?? 'Unnamed Asset';
        $assetPath = is_string($asset['path'] ?? null) ? $asset['path'] : '';

        $this->addControl(new TextInputControl('Type', $isDirectory ? 'Folder' : 'File', 0, true));

        if ($isDirectory) {
            $this->addControl(new TextInputControl('Name', $assetName, 0, true));
        } else {
            $this->addBoundControl(
                new TextInputControl('Name', $assetName, 0),
                ['name'],
            );
        }

        $this->addControl(new TextInputControl('Path', $assetPath, 0, true));
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

        $textureControl = new PathInputControl(
            'Texture',
            $texturePath,
            $this->resolveAssetsWorkingDirectory(),
            ['texture'],
            1,
        );
        $offsetControl = new VectorInputControl('Offset', $offset, 1);
        $sizeControl = new VectorInputControl('Size', $size, 1);
        $previewControl = new PreviewWindowControl(
            'Preview',
            $this->buildTexturePreviewLines($texturePath, $offset, $size, true),
            1,
        );

        $this->addBoundControl($textureControl, ['sprite', 'texture', 'path']);
        $this->addBoundControl($offsetControl, ['sprite', 'texture', 'position']);
        $this->addBoundControl($sizeControl, ['sprite', 'texture', 'size']);
        $this->addControl($previewControl);
        $this->registerTexturePreview($textureControl, $sizeControl, $previewControl, $offsetControl, true);

        if (array_key_exists('text', $item)) {
            $this->addBoundControl(new TextInputControl('Text', $item['text'], 1), ['text']);
        }
    }

    private function addGuiTextureControls(array $item, ?VectorInputControl $sizeControl = null): void
    {
        $texturePath = is_string($item['texture'] ?? null) && trim($item['texture']) !== ''
            ? trim((string) $item['texture'])
            : 'None';
        $colorOptions = $this->resolveGuiTextureColorOptions();
        $selectedColor = $this->normalizeGuiTextureColor($item['color'] ?? null);
        $resolvedSizeControl = $sizeControl ?? new VectorInputControl('Size', $this->normalizeGuiTextureSize($this->normalizeVector($item['size'] ?? null)), 1);
        $textureControl = new PathInputControl(
            'Texture',
            $texturePath,
            $this->resolveAssetsWorkingDirectory(),
            ['texture'],
            1,
        );
        $previewControl = new PreviewWindowControl(
            'Preview',
            $this->buildTexturePreviewLines($texturePath, ['x' => 0, 'y' => 0], $resolvedSizeControl->getValue(), false),
            1,
        );

        $this->addBoundControl($textureControl, ['texture']);
        $this->addBoundControl(
            new SelectInputControl(
                'Color',
                $colorOptions,
                in_array($selectedColor, $colorOptions, true) ? $selectedColor : EngineColor::WHITE->getPhoneticName(),
                1,
            ),
            ['color'],
        );
        $this->addControl($previewControl);
        $this->registerTexturePreview($textureControl, $resolvedSizeControl, $previewControl, null, false);
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

            $serializedComponentData = is_array($component['data'] ?? null) ? $component['data'] : null;
            $componentFieldTypes = is_array($component['__editorFieldTypes'] ?? null)
                ? $component['__editorFieldTypes']
                : [];
            $componentFieldSchemas = $this->resolveComponentFieldSchemas(
                is_string($component['class'] ?? null) ? $component['class'] : null,
                $componentFieldTypes,
                $serializedComponentData ?? [],
            );

            if (is_array($serializedComponentData)) {
                $this->addControl(
                    $this->addSectionHeader(
                        $this->resolveClassName($component['class'] ?? null, 'Component'),
                    ),
                    [
                        'kind' => 'component_header',
                        'componentIndex' => $componentIndex,
                    ],
                );
                $this->addComponentPropertyControls(
                    $serializedComponentData,
                    ['components', $componentIndex, 'data'],
                    1,
                    $componentFieldSchemas,
                );
                continue;
            }

            $legacyComponentData = array_filter(
                $component,
                static fn(string $key): bool => $key !== 'class',
                ARRAY_FILTER_USE_KEY,
            );

            $this->addControl(
                $this->addSectionHeader(
                    $this->resolveClassName($component['class'] ?? null, 'Component'),
                ),
                [
                    'kind' => 'component_header',
                    'componentIndex' => $componentIndex,
                ],
            );

            if (!is_array($legacyComponentData) || $legacyComponentData === []) {
                continue;
            }

            $this->addComponentPropertyControls(
                $legacyComponentData,
                ['components', $componentIndex],
                1,
                $componentFieldSchemas,
            );
        }
    }

    private function addComponentPropertyControls(
        array $properties,
        array $basePath,
        int $indentLevel = 1,
        array $fieldSchemas = [],
    ): void
    {
        foreach ($properties as $key => $value) {
            $propertySchema = is_array($fieldSchemas[$key] ?? null)
                ? $fieldSchemas[$key]
                : [];
            $label = is_string($key)
                ? $this->humanizeKey($key)
                : 'Item ' . (((int) $key) + 1);

            if ($this->shouldRenderNestedComponentProperties($value, $propertySchema)) {
                $this->addControl($this->addSectionHeader(
                    $label,
                    $indentLevel,
                ));
                $this->addComponentPropertyControls(
                    is_array($value) ? $value : [],
                    [...$basePath, $key],
                    $indentLevel + 1,
                    $this->resolveNestedComponentFieldSchemas($propertySchema, $value),
                );
                continue;
            }

            $this->addBoundControl(
                $this->buildComponentPropertyControl(
                    $label,
                    $value,
                    $indentLevel,
                    $propertySchema,
                ),
                [...$basePath, $key],
                $this->buildComponentControlMetadata($propertySchema),
            );
        }
    }

    private function buildComponentPropertyControl(
        string $label,
        mixed $value,
        int $indentLevel,
        array $fieldSchema = [],
    ): InputControl {
        $fieldType = $this->resolveFieldSchemaType($fieldSchema);
        $range = is_array($fieldSchema['range'] ?? null) ? $fieldSchema['range'] : null;

        if ($range !== null && $this->isNumericFieldSchema($fieldSchema, $value)) {
            return new SliderInputControl(
                $label,
                $value,
                $range['min'] ?? 0,
                $range['max'] ?? 0,
                $range['step'] ?? 1,
                $indentLevel,
            );
        }

        if ($this->isPrefabAssignableGameObjectField($fieldType)) {
            return new PrefabReferenceInputControl(
                $label,
                $value,
                $this->resolvePrefabDisplayLabelsByPath(),
                $indentLevel,
            );
        }

        $uiElementFieldType = $this->resolveAssignableUIElementFieldType($fieldType);

        if (is_string($uiElementFieldType)) {
            return new UIElementReferenceInputControl(
                $label,
                $value,
                $this->resolveUIElementDisplayLabelsByName($uiElementFieldType),
                $indentLevel,
            );
        }

        if ($this->isTextureAssignableField($fieldType)) {
            return new PathInputControl(
                $label,
                $this->normalizeTextureComponentFieldValue($value),
                $this->resolveAssetsWorkingDirectory(),
                ['texture'],
                $indentLevel,
            );
        }

        return $this->inputControlFactory->createForFieldType($label, $value, $fieldType, $indentLevel);
    }

    private function isPrefabAssignableGameObjectField(?string $fieldType): bool
    {
        if (!is_string($fieldType) || trim($fieldType) === '') {
            return false;
        }

        $normalizedTypes = array_map(
            static fn(string $type): string => ltrim(trim($type), '\\'),
            explode('|', $fieldType),
        );

        foreach ($normalizedTypes as $normalizedType) {
            if (in_array($normalizedType, [
                'Sendama\\Engine\\Core\\GameObject',
                'Sendama\\Engine\\Core\\Interfaces\\GameObjectInterface',
            ], true)) {
                return true;
            }
        }

        return false;
    }

    private function resolveAssignableUIElementFieldType(?string $fieldType): ?string
    {
        if (!is_string($fieldType) || trim($fieldType) === '') {
            return null;
        }

        $normalizedTypes = array_map(
            static fn(string $type): string => ltrim(trim($type), '\\'),
            explode('|', $fieldType),
        );

        foreach ($normalizedTypes as $normalizedType) {
            if ($normalizedType === '' || $normalizedType === 'null') {
                continue;
            }

            if (in_array($normalizedType, [self::UI_ELEMENT_TYPE, self::UI_ELEMENT_INTERFACE_TYPE], true)) {
                return self::UI_ELEMENT_TYPE;
            }

            if ($this->isKnownEngineUIElementType($normalizedType)) {
                return $normalizedType;
            }

            if ((class_exists($normalizedType) || interface_exists($normalizedType)) && is_a($normalizedType, self::UI_ELEMENT_TYPE, true)) {
                return $normalizedType;
            }
        }

        return null;
    }

    private function isTextureAssignableField(?string $fieldType): bool
    {
        if (!is_string($fieldType) || trim($fieldType) === '') {
            return false;
        }

        $normalizedTypes = array_map(
            static fn(string $type): string => ltrim(trim($type), '\\'),
            explode('|', $fieldType),
        );

        return in_array('Sendama\\Engine\\Core\\Texture', $normalizedTypes, true);
    }

    private function shouldRenderNestedComponentProperties(mixed $value, array $fieldSchema = []): bool
    {
        $hasNestedSchema = is_array($fieldSchema['properties'] ?? null)
            || is_array($fieldSchema['item'] ?? null);

        if (!is_array($value)) {
            return false;
        }

        if ($value === []) {
            return $hasNestedSchema;
        }

        if ($this->isVectorValue($value)) {
            return false;
        }

        if (array_is_list($value)) {
            return true;
        }

        return $hasNestedSchema || !$this->isScalarListValue($value);
    }

    private function resolveComponentFieldSchemas(
        ?string $componentClass,
        array $fieldTypes,
        array $properties,
    ): array {
        $schemas = [];
        $reflection = $this->reflectProjectClass($componentClass);

        foreach ($properties as $propertyName => $propertyValue) {
            $fallbackType = is_string($fieldTypes[$propertyName] ?? null)
                ? $fieldTypes[$propertyName]
                : null;

            if (
                $reflection instanceof ReflectionClass
                && is_string($propertyName)
                && $reflection->hasProperty($propertyName)
            ) {
                try {
                    $property = $reflection->getProperty($propertyName);
                    $schemas[$propertyName] = $this->resolveComponentPropertyFieldSchema(
                        $property,
                        $propertyValue,
                        $fallbackType,
                    );
                    continue;
                } catch (Throwable) {
                }
            }

            $schemas[$propertyName] = $this->buildComponentFieldSchema($fallbackType, $propertyValue);
        }

        return $schemas;
    }

    private function resolveComponentPropertyFieldSchema(
        ReflectionProperty $property,
        mixed $currentValue,
        ?string $fallbackType = null,
    ): array {
        $resolvedType = $this->resolveReflectionPropertyType($property) ?? $fallbackType;
        $schema = $this->buildComponentFieldSchema(
            $resolvedType,
            $currentValue,
            $property->getDeclaringClass(),
        );
        $range = $this->resolveRangeAttributeMetadata($property);

        if ($range !== null) {
            $schema['range'] = $range;
        }

        $collectionItemType = $this->resolveCollectionItemFieldType($property);

        if ($collectionItemType !== null) {
            $schema['item'] = $this->buildComponentFieldSchema(
                $collectionItemType,
                $this->resolveRepresentativeCollectionValue($currentValue),
                $property->getDeclaringClass(),
            );
        }

        return $schema;
    }

    private function buildComponentFieldSchema(
        ?string $fieldType,
        mixed $currentValue,
        ?ReflectionClass $scope = null,
    ): array {
        $schema = [];

        if (is_string($fieldType) && trim($fieldType) !== '') {
            $schema['type'] = $fieldType;
        }

        $primaryType = $this->resolvePrimaryFieldTypeName($fieldType);

        if ($primaryType !== null && $this->isCompoundStructureType($primaryType)) {
            $schema['properties'] = $this->resolveCompoundStructureFieldSchemas(
                $primaryType,
                is_array($currentValue) ? $currentValue : [],
            );
        }

        if (
            !isset($schema['item'])
            && is_array($currentValue)
            && array_is_list($currentValue)
            && $currentValue !== []
        ) {
            $schema['item'] = $this->buildComponentFieldSchema(
                null,
                $this->resolveRepresentativeCollectionValue($currentValue),
                $scope,
            );
        }

        return $schema;
    }

    private function resolveNestedComponentFieldSchemas(array $fieldSchema, mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if (array_is_list($value)) {
            $itemSchema = is_array($fieldSchema['item'] ?? null)
                ? $fieldSchema['item']
                : [];

            if ($itemSchema === []) {
                return [];
            }

            $schemas = [];

            foreach (array_keys($value) as $index) {
                $schemas[$index] = $itemSchema;
            }

            return $schemas;
        }

        return is_array($fieldSchema['properties'] ?? null)
            ? $fieldSchema['properties']
            : [];
    }

    private function buildComponentControlMetadata(array $fieldSchema): array
    {
        if ($fieldSchema === []) {
            return [];
        }

        $metadata = [
            'fieldSchema' => $fieldSchema,
        ];
        $fieldType = $this->resolveFieldSchemaType($fieldSchema);

        if ($fieldType !== null) {
            $metadata['fieldType'] = $fieldType;
        }

        return $metadata;
    }

    private function resolveFieldSchemaType(array $fieldSchema): ?string
    {
        $fieldType = $fieldSchema['type'] ?? null;

        return is_string($fieldType) && trim($fieldType) !== ''
            ? $fieldType
            : null;
    }

    private function isNumericFieldSchema(array $fieldSchema, mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        $fieldType = $this->resolveFieldSchemaType($fieldSchema);

        if (!is_string($fieldType) || trim($fieldType) === '') {
            return false;
        }

        $normalizedTypes = array_map(
            static fn(string $type): string => strtolower(trim($type)),
            explode('|', $fieldType),
        );

        return in_array('int', $normalizedTypes, true) || in_array('float', $normalizedTypes, true);
    }

    private function resolveRepresentativeCollectionValue(mixed $value): mixed
    {
        if (!is_array($value) || $value === []) {
            return null;
        }

        foreach ($value as $item) {
            return $item;
        }

        return null;
    }

    private function resolvePrimaryFieldTypeName(?string $fieldType): ?string
    {
        if (!is_string($fieldType) || trim($fieldType) === '') {
            return null;
        }

        foreach (explode('|', $fieldType) as $candidateType) {
            $normalizedType = ltrim(trim($candidateType), '\\');

            if ($normalizedType === '' || strtolower($normalizedType) === 'null') {
                continue;
            }

            return $normalizedType;
        }

        return null;
    }

    private function resolveCompoundStructureFieldSchemas(string $typeName, array $currentValue): array
    {
        $reflection = $this->reflectProjectClass($typeName);

        if (!$reflection instanceof ReflectionClass) {
            return [];
        }

        $schemas = [];

        foreach ($reflection->getProperties() as $property) {
            if (
                $property->isStatic()
                || !$this->isSerializableComponentProperty($property)
                || (method_exists($property, 'isVirtual') && $property->isVirtual())
            ) {
                continue;
            }

            $propertyName = $property->getName();
            $schemas[$propertyName] = $this->resolveComponentPropertyFieldSchema(
                $property,
                $currentValue[$propertyName] ?? null,
            );
        }

        return $schemas;
    }

    private function isCompoundStructureType(string $typeName): bool
    {
        $normalizedType = ltrim(trim($typeName), '\\');

        if ($normalizedType === '' || $this->isBuiltinTypeName($normalizedType)) {
            return false;
        }

        if (
            enum_exists($normalizedType)
            || interface_exists($normalizedType)
            || !class_exists($normalizedType)
        ) {
            return false;
        }

        if (in_array($normalizedType, [
            'Sendama\\Engine\\Core\\GameObject',
            self::UI_ELEMENT_TYPE,
            self::UI_ELEMENT_INTERFACE_TYPE,
            'Sendama\\Engine\\Core\\Vector2',
            'Sendama\\Engine\\Core\\Rect',
            'Sendama\\Engine\\Core\\Texture',
            'Sendama\\Engine\\Core\\Sprite',
        ], true)) {
            return false;
        }

        if (
            is_a($normalizedType, 'Sendama\\Engine\\Core\\Component', true)
            || is_a($normalizedType, 'Sendama\\Engine\\Core\\GameObject', true)
            || is_a($normalizedType, self::UI_ELEMENT_TYPE, true)
        ) {
            return false;
        }

        return true;
    }

    private function isBuiltinTypeName(string $typeName): bool
    {
        return in_array(strtolower($typeName), [
            'array',
            'bool',
            'callable',
            'false',
            'float',
            'int',
            'iterable',
            'mixed',
            'never',
            'null',
            'object',
            'self',
            'static',
            'string',
            'true',
        ], true);
    }

    private function resolveCollectionItemFieldType(ReflectionProperty $property): ?string
    {
        $docComment = $property->getDocComment();

        if (!is_string($docComment) || $docComment === '') {
            return null;
        }

        if (preg_match('/@var\s+([^\s]+)/', $docComment, $matches) !== 1) {
            return null;
        }

        $typeExpression = trim($matches[1]);
        $collectionItemType = $this->extractCollectionItemTypeExpression($typeExpression);

        if ($collectionItemType === null) {
            return null;
        }

        return $this->resolveDocblockTypeReference(
            $property->getDeclaringClass(),
            $collectionItemType,
        );
    }

    private function extractCollectionItemTypeExpression(string $typeExpression): ?string
    {
        $normalizedExpression = trim($typeExpression);

        if ($normalizedExpression === '') {
            return null;
        }

        $unionMembers = array_values(array_filter(array_map('trim', explode('|', $normalizedExpression))));

        foreach ($unionMembers as $unionMember) {
            if (strtolower($unionMember) === 'null') {
                continue;
            }

            if (preg_match('/^(.+)\[\]$/', $unionMember, $matches) === 1) {
                return trim($matches[1]);
            }

            if (preg_match('/^(?:array|list)<(.+)>$/', $unionMember, $matches) === 1) {
                $innerType = trim($matches[1]);
                $segments = array_values(array_filter(array_map('trim', explode(',', $innerType))));

                return $segments === [] ? null : end($segments);
            }
        }

        return null;
    }

    private function resolveDocblockTypeReference(ReflectionClass $scope, string $typeReference): ?string
    {
        $normalizedTypeReference = trim($typeReference);

        if ($normalizedTypeReference === '') {
            return null;
        }

        if ($normalizedTypeReference[0] === '\\') {
            return ltrim($normalizedTypeReference, '\\');
        }

        if ($this->isBuiltinTypeName($normalizedTypeReference)) {
            return strtolower($normalizedTypeReference);
        }

        if (str_contains($normalizedTypeReference, '\\')) {
            return ltrim($normalizedTypeReference, '\\');
        }

        $importAliases = $this->resolveClassImportAliases($scope);
        $normalizedAlias = strtolower($normalizedTypeReference);

        if (isset($importAliases[$normalizedAlias])) {
            return $importAliases[$normalizedAlias];
        }

        $namespace = $scope->getNamespaceName();

        return $namespace !== ''
            ? $namespace . '\\' . $normalizedTypeReference
            : $normalizedTypeReference;
    }

    private function resolveClassImportAliases(ReflectionClass $scope): array
    {
        $scopeName = $scope->getName();

        if (array_key_exists($scopeName, $this->classImportAliasCache)) {
            return $this->classImportAliasCache[$scopeName];
        }

        $fileName = $scope->getFileName();

        if (!is_string($fileName) || !is_file($fileName)) {
            return $this->classImportAliasCache[$scopeName] = [];
        }

        $source = file_get_contents($fileName);

        if (!is_string($source) || $source === '') {
            return $this->classImportAliasCache[$scopeName] = [];
        }

        $aliases = [];

        if (preg_match_all('/^\s*use\s+([^;]+);/mi', $source, $matches) === 1 || count($matches[1] ?? []) > 0) {
            foreach ($matches[1] as $importClause) {
                if (!is_string($importClause) || str_contains($importClause, '{')) {
                    continue;
                }

                $normalizedClause = trim($importClause);
                $alias = basename(str_replace('\\', '/', $normalizedClause));
                $typeReference = $normalizedClause;

                if (preg_match('/^(.+)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $normalizedClause, $aliasMatches) === 1) {
                    $typeReference = trim($aliasMatches[1]);
                    $alias = trim($aliasMatches[2]);
                }

                $aliases[strtolower($alias)] = ltrim($typeReference, '\\');
            }
        }

        return $this->classImportAliasCache[$scopeName] = $aliases;
    }

    private function resolveRangeAttributeMetadata(ReflectionProperty $property): ?array
    {
        $attributes = $property->getAttributes('Sendama\\Engine\\Core\\Attributes\\Range');

        if ($attributes === []) {
            return null;
        }

        try {
            $attribute = $attributes[0]->newInstance();
            $minimum = $attribute->min ?? null;
            $maximum = $attribute->max ?? null;
            $step = $attribute->step ?? 1;
        } catch (Throwable) {
            return null;
        }

        if (!is_int($minimum) && !is_float($minimum)) {
            return null;
        }

        if (!is_int($maximum) && !is_float($maximum)) {
            return null;
        }

        if (!is_int($step) && !is_float($step)) {
            $step = 1;
        }

        if ($step == 0) {
            $step = 1;
        }

        if ($minimum > $maximum) {
            [$minimum, $maximum] = [$maximum, $minimum];
        }

        return [
            'min' => $minimum,
            'max' => $maximum,
            'step' => abs($step),
        ];
    }

    private function reflectProjectClass(?string $className): ?ReflectionClass
    {
        if (!is_string($className) || trim($className) === '') {
            return null;
        }

        $this->ensureProjectAutoloadLoaded();
        $normalizedClassName = ltrim(trim($className), '\\');

        if (!class_exists($normalizedClassName)) {
            return null;
        }

        try {
            return new ReflectionClass($normalizedClassName);
        } catch (Throwable) {
            return null;
        }
    }

    private function ensureProjectAutoloadLoaded(): void
    {
        $autoloadPath = Path::join($this->projectDirectory, 'vendor', 'autoload.php');
        ProjectAutoloadLoader::load($autoloadPath);
    }

    private function isVectorValue(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, ['x', 'y', 'z', 'w'], true)) {
                return false;
            }
        }

        foreach ($value as $item) {
            if (!is_scalar($item) && $item !== null) {
                return false;
            }
        }

        return true;
    }

    private function isScalarListValue(array $value): bool
    {
        if (!array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_scalar($item) && $item !== null) {
                return false;
            }
        }

        return true;
    }

    private function addSectionHeader(string $title, int $indentLevel = 0): SectionControl
    {
        return new SectionControl($title, $indentLevel);
    }

    private function addControl(InputControl $control, array $metadata = []): void
    {
        $this->elements[] = [
            'kind' => 'control',
            'control' => $control,
        ];
        $this->focusableControls[] = $control;

        if ($metadata !== []) {
            $this->controlMetadata[spl_object_id($control)] = $metadata;
        }
    }

    private function addBoundControl(InputControl $control, array $valuePath, array $metadata = []): void
    {
        $this->bindControl($control, $valuePath);
        $this->addControl($control, $metadata);
    }

    private function bindControl(InputControl $control, array $valuePath): void
    {
        $this->controlBindings[spl_object_id($control)] = $valuePath;
    }

    private function refreshContent(): void
    {
        $this->updateHelpInfo();
        $this->refreshDerivedControls();
        $content = [];
        $lineKinds = [];
        $lineStates = [];
        $lineControlIndexes = [];
        $collapsedSectionIndentLevels = [];

        foreach ($this->elements as $element) {
            $control = $element['control'] ?? null;

            if (!$control instanceof InputControl) {
                continue;
            }

            $controlIndentLevel = $control->getIndentLevel();

            while (
                $collapsedSectionIndentLevels !== []
                && $controlIndentLevel <= end($collapsedSectionIndentLevels)
            ) {
                array_pop($collapsedSectionIndentLevels);
            }

            if ($collapsedSectionIndentLevels !== []) {
                continue;
            }

            $controlIndex = array_search($control, $this->focusableControls, true);
            $lineControlIndex = is_int($controlIndex) ? $controlIndex : null;
            $control->setAvailableWidth($this->resolveControlRenderWidth());

            foreach ($control->renderLineDefinitions() as $lineDefinition) {
                $content[] = $lineDefinition['text'] ?? '';
                $lineKinds[] = $lineDefinition['kind'] ?? 'control';
                $lineStates[] = $lineDefinition['state'] ?? 'normal';
                $lineControlIndexes[] = $lineControlIndex;
            }

            if ($control instanceof SectionControl && $control->isCollapsed()) {
                $collapsedSectionIndentLevels[] = $controlIndentLevel;
            }
        }

        $this->content = $content;
        $this->lineKinds = $lineKinds;
        $this->lineStates = $lineStates;
        $this->lineControlIndexes = $lineControlIndexes;
        $this->ensureContentLineVisible($this->resolveSelectedContentIndex());
    }

    private function updateHelpInfo(): void
    {
        if ($this->addComponentModal->isVisible()) {
            $this->help = 'Up/Down choose  Enter add  Esc cancel';
            $this->modeHelpLabel = 'Mode: Add Component';
            return;
        }

        if ($this->deleteComponentModal->isVisible()) {
            $this->help = 'Up/Down choose  Enter confirm  Esc cancel';
            $this->modeHelpLabel = 'Mode: Remove Component';
            return;
        }

        if ($this->prefabReferenceModal->isVisible()) {
            $this->help = 'Up/Down choose  Enter assign  Esc cancel';
            $this->modeHelpLabel = 'Mode: Prefab Picker';
            return;
        }

        if ($this->uiElementReferenceModal->isVisible()) {
            $this->help = 'Up/Down choose  Enter assign  Esc cancel';
            $this->modeHelpLabel = 'Mode: UI Element Picker';
            return;
        }

        if ($this->interactionState === self::STATE_PATH_INPUT_ACTION_SELECTION) {
            $this->help = 'Up/Down choose  Enter select  Esc cancel';
            $this->modeHelpLabel = 'Mode: Path Action';
            return;
        }

        if ($this->interactionState === self::STATE_PATH_INPUT_FILE_DIALOG) {
            $this->help = 'Up/Down move  Left/Right tree  Enter choose  Esc back';
            $this->modeHelpLabel = 'Mode: File Picker';
            return;
        }

        if ($this->interactionState === self::STATE_UI_ELEMENT_REFERENCE_SELECTION) {
            $this->help = 'Up/Down choose  Enter assign  Esc cancel';
            $this->modeHelpLabel = 'Mode: UI Element Assign';
            return;
        }

        $selectedControl = $this->getSelectedControl();

        if ($this->interactionState === self::STATE_CONTROL_EDIT) {
            if ($selectedControl instanceof SliderInputControl) {
                $this->help = 'Left/Right adjust  Enter save  Esc cancel';
                $this->modeHelpLabel = 'Mode: Slider Edit';
                return;
            }

            if ($selectedControl instanceof NumberInputControl) {
                $this->help = 'Type edit  Up/Down adjust  Left/Right move  Enter save  Esc cancel';
                $this->modeHelpLabel = 'Mode: Number Edit';
                return;
            }

            if ($selectedControl instanceof TextInputControl || $selectedControl instanceof PathInputControl) {
                $this->help = 'Type edit  Left/Right move  Backspace delete  Enter save  Esc cancel';
                $this->modeHelpLabel = 'Mode: Text Edit';
                return;
            }

            $this->help = 'Edit value  Enter save  Esc cancel';
            $this->modeHelpLabel = 'Mode: Control Edit';
            return;
        }

        if ($this->interactionState === self::STATE_PROPERTY_SELECTION) {
            $this->help = 'Up/Down property  Enter edit  Esc back';
            $this->modeHelpLabel = 'Mode: Property Select';
            return;
        }

        if (
            $this->isComponentMoveModeActive
            && $this->isSelectedComponentHeader($selectedControl)
            && $this->canMutateCurrentComponentList()
        ) {
            $this->help = 'Up/Down reorder  Shift+W done  Esc cancel';
            $this->modeHelpLabel = 'Mode: Component Move';
            return;
        }

        if ($this->isSelectedComponentHeader($selectedControl)) {
            $this->help = $this->canMutateCurrentComponentList()
                ? 'Up/Down select  / toggle  Shift+A add  Shift+W move  Del remove'
                : 'Up/Down select  / toggle  Tab next';
            $this->modeHelpLabel = 'Mode: Control Select';
            return;
        }

        if ($selectedControl instanceof CompoundInputControl) {
            $this->help = 'Up/Down select  Enter properties  Tab next';
            $this->modeHelpLabel = 'Mode: Control Select';
            return;
        }

        if ($selectedControl instanceof PathInputControl) {
            $this->help = 'Up/Down select  Enter path options  Tab next';
            $this->modeHelpLabel = 'Mode: Control Select';
            return;
        }

        if ($selectedControl instanceof PrefabReferenceInputControl) {
            $this->help = 'Up/Down select  Enter choose prefab  Tab next';
            $this->modeHelpLabel = 'Mode: Control Select';
            return;
        }

        if ($selectedControl instanceof UIElementReferenceInputControl) {
            $this->help = 'Up/Down select  Enter choose UI element  Tab next';
            $this->modeHelpLabel = 'Mode: Control Select';
            return;
        }

        $this->help = 'Up/Down select  Enter edit  Shift+A add  Tab next';
        $this->modeHelpLabel = 'Mode: Control Select';
    }

    private function resolveControlRenderWidth(): int
    {
        return max(
            0,
            $this->innerWidth
            - max(0, $this->padding->leftPadding)
            - max(0, $this->padding->rightPadding)
            - 1,
        );
    }

    private function buildSplitHelpBorder(string $leftLabel, string $rightLabel): string
    {
        $availableLabelWidth = max(0, $this->width - 3);
        $visibleRightLabel = $this->clipContentToWidth($rightLabel, $availableLabelWidth);
        $remainingWidth = max(0, $availableLabelWidth - $this->getDisplayWidth($visibleRightLabel));
        $visibleLeftLabel = $this->clipContentToWidth($leftLabel, $remainingWidth);
        $fillerWidth = max(
            0,
            $availableLabelWidth - $this->getDisplayWidth($visibleLeftLabel) - $this->getDisplayWidth($visibleRightLabel),
        );

        return $this->borderPack->bottomLeft
            . $this->borderPack->horizontal
            . $visibleLeftLabel
            . str_repeat($this->borderPack->horizontal, $fillerWidth)
            . $visibleRightLabel
            . $this->borderPack->bottomRight;
    }

    private function refreshDerivedControls(): void
    {
        foreach ($this->texturePreviewRegistrations as $registration) {
            $textureControl = $registration['texture'] ?? null;
            $sizeControl = $registration['size'] ?? null;
            $previewControl = $registration['preview'] ?? null;
            $offsetControl = $registration['offset'] ?? null;
            $naturalSizeFallback = (bool) ($registration['naturalSizeFallback'] ?? true);

            if (
                !$textureControl instanceof PathInputControl
                || !$sizeControl instanceof VectorInputControl
                || !$previewControl instanceof PreviewWindowControl
            ) {
                continue;
            }

            $texturePath = (string) $textureControl->getValue();
            $size = $sizeControl->getValue();
            $offset = $offsetControl instanceof VectorInputControl
                ? $offsetControl->getValue()
                : ['x' => 0, 'y' => 0];

            if (!is_array($size) || !is_array($offset)) {
                continue;
            }

            $previewControl->setValue(
                $this->buildTexturePreviewLines($texturePath, $offset, $size, $naturalSizeFallback)
            );
        }
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
        $visibleControlIndexes = $this->resolveVisibleControlIndexes();

        if ($visibleControlIndexes === []) {
            return;
        }

        if ($this->selectedControlIndex === null || !in_array($this->selectedControlIndex, $visibleControlIndexes, true)) {
            $this->selectedControlIndex = $visibleControlIndexes[0];
            $this->applyControlSelection();
            $this->refreshContent();
            return;
        }

        $visibleControlPosition = array_search($this->selectedControlIndex, $visibleControlIndexes, true);

        if (!is_int($visibleControlPosition)) {
            $this->selectedControlIndex = $visibleControlIndexes[0];
            $this->applyControlSelection();
            $this->refreshContent();
            return;
        }

        $nextVisibleControlPosition = ($visibleControlPosition + $offset + count($visibleControlIndexes))
            % count($visibleControlIndexes);
        $this->selectedControlIndex = $visibleControlIndexes[$nextVisibleControlPosition];
        $this->applyControlSelection();
        $selectedControl = $this->getSelectedControl();

        if (!$this->isSelectedComponentHeader($selectedControl)) {
            $this->isComponentMoveModeActive = false;
        }

        $this->refreshContent();
    }

    private function handleControlSelectionInput(InputControl $selectedControl): void
    {
        if (Input::getCurrentInput() === 'A' && $this->canOpenAddComponentModal()) {
            $this->showAddComponentModal();
            return;
        }

        if (Input::getCurrentInput() === 'W' && $this->canMutateCurrentComponentList()) {
            $this->handleComponentMoveModeToggle($selectedControl);
            return;
        }

        if (
            Input::isKeyDown(KeyCode::DELETE)
            && $this->isSelectedComponentHeader($selectedControl)
            && $this->canMutateCurrentComponentList()
        ) {
            $this->showDeleteComponentModal($selectedControl);
            return;
        }

        if (
            $this->isComponentMoveModeActive
            && $this->isSelectedComponentHeader($selectedControl)
            && $this->canMutateCurrentComponentList()
        ) {
            if (Input::isKeyDown(KeyCode::ESCAPE)) {
                $this->isComponentMoveModeActive = false;
                return;
            }

            if (Input::isKeyPressed(KeyCode::UP)) {
                $this->moveSelectedComponent(-1);
                return;
            }

            if (Input::isKeyPressed(KeyCode::DOWN)) {
                $this->moveSelectedComponent(1);
                return;
            }
        }

        if (Input::isKeyDown(KeyCode::UP)) {
            $this->moveControlSelection(-1);
            return;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $this->moveControlSelection(1);
            return;
        }

        if (Input::isKeyDown(KeyCode::SLASH) && $selectedControl instanceof SectionControl) {
            $selectedControl->toggleCollapsed();
            $this->refreshContent();
            return;
        }

        if (!Input::isKeyDown(KeyCode::ENTER)) {
            return;
        }

        $this->activateSelectedControl($selectedControl);
    }

    private function activateSelectedControl(InputControl $selectedControl): void
    {
        if ($selectedControl instanceof PrefabReferenceInputControl) {
            $this->showPrefabReferenceModal($selectedControl);
            return;
        }

        if ($selectedControl instanceof UIElementReferenceInputControl) {
            $this->showUIElementReferenceModal($selectedControl);
            return;
        }

        if ($selectedControl instanceof PathInputControl) {
            $this->showPathInputActionModal($selectedControl);
            return;
        }

        if ($selectedControl instanceof CompoundInputControl) {
            if ($selectedControl->beginPropertySelection()) {
                $this->interactionState = self::STATE_PROPERTY_SELECTION;
                $this->refreshContent();
            }

            return;
        }

        if ($selectedControl->enterEditMode()) {
            $this->interactionState = self::STATE_CONTROL_EDIT;
            $this->refreshContent();
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

        if (Input::isKeyPressed(KeyCode::BACKSPACE)) {
            $selectedControl->deleteBackward();
            return;
        }

        if (Input::isKeyPressed(KeyCode::LEFT)) {
            $selectedControl->moveCursorLeft();
            return;
        }

        if (Input::isKeyPressed(KeyCode::RIGHT)) {
            $selectedControl->moveCursorRight();
            return;
        }

        if (
            !$selectedControl instanceof SliderInputControl
            && Input::isKeyPressed(KeyCode::UP)
            && $selectedControl->increment()
        ) {
            return;
        }

        if (
            !$selectedControl instanceof SliderInputControl
            && Input::isKeyPressed(KeyCode::DOWN)
            && $selectedControl->decrement()
        ) {
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
        $this->closeAddComponentModal();
        $this->closeDeleteComponentModal();
        $this->closePrefabReferenceModal();
        $this->closeUIElementReferenceModal();
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
        $this->isComponentMoveModeActive = false;
    }

    private function handleAddComponentModalInput(): void
    {
        if (Input::isKeyDown(KeyCode::ESCAPE)) {
            $this->closeAddComponentModal();
            $this->refreshContent();
            return;
        }

        if (Input::isKeyDown(KeyCode::UP)) {
            $this->addComponentModal->moveSelection(-1);
            return;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $this->addComponentModal->moveSelection(1);
            return;
        }

        if (!Input::isKeyDown(KeyCode::ENTER)) {
            return;
        }

        $this->applyAddComponentSelection($this->addComponentModal->getSelectedOption());
    }

    private function handleDeleteComponentModalInput(): void
    {
        if (Input::isKeyDown(KeyCode::ESCAPE)) {
            $this->closeDeleteComponentModal();
            $this->refreshContent();
            return;
        }

        if (Input::isKeyDown(KeyCode::UP)) {
            $this->deleteComponentModal->moveSelection(-1);
            return;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $this->deleteComponentModal->moveSelection(1);
            return;
        }

        if (!Input::isKeyDown(KeyCode::ENTER)) {
            return;
        }

        $this->applyDeleteComponentSelection($this->deleteComponentModal->getSelectedOption());
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

        $this->applyPathInputActionSelection($this->pathInputActionModal->getSelectedOption());
    }

    private function handlePathInputFileDialogInput(): void
    {
        if (Input::isKeyDown(KeyCode::ESCAPE)) {
            $this->requestModalBackgroundRefresh();
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

        $this->applyPathInputFileSelection($this->fileDialogModal->submitSelection());
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

    private function showPrefabReferenceModal(PrefabReferenceInputControl $control): void
    {
        $this->activePrefabReferenceControl = $control;
        $this->prefabReferenceOptions = $this->resolveAvailablePrefabOptions();
        $options = ['None', ...array_keys($this->prefabReferenceOptions), 'Cancel'];
        $selectedIndex = 0;
        $currentValue = $control->getValue();

        if (is_string($currentValue) && $currentValue !== '') {
            foreach ($this->prefabReferenceOptions as $label => $definition) {
                if (($definition['path'] ?? null) === $currentValue) {
                    $optionIndex = array_search($label, $options, true);

                    if (is_int($optionIndex)) {
                        $selectedIndex = $optionIndex;
                    }

                    break;
                }
            }
        }

        $this->prefabReferenceModal->show($options, $selectedIndex, 'Choose Prefab');
        $this->interactionState = self::STATE_PREFAB_REFERENCE_SELECTION;
        $terminalSize = get_max_terminal_size();
        $terminalWidth = $terminalSize['width'] ?? DEFAULT_TERMINAL_WIDTH;
        $terminalHeight = $terminalSize['height'] ?? DEFAULT_TERMINAL_HEIGHT;
        $this->syncModalLayout($terminalWidth, $terminalHeight);
    }

    private function showUIElementReferenceModal(UIElementReferenceInputControl $control): void
    {
        $this->activeUIElementReferenceControl = $control;
        $fieldType = $this->resolveSelectedControlAssignableUIElementType($control);
        $this->uiElementReferenceOptions = $this->resolveAvailableUIElementReferenceOptions($fieldType);
        $options = ['None', ...array_keys($this->uiElementReferenceOptions), 'Cancel'];
        $selectedIndex = 0;
        $currentValue = $control->getValue();

        if (is_string($currentValue) && $currentValue !== '') {
            foreach ($this->uiElementReferenceOptions as $label => $definition) {
                if (($definition['name'] ?? null) === $currentValue) {
                    $optionIndex = array_search($label, $options, true);

                    if (is_int($optionIndex)) {
                        $selectedIndex = $optionIndex;
                    }

                    break;
                }
            }
        }

        $modalTitle = $fieldType === null || $fieldType === self::UI_ELEMENT_TYPE
            ? 'Choose UI Element'
            : 'Choose ' . $this->shortTypeName($fieldType);

        $this->uiElementReferenceModal->show($options, $selectedIndex, $modalTitle);
        $this->interactionState = self::STATE_UI_ELEMENT_REFERENCE_SELECTION;
        $terminalSize = get_max_terminal_size();
        $terminalWidth = $terminalSize['width'] ?? DEFAULT_TERMINAL_WIDTH;
        $terminalHeight = $terminalSize['height'] ?? DEFAULT_TERMINAL_HEIGHT;
        $this->syncModalLayout($terminalWidth, $terminalHeight);
    }

    private function handlePrefabReferenceModalInput(): void
    {
        if (Input::isKeyDown(KeyCode::ESCAPE)) {
            $this->closePrefabReferenceModal();
            $this->interactionState = self::STATE_CONTROL_SELECTION;
            $this->refreshContent();
            return;
        }

        if (Input::isKeyDown(KeyCode::UP)) {
            $this->prefabReferenceModal->moveSelection(-1);
            return;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $this->prefabReferenceModal->moveSelection(1);
            return;
        }

        if (!Input::isKeyDown(KeyCode::ENTER)) {
            return;
        }

        $this->applyPrefabReferenceSelection($this->prefabReferenceModal->getSelectedOption());
    }

    private function handleUIElementReferenceModalInput(): void
    {
        if (Input::isKeyDown(KeyCode::ESCAPE)) {
            $this->closeUIElementReferenceModal();
            $this->interactionState = self::STATE_CONTROL_SELECTION;
            $this->refreshContent();
            return;
        }

        if (Input::isKeyDown(KeyCode::UP)) {
            $this->uiElementReferenceModal->moveSelection(-1);
            return;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $this->uiElementReferenceModal->moveSelection(1);
            return;
        }

        if (!Input::isKeyDown(KeyCode::ENTER)) {
            return;
        }

        $this->applyUIElementReferenceSelection($this->uiElementReferenceModal->getSelectedOption());
    }

    private function closePrefabReferenceModal(): void
    {
        $this->prefabReferenceModal->hide();
        $this->activePrefabReferenceControl = null;
        $this->prefabReferenceOptions = [];
    }

    private function closeUIElementReferenceModal(): void
    {
        $this->uiElementReferenceModal->hide();
        $this->activeUIElementReferenceControl = null;
        $this->uiElementReferenceOptions = [];
    }

    private function applyAddComponentSelection(?string $selection): void
    {
        if (!is_string($selection) || $selection === '' || $selection === 'Cancel') {
            $this->closeAddComponentModal();
            $this->refreshContent();
            return;
        }

        $componentDefinition = $this->componentMenuDefinitions[$selection] ?? null;

        if (is_array($componentDefinition)) {
            $this->appendComponentToInspectionTarget($componentDefinition);
        }

        $this->closeAddComponentModal();
        $this->refreshContent();
    }

    private function applyDeleteComponentSelection(?string $selection): void
    {
        if ($selection === 'Delete' && is_int($this->pendingComponentDeletionIndex)) {
            $this->removeComponentAtIndex($this->pendingComponentDeletionIndex);
        }

        $this->closeDeleteComponentModal();
        $this->refreshContent();
    }

    private function applyPathInputActionSelection(?string $selection): void
    {
        if ($selection === 'Choose file') {
            $this->pathInputActionModal->hide();

            if ($this->activePathInputControl instanceof PathInputControl) {
                $this->fileDialogModal->show(
                    $this->activePathInputControl->getWorkingDirectory(),
                    (string) $this->activePathInputControl->getValue(),
                    $this->activePathInputControl->getAllowedExtensions(),
                );
                $this->interactionState = self::STATE_PATH_INPUT_FILE_DIALOG;
            }

            return;
        }

        if ($selection !== 'Edit path' || !$this->activePathInputControl instanceof PathInputControl) {
            return;
        }

        $this->requestModalBackgroundRefresh();
        $this->pathInputActionModal->hide();

        if ($this->activePathInputControl->enterEditMode()) {
            $this->interactionState = self::STATE_CONTROL_EDIT;
        } else {
            $this->closePathInputModals();
            $this->interactionState = self::STATE_CONTROL_SELECTION;
        }

        $this->refreshContent();
    }

    private function applyPathInputFileSelection(?string $selectedPath): void
    {
        if ($selectedPath === null || !$this->activePathInputControl instanceof PathInputControl) {
            return;
        }

        $this->activePathInputControl->setValueFromRelativePath($selectedPath);
        $this->applyControlValueToInspectionTarget($this->activePathInputControl);
        $this->closePathInputModals();
        $this->interactionState = self::STATE_CONTROL_SELECTION;
        $this->refreshContent();
    }

    private function applyPrefabReferenceSelection(?string $selection): void
    {
        if (!$this->activePrefabReferenceControl instanceof PrefabReferenceInputControl) {
            $this->closePrefabReferenceModal();
            $this->interactionState = self::STATE_CONTROL_SELECTION;
            $this->refreshContent();
            return;
        }

        if ($selection === 'Cancel') {
            $this->closePrefabReferenceModal();
            $this->interactionState = self::STATE_CONTROL_SELECTION;
            $this->refreshContent();
            return;
        }

        $nextValue = $selection === 'None'
            ? null
            : ($this->prefabReferenceOptions[$selection]['path'] ?? null);

        $this->activePrefabReferenceControl->setValue($nextValue);
        $this->applyControlValueToInspectionTarget($this->activePrefabReferenceControl);
        $this->closePrefabReferenceModal();
        $this->interactionState = self::STATE_CONTROL_SELECTION;
        $this->refreshContent();
    }

    private function applyUIElementReferenceSelection(?string $selection): void
    {
        if (!$this->activeUIElementReferenceControl instanceof UIElementReferenceInputControl) {
            $this->closeUIElementReferenceModal();
            $this->interactionState = self::STATE_CONTROL_SELECTION;
            $this->refreshContent();
            return;
        }

        if ($selection === 'Cancel') {
            $this->closeUIElementReferenceModal();
            $this->interactionState = self::STATE_CONTROL_SELECTION;
            $this->refreshContent();
            return;
        }

        $nextValue = $selection === 'None'
            ? null
            : ($this->uiElementReferenceOptions[$selection]['name'] ?? null);

        $this->activeUIElementReferenceControl->setValue($nextValue);
        $this->applyControlValueToInspectionTarget($this->activeUIElementReferenceControl);
        $this->closeUIElementReferenceModal();
        $this->interactionState = self::STATE_CONTROL_SELECTION;
        $this->refreshContent();
    }

    private function requestModalBackgroundRefresh(): void
    {
        $this->shouldRefreshModalBackground = true;
    }

    private function canOpenAddComponentModal(): bool
    {
        return is_array($this->inspectionTarget)
            && in_array($this->inspectionTarget['context'] ?? null, ['hierarchy', 'prefab'], true)
            && is_string($this->inspectionTarget['path'] ?? null)
            && ($this->inspectionTarget['path'] ?? null) !== 'scene'
            && is_array($this->inspectionTarget['value'] ?? null);
    }

    private function queueObjectInspectionMutation(array $target, array $inspectionValue): void
    {
        $context = $target['context'] ?? null;
        $path = $target['path'] ?? null;

        if (!is_string($path) || $path === '') {
            return;
        }

        if ($context === 'hierarchy') {
            $this->pendingHierarchyMutation = [
                'path' => $path,
                'value' => $inspectionValue,
            ];
            return;
        }

        if ($context !== 'prefab') {
            return;
        }

        $asset = is_array($target['asset'] ?? null) ? $target['asset'] : [];
        $prefabPath = is_string($asset['path'] ?? null) ? $asset['path'] : null;

        if (!is_string($prefabPath) || $prefabPath === '') {
            return;
        }

        $this->pendingPrefabMutation = [
            'path' => $path,
            'prefabPath' => $prefabPath,
            'asset' => $asset,
            'value' => $inspectionValue,
        ];
    }

    private function showAddComponentModal(): void
    {
        $this->componentMenuDefinitions = $this->resolveAvailableComponentDefinitions();
        $options = array_keys($this->componentMenuDefinitions);
        $options[] = 'Cancel';
        $this->addComponentModal->show($options, 0, 'Add Component');
        $terminalSize = get_max_terminal_size();
        $terminalWidth = $terminalSize['width'] ?? DEFAULT_TERMINAL_WIDTH;
        $terminalHeight = $terminalSize['height'] ?? DEFAULT_TERMINAL_HEIGHT;
        $this->syncModalLayout($terminalWidth, $terminalHeight);
    }

    private function closeAddComponentModal(): void
    {
        $this->addComponentModal->hide();
        $this->componentMenuDefinitions = [];
    }

    private function closeDeleteComponentModal(): void
    {
        $this->deleteComponentModal->hide();
        $this->pendingComponentDeletionIndex = null;
    }

    private function resolveAvailableComponentDefinitions(): array
    {
        $currentItem = is_array($this->inspectionTarget['value'] ?? null)
            ? $this->inspectionTarget['value']
            : [];
        $candidateClasses = $this->resolveComponentCandidateClasses($currentItem);
        $definitions = $this->loadComponentDefinitionsInIsolatedProcess($candidateClasses, $currentItem);

        $resolvedDefinitionClasses = array_values(array_unique(array_filter(
            array_map(
                static fn(array $definition): ?string => is_string($definition['class'] ?? null)
                    ? $definition['class']
                    : null,
                $definitions,
            ),
            static fn(?string $class): bool => is_string($class) && $class !== '',
        )));

        $missingCandidateClasses = array_values(array_filter(
            $candidateClasses,
            static fn(string $candidateClass): bool => !in_array($candidateClass, $resolvedDefinitionClasses, true),
        ));

        if ($missingCandidateClasses !== []) {
            $definitions = [
                ...$definitions,
                ...$this->buildFallbackComponentDefinitions($missingCandidateClasses),
            ];
        }

        if ($definitions === []) {
            return [];
        }

        usort(
            $definitions,
            fn(array $left, array $right): int => strcmp(
                (string) ($left['label'] ?? $left['class'] ?? ''),
                (string) ($right['label'] ?? $right['class'] ?? '')
            ),
        );

        $resolvedDefinitions = [];
        $usedLabels = [];

        foreach ($definitions as $definition) {
            $componentClass = is_string($definition['class'] ?? null) ? $definition['class'] : null;

            if ($componentClass === null || $componentClass === '') {
                continue;
            }

            $label = $this->buildUniqueComponentMenuLabel(
                is_string($definition['label'] ?? null) && $definition['label'] !== ''
                    ? $definition['label']
                    : $this->resolveClassName($componentClass, $componentClass),
                $componentClass,
                $usedLabels,
            );

            $resolvedDefinitions[$label] = [
                'class' => $componentClass,
                'data' => is_array($definition['data'] ?? null) ? $definition['data'] : [],
                'fieldTypes' => is_array($definition['fieldTypes'] ?? null) ? $definition['fieldTypes'] : [],
            ];
        }

        return $resolvedDefinitions;
    }

    private function resolveComponentCandidateClasses(array $currentItem): array
    {
        $currentComponentClasses = $this->collectComponentClassesFromComponents($currentItem['components'] ?? []);
        $sceneComponentClasses = $this->collectComponentClassesFromHierarchy($this->sceneHierarchy);
        $projectComponentClasses = $this->discoverProjectComponentCandidates();

        $candidates = array_values(array_unique(array_filter(
            [
                ...self::DEFAULT_COMPONENT_CANDIDATES,
                ...$projectComponentClasses,
                ...$sceneComponentClasses,
                ...$currentComponentClasses,
            ],
            static fn(mixed $class): bool => is_string($class) && $class !== '',
        )));

        return $candidates;
    }

    private function buildFallbackComponentDefinitions(array $candidateClasses): array
    {
        $autoloadPath = Path::join($this->projectDirectory, 'vendor', 'autoload.php');
        ProjectAutoloadLoader::load($autoloadPath);

        $componentBaseClass = 'Sendama\\Engine\\Core\\Component';

        if (!class_exists($componentBaseClass)) {
            return [];
        }

        $definitions = [];

        foreach ($candidateClasses as $candidateClass) {
            if (!is_string($candidateClass) || $candidateClass === '') {
                continue;
            }

            if (in_array($candidateClass, ['Sendama\\Engine\\Core\\Transform', 'Sendama\\Engine\\Core\\Rendering\\Renderer'], true)) {
                continue;
            }

            if (!class_exists($candidateClass) || !is_a($candidateClass, $componentBaseClass, true)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($candidateClass);

                if ($reflection->isAbstract()) {
                    continue;
                }

                $definitions[] = [
                    'class' => $candidateClass,
                    'label' => $this->resolveClassName($candidateClass, $candidateClass),
                    'data' => $this->extractFallbackSerializableComponentData($reflection),
                    'fieldTypes' => $this->extractFallbackComponentFieldTypes($reflection),
                ];
            } catch (Throwable) {
                continue;
            }
        }

        return $definitions;
    }

    private function discoverProjectComponentCandidates(): array
    {
        if (is_array($this->cachedProjectComponentCandidates)) {
            return $this->cachedProjectComponentCandidates;
        }

        $scriptsDirectory = Path::join($this->resolveAssetsWorkingDirectory(), 'Scripts');

        if (!is_dir($scriptsDirectory)) {
            return $this->cachedProjectComponentCandidates = [];
        }

        $componentCandidates = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scriptsDirectory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower((string) $file->getExtension()) !== 'php') {
                continue;
            }

            $classReference = $this->extractClassReferenceFromPhpFile($file->getPathname());

            if (is_string($classReference) && $classReference !== '') {
                $componentCandidates[] = $classReference;
            }
        }

        return $this->cachedProjectComponentCandidates = array_values(array_unique($componentCandidates));
    }

    private function extractClassReferenceFromPhpFile(string $filePath): ?string
    {
        $source = file_get_contents($filePath);

        if ($source === false || $source === '') {
            return null;
        }

        $tokens = token_get_all($source);
        $namespace = '';
        $className = null;
        $tokenCount = count($tokens);

        for ($index = 0; $index < $tokenCount; $index++) {
            $token = $tokens[$index];

            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $namespace = '';

                for ($lookahead = $index + 1; $lookahead < $tokenCount; $lookahead++) {
                    $namespaceToken = $tokens[$lookahead];

                    if (
                        is_string($namespaceToken)
                        && ($namespaceToken === ';' || $namespaceToken === '{')
                    ) {
                        break;
                    }

                    if (
                        is_array($namespaceToken)
                        && in_array($namespaceToken[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)
                    ) {
                        $namespace .= $namespaceToken[1];
                    }
                }

                continue;
            }

            if (!is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }

            $previousToken = $tokens[$index - 1] ?? null;

            if (
                is_array($previousToken)
                && in_array($previousToken[0], [T_DOUBLE_COLON, T_NEW], true)
            ) {
                continue;
            }

            for ($lookahead = $index + 1; $lookahead < $tokenCount; $lookahead++) {
                $classToken = $tokens[$lookahead];

                if (is_array($classToken) && $classToken[0] === T_STRING) {
                    $className = $classToken[1];
                    break 2;
                }
            }
        }

        if (!is_string($className) || $className === '') {
            return null;
        }

        return $namespace !== ''
            ? $namespace . '\\' . $className
            : $className;
    }

    private function collectComponentClassesFromHierarchy(array $hierarchy): array
    {
        $componentClasses = [];

        foreach ($hierarchy as $item) {
            if (!is_array($item)) {
                continue;
            }

            $componentClasses = [
                ...$componentClasses,
                ...$this->collectComponentClassesFromComponents($item['components'] ?? []),
                ...$this->collectComponentClassesFromHierarchy(
                    is_array($item['children'] ?? null) ? $item['children'] : []
                ),
            ];
        }

        return array_values(array_unique($componentClasses));
    }

    private function collectComponentClassesFromComponents(mixed $components): array
    {
        if (!is_array($components)) {
            return [];
        }

        $componentClasses = [];

        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }

            $componentClass = $component['class'] ?? null;

            if (is_string($componentClass) && $componentClass !== '') {
                $componentClasses[] = $componentClass;
            }
        }

        return array_values(array_unique($componentClasses));
    }

    private function loadComponentDefinitionsInIsolatedProcess(array $candidateClasses, array $item): array
    {
        $candidateClasses = array_values(array_unique(array_filter(
            $candidateClasses,
            static fn(mixed $class): bool => is_string($class) && $class !== '',
        )));

        if ($candidateClasses === []) {
            return [];
        }

        $autoloadPath = Path::join($this->projectDirectory, 'vendor', 'autoload.php');

        if (!is_file($autoloadPath)) {
            return array_map(
                fn(string $componentClass): array => [
                    'class' => $componentClass,
                    'label' => $this->resolveClassName($componentClass, $componentClass),
                    'data' => [],
                ],
                $candidateClasses,
            );
        }

        $script = <<<'PHP'
$autoloadPath = $argv[1] ?? '';
$candidateClasses = json_decode($argv[2] ?? '[]', true);
$item = json_decode($argv[3] ?? '[]', true);

function normalize_editor_value(mixed $value): mixed
{
    if (is_array($value)) {
        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = normalize_editor_value($item);
        }

        return $normalized;
    }

    if ($value instanceof UnitEnum) {
        return $value instanceof BackedEnum ? $value->value : $value->name;
    }

    if (!is_object($value)) {
        return $value;
    }

    if (is_a($value, '\Sendama\Engine\Core\Sprite')) {
        $normalizedSprite = [];
        $texture = method_exists($value, 'getTexture')
            ? $value->getTexture()
            : (property_exists($value, 'texture') ? $value->texture : null);
        $rect = method_exists($value, 'getRect')
            ? $value->getRect()
            : (property_exists($value, 'rect') ? $value->rect : null);
        $pivot = method_exists($value, 'getPivot')
            ? $value->getPivot()
            : (property_exists($value, 'pivot') ? $value->pivot : null);

        if ($texture !== null) {
            $normalizedSprite['texture'] = normalize_editor_value($texture);
        }

        if ($rect !== null) {
            $normalizedSprite['rect'] = normalize_editor_value($rect);
        }

        if ($pivot !== null) {
            $normalizedSprite['pivot'] = normalize_editor_value($pivot);
        }

        if ($normalizedSprite !== []) {
            return $normalizedSprite;
        }
    }

    if (is_a($value, '\Sendama\Engine\Core\Texture')) {
        $path = method_exists($value, 'getPath')
            ? $value->getPath()
            : (property_exists($value, 'path') ? $value->path : null);
        $path = is_string($path) ? trim($path) : '';

        if ($path !== '') {
            $normalizedTexture = ['path' => $path];
            $requestedWidth = method_exists($value, 'getRequestedWidth') ? $value->getRequestedWidth() : null;
            $requestedHeight = method_exists($value, 'getRequestedHeight') ? $value->getRequestedHeight() : null;
            $color = method_exists($value, 'getColor') ? $value->getColor() : null;

            if (is_int($requestedWidth) && $requestedWidth > 0) {
                $normalizedTexture['width'] = $requestedWidth;
            }

            if (is_int($requestedHeight) && $requestedHeight > 0) {
                $normalizedTexture['height'] = $requestedHeight;
            }

            if ($color !== null) {
                $normalizedTexture['color'] = normalize_editor_value($color);
            }

            return count($normalizedTexture) === 1
                ? $normalizedTexture['path']
                : $normalizedTexture;
        }
    }

    if (
        (is_a($value, '\Sendama\Engine\Core\Rect')
            || (method_exists($value, 'getWidth') && method_exists($value, 'getHeight')))
        && method_exists($value, 'getX')
        && method_exists($value, 'getY')
        && method_exists($value, 'getWidth')
        && method_exists($value, 'getHeight')
    ) {
        return [
            'x' => normalize_editor_value($value->getX()),
            'y' => normalize_editor_value($value->getY()),
            'width' => normalize_editor_value($value->getWidth()),
            'height' => normalize_editor_value($value->getHeight()),
        ];
    }

    if (method_exists($value, 'getX') && method_exists($value, 'getY')) {
        return [
            'x' => normalize_editor_value($value->getX()),
            'y' => normalize_editor_value($value->getY()),
        ];
    }

    if (method_exists($value, 'getName')) {
        try {
            return $value->getName();
        } catch (Throwable) {
        }
    }

    if (method_exists($value, '__serialize')) {
        try {
            $serialized = $value->__serialize();

            return is_array($serialized)
                ? normalize_editor_value($serialized)
                : normalize_editor_value((array) $serialized);
        } catch (Throwable) {
        }
    }

    $compoundValue = extract_compound_editor_value($value);

    if (is_array($compoundValue)) {
        return $compoundValue;
    }

    if ($value instanceof Stringable) {
        return (string) $value;
    }

    return get_class($value);
}

function extract_compound_editor_value(object $value): ?array
{
    $valueClass = $value::class;

    if (
        is_a($valueClass, '\Sendama\Engine\Core\Component', true)
        || is_a($valueClass, '\Sendama\Engine\Core\GameObject', true)
        || is_a($valueClass, '\Sendama\Engine\UI\UIElement', true)
    ) {
        return null;
    }

    try {
        $reflection = new ReflectionObject($value);
    } catch (Throwable) {
        return null;
    }

    $normalized = [];

    foreach ($reflection->getProperties() as $property) {
        if (
            $property->isStatic()
            || (!$property->isPublic() && $property->getAttributes('Sendama\Engine\Core\Behaviours\Attributes\SerializeField') === [])
            || (method_exists($property, 'isVirtual') && $property->isVirtual())
        ) {
            continue;
        }

        try {
            $normalized[$property->getName()] = normalize_editor_value($property->getValue($value));
        } catch (Throwable) {
            continue;
        }
    }

    return $normalized !== [] ? $normalized : null;
}

function build_vector(mixed $value, array $default = ['x' => 0, 'y' => 0]): ?object
{
    if (!class_exists('\Sendama\Engine\Core\Vector2')) {
        return null;
    }

    $vectorValue = is_array($value) ? $value : $default;

    return new \Sendama\Engine\Core\Vector2(
        (int) ($vectorValue['x'] ?? $default['x']),
        (int) ($vectorValue['y'] ?? $default['y']),
    );
}

function build_dummy_game_object(array $item): ?object
{
    if (!class_exists('\Sendama\Engine\Core\GameObject')) {
        return null;
    }

    $tag = is_string($item['tag'] ?? null) && $item['tag'] !== 'None'
        ? $item['tag']
        : null;

    return new \Sendama\Engine\Core\GameObject(
        is_string($item['name'] ?? null) ? $item['name'] : 'GameObject',
        $tag,
        build_vector($item['position'] ?? null) ?? new \Sendama\Engine\Core\Vector2(),
        build_vector($item['rotation'] ?? null) ?? new \Sendama\Engine\Core\Vector2(),
        build_vector($item['scale'] ?? ['x' => 1, 'y' => 1], ['x' => 1, 'y' => 1]) ?? new \Sendama\Engine\Core\Vector2(1, 1),
        null,
    );
}

function extract_component_serializable_data(object $component): array
{
    $serializedData = [];
    $reflection = new ReflectionObject($component);

    foreach ($reflection->getProperties() as $property) {
        $isSerializable = $property->isPublic()
            || $property->getAttributes('Sendama\Engine\Core\Behaviours\Attributes\SerializeField') !== [];

        if (!$isSerializable) {
            continue;
        }

        if (method_exists($property, 'isVirtual') && $property->isVirtual()) {
            continue;
        }

        try {
            $serializedData[$property->getName()] = $property->getValue($component);
        } catch (Throwable) {
            continue;
        }
    }

    return $serializedData;
}

function extract_component_field_types(object $component): array
{
    $fieldTypes = [];
    $reflection = new ReflectionObject($component);

    foreach ($reflection->getProperties() as $property) {
        $isSerializable = $property->isPublic()
            || $property->getAttributes('Sendama\Engine\Core\Behaviours\Attributes\SerializeField') !== [];

        if (!$isSerializable) {
            continue;
        }

        if (method_exists($property, 'isVirtual') && $property->isVirtual()) {
            continue;
        }

        $resolvedType = resolve_property_type($property);

        if ($resolvedType !== null) {
            $fieldTypes[$property->getName()] = $resolvedType;
        }
    }

    return $fieldTypes;
}

function resolve_property_type(ReflectionProperty $property): ?string
{
    $type = $property->getType();

    if ($type instanceof ReflectionNamedType) {
        $resolvedType = $type->getName();

        if ($type->allowsNull() && $resolvedType !== 'null') {
            return $resolvedType . '|null';
        }

        return $resolvedType;
    }

    if ($type instanceof ReflectionUnionType) {
        $resolvedTypes = [];

        foreach ($type->getTypes() as $namedType) {
            if ($namedType instanceof ReflectionNamedType) {
                $resolvedTypes[] = $namedType->getName();
            }
        }

        $resolvedTypes = array_values(array_unique(array_filter($resolvedTypes)));

        return $resolvedTypes !== [] ? implode('|', $resolvedTypes) : null;
    }

    return null;
}

function serialize_component_data(string $componentClass, array $item): ?array
{
    if (
        !class_exists($componentClass)
        || !class_exists('\Sendama\Engine\Core\Component')
        || !is_a($componentClass, '\Sendama\Engine\Core\Component', true)
    ) {
        return null;
    }

    try {
        $reflection = new ReflectionClass($componentClass);

        if ($reflection->isAbstract()) {
            return null;
        }

        $gameObject = build_dummy_game_object($item);

        if (!is_object($gameObject)) {
            return null;
        }

        $component = new $componentClass($gameObject);

        return normalize_editor_value(extract_component_serializable_data($component));
    } catch (Throwable) {
        return null;
    }
}

function short_class_name(string $classReference): string
{
    $segments = explode('\\', ltrim($classReference, '\\'));
    return end($segments) ?: $classReference;
}

if (is_file($autoloadPath)) {
    @require $autoloadPath;
}

$definitions = [];

foreach ((array) $candidateClasses as $candidateClass) {
    if (!is_string($candidateClass) || $candidateClass === '') {
        continue;
    }

    if (in_array($candidateClass, ['Sendama\Engine\Core\Transform', 'Sendama\Engine\Core\Rendering\Renderer'], true)) {
        continue;
    }

    if (
        !class_exists($candidateClass)
        || !class_exists('\Sendama\Engine\Core\Component')
        || !is_a($candidateClass, '\Sendama\Engine\Core\Component', true)
    ) {
        continue;
    }

    try {
        $reflection = new ReflectionClass($candidateClass);

        if ($reflection->isAbstract()) {
            continue;
        }
    } catch (Throwable) {
        continue;
    }

    $definitions[] = [
        'class' => $candidateClass,
        'label' => short_class_name($candidateClass),
        'data' => serialize_component_data($candidateClass, is_array($item) ? $item : []) ?? [],
        'fieldTypes' => is_object($gameObject = build_dummy_game_object(is_array($item) ? $item : []))
            ? (function () use ($candidateClass, $gameObject): array {
                try {
                    return extract_component_field_types(new $candidateClass($gameObject));
                } catch (Throwable) {
                    return [];
                }
            })()
            : [],
    ];
}

echo json_encode($definitions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHP;

        $command = [
            PHP_BINARY,
            '-d',
            'display_errors=stderr',
            '-r',
            $script,
            $autoloadPath,
            json_encode($candidateClasses, JSON_UNESCAPED_SLASHES) ?: '[]',
            json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $this->projectDirectory);

        if (!is_resource($process)) {
            return [];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0 || !is_string($stdout)) {
            return [];
        }

        $definitions = json_decode($stdout, true);

        return is_array($definitions) ? $definitions : [];
    }

    private function extractFallbackSerializableComponentData(ReflectionClass $reflection): array
    {
        $data = [];
        $defaults = $reflection->getDefaultProperties();

        foreach ($reflection->getProperties() as $property) {
            if (!$this->isSerializableComponentProperty($property) || $property->isStatic()) {
                continue;
            }

            $propertyName = $property->getName();

            if (!array_key_exists($propertyName, $defaults)) {
                continue;
            }

            $data[$propertyName] = $this->normalizeEditorValue($defaults[$propertyName]);
        }

        return $data;
    }

    private function extractFallbackComponentFieldTypes(ReflectionClass $reflection): array
    {
        $fieldTypes = [];

        foreach ($reflection->getProperties() as $property) {
            if (!$this->isSerializableComponentProperty($property) || $property->isStatic()) {
                continue;
            }

            $resolvedType = $this->resolveReflectionPropertyType($property);

            if ($resolvedType !== null) {
                $fieldTypes[$property->getName()] = $resolvedType;
            }
        }

        return $fieldTypes;
    }

    private function isSerializableComponentProperty(ReflectionProperty $property): bool
    {
        return $property->isPublic()
            || $property->getAttributes('Sendama\\Engine\\Core\\Behaviours\\Attributes\\SerializeField') !== [];
    }

    private function resolveReflectionPropertyType(ReflectionProperty $property): ?string
    {
        $type = $property->getType();

        if ($type instanceof ReflectionNamedType) {
            $resolvedType = $type->getName();

            if ($type->allowsNull() && $resolvedType !== 'null') {
                return $resolvedType . '|null';
            }

            return $resolvedType;
        }

        if ($type instanceof ReflectionUnionType) {
            $resolvedTypes = [];

            foreach ($type->getTypes() as $namedType) {
                if ($namedType instanceof ReflectionNamedType) {
                    $resolvedTypes[] = $namedType->getName();
                }
            }

            $resolvedTypes = array_values(array_unique(array_filter($resolvedTypes)));

            return $resolvedTypes !== [] ? implode('|', $resolvedTypes) : null;
        }

        return null;
    }

    private function normalizeEditorValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeEditorValue($item);
            }

            return $normalized;
        }

        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }

        if (!is_object($value)) {
            return $value;
        }

        if (is_a($value, '\Sendama\Engine\Core\Sprite')) {
            $normalizedSprite = [];
            $texture = method_exists($value, 'getTexture')
                ? $value->getTexture()
                : (property_exists($value, 'texture') ? $value->texture : null);
            $rect = method_exists($value, 'getRect')
                ? $value->getRect()
                : (property_exists($value, 'rect') ? $value->rect : null);
            $pivot = method_exists($value, 'getPivot')
                ? $value->getPivot()
                : (property_exists($value, 'pivot') ? $value->pivot : null);

            if ($texture !== null) {
                $normalizedSprite['texture'] = $this->normalizeEditorValue($texture);
            }

            if ($rect !== null) {
                $normalizedSprite['rect'] = $this->normalizeEditorValue($rect);
            }

            if ($pivot !== null) {
                $normalizedSprite['pivot'] = $this->normalizeEditorValue($pivot);
            }

            if ($normalizedSprite !== []) {
                return $normalizedSprite;
            }
        }

        if (is_a($value, '\Sendama\Engine\Core\Texture')) {
            $path = method_exists($value, 'getPath')
                ? $value->getPath()
                : (property_exists($value, 'path') ? $value->path : null);
            $path = is_string($path) ? trim($path) : '';

            if ($path !== '') {
                $normalizedTexture = ['path' => $path];
                $requestedWidth = method_exists($value, 'getRequestedWidth') ? $value->getRequestedWidth() : null;
                $requestedHeight = method_exists($value, 'getRequestedHeight') ? $value->getRequestedHeight() : null;
                $color = method_exists($value, 'getColor') ? $value->getColor() : null;

                if (is_int($requestedWidth) && $requestedWidth > 0) {
                    $normalizedTexture['width'] = $requestedWidth;
                }

                if (is_int($requestedHeight) && $requestedHeight > 0) {
                    $normalizedTexture['height'] = $requestedHeight;
                }

                if ($color !== null) {
                    $normalizedTexture['color'] = $this->normalizeEditorValue($color);
                }

                return count($normalizedTexture) === 1
                    ? $normalizedTexture['path']
                    : $normalizedTexture;
            }
        }

        if (
            (is_a($value, '\Sendama\Engine\Core\Rect')
                || (method_exists($value, 'getWidth') && method_exists($value, 'getHeight')))
            && method_exists($value, 'getX')
            && method_exists($value, 'getY')
            && method_exists($value, 'getWidth')
            && method_exists($value, 'getHeight')
        ) {
            return [
                'x' => $this->normalizeEditorValue($value->getX()),
                'y' => $this->normalizeEditorValue($value->getY()),
                'width' => $this->normalizeEditorValue($value->getWidth()),
                'height' => $this->normalizeEditorValue($value->getHeight()),
            ];
        }

        if (method_exists($value, 'getX') && method_exists($value, 'getY')) {
            return [
                'x' => $this->normalizeEditorValue($value->getX()),
                'y' => $this->normalizeEditorValue($value->getY()),
            ];
        }

        if (method_exists($value, 'getName')) {
            try {
                return $value->getName();
            } catch (Throwable) {
            }
        }

        if (method_exists($value, '__serialize')) {
            try {
                $serialized = $value->__serialize();

                return is_array($serialized)
                    ? $this->normalizeEditorValue($serialized)
                    : $this->normalizeEditorValue((array) $serialized);
            } catch (Throwable) {
            }
        }

        $compoundValue = $this->extractCompoundEditorValue($value);

        if (is_array($compoundValue)) {
            return $compoundValue;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return get_class($value);
    }

    private function extractCompoundEditorValue(object $value): ?array
    {
        $valueClass = $value::class;

        if (
            is_a($valueClass, 'Sendama\\Engine\\Core\\Component', true)
            || is_a($valueClass, 'Sendama\\Engine\\Core\\GameObject', true)
            || is_a($valueClass, self::UI_ELEMENT_TYPE, true)
        ) {
            return null;
        }

        try {
            $reflection = new ReflectionObject($value);
        } catch (Throwable) {
            return null;
        }

        $normalized = [];

        foreach ($reflection->getProperties() as $property) {
            if (
                $property->isStatic()
                || (!$property->isPublic() && $property->getAttributes('Sendama\\Engine\\Core\\Behaviours\\Attributes\\SerializeField') === [])
                || (method_exists($property, 'isVirtual') && $property->isVirtual())
            ) {
                continue;
            }

            try {
                $normalized[$property->getName()] = $this->normalizeEditorValue($property->getValue($value));
            } catch (Throwable) {
                continue;
            }
        }

        return $normalized !== [] ? $normalized : null;
    }

    private function normalizeTextureComponentFieldValue(mixed $value): string
    {
        if (is_string($value)) {
            $normalizedValue = trim($value);

            return $normalizedValue !== '' ? $normalizedValue : 'None';
        }

        if (is_array($value)) {
            $path = $value['path'] ?? null;

            return is_string($path) && trim($path) !== ''
                ? trim($path)
                : 'None';
        }

        if (is_object($value)) {
            $path = $value->path ?? null;

            return is_string($path) && trim($path) !== ''
                ? trim($path)
                : 'None';
        }

        return 'None';
    }

    private function buildUniqueComponentMenuLabel(string $baseLabel, string $componentClass, array &$usedLabels): string
    {
        if (!isset($usedLabels[$baseLabel])) {
            $usedLabels[$baseLabel] = true;
            return $baseLabel;
        }

        $uniqueLabel = $baseLabel . ' (' . ltrim($componentClass, '\\') . ')';
        $usedLabels[$uniqueLabel] = true;

        return $uniqueLabel;
    }

    private function appendComponentToInspectionTarget(array $componentDefinition): void
    {
        if (
            !is_array($this->inspectionTarget)
            || !in_array($this->inspectionTarget['context'] ?? null, ['hierarchy', 'prefab'], true)
            || !is_string($this->inspectionTarget['path'] ?? null)
            || !is_array($this->inspectionTarget['value'] ?? null)
        ) {
            return;
        }

        $componentClass = is_string($componentDefinition['class'] ?? null)
            ? $componentDefinition['class']
            : null;

        if ($componentClass === null || $componentClass === '') {
            return;
        }

        $inspectionValue = $this->inspectionTarget['value'];
        $inspectionValue['components'] = is_array($inspectionValue['components'] ?? null)
            ? array_values($inspectionValue['components'])
            : [];

        $componentEntry = ['class' => $componentClass];

        if (array_key_exists('data', $componentDefinition) && is_array($componentDefinition['data'])) {
            $componentEntry['data'] = $componentDefinition['data'];
        }

        if (array_key_exists('fieldTypes', $componentDefinition) && is_array($componentDefinition['fieldTypes'])) {
            $componentEntry['__editorFieldTypes'] = $componentDefinition['fieldTypes'];
        }

        $inspectionValue['components'][] = $componentEntry;
        $updatedTarget = $this->inspectionTarget;
        $updatedTarget['value'] = $inspectionValue;
        $this->inspectTarget($updatedTarget);
        $this->queueObjectInspectionMutation($updatedTarget, $inspectionValue);
    }

    private function getSelectedControlMetadata(?InputControl $control): array
    {
        if (!$control instanceof InputControl) {
            return [];
        }

        return $this->controlMetadata[spl_object_id($control)] ?? [];
    }

    private function captureSelectedControlSnapshot(?InputControl $control): array
    {
        if (!$control instanceof InputControl) {
            return [];
        }

        $snapshot = [
            'class' => $control::class,
            'label' => $control->getLabel(),
        ];
        $bindingPath = $this->controlBindings[spl_object_id($control)] ?? null;
        $metadata = $this->getSelectedControlMetadata($control);

        if (is_array($bindingPath) && $bindingPath !== []) {
            $snapshot['bindingPath'] = $bindingPath;
        }

        if ($metadata !== []) {
            $snapshot['metadata'] = $metadata;
        }

        return $snapshot;
    }

    private function shouldPreserveSelectedControl(?array $currentTarget, ?array $nextTarget): bool
    {
        return $this->resolveInspectionIdentity($currentTarget) !== null
            && $this->resolveInspectionIdentity($currentTarget) === $this->resolveInspectionIdentity($nextTarget);
    }

    private function resolveInspectionIdentity(?array $target): ?string
    {
        if (!is_array($target)) {
            return null;
        }

        $context = $target['context'] ?? null;

        if (!is_string($context) || $context === '') {
            return null;
        }

        $path = $target['path'] ?? null;

        if (is_string($path) && $path !== '') {
            return $context . ':' . $path;
        }

        $value = $target['value'] ?? null;
        $valuePath = is_array($value) ? ($value['path'] ?? null) : null;

        if (is_string($valuePath) && $valuePath !== '') {
            return $context . ':' . $valuePath;
        }

        $name = $target['name'] ?? null;

        if (is_string($name) && $name !== '') {
            return $context . ':' . $name;
        }

        return null;
    }

    private function restoreSelectedControlSnapshot(array $snapshot): bool
    {
        if ($snapshot === [] || $this->focusableControls === []) {
            return false;
        }

        $bindingPath = $snapshot['bindingPath'] ?? null;

        if (is_array($bindingPath) && $bindingPath !== []) {
            foreach ($this->focusableControls as $index => $control) {
                $candidateBindingPath = $this->controlBindings[spl_object_id($control)] ?? null;

                if ($candidateBindingPath === $bindingPath) {
                    $this->selectControlByIndex($index);
                    return true;
                }
            }
        }

        $metadata = $snapshot['metadata'] ?? null;

        if (is_array($metadata) && $metadata !== []) {
            foreach ($this->focusableControls as $index => $control) {
                $candidateMetadata = $this->getSelectedControlMetadata($control);

                if ($candidateMetadata === $metadata) {
                    $this->selectControlByIndex($index);
                    return true;
                }
            }
        }

        $label = $snapshot['label'] ?? null;
        $class = $snapshot['class'] ?? null;

        if (!is_string($label) || $label === '' || !is_string($class) || $class === '') {
            return false;
        }

        foreach ($this->focusableControls as $index => $control) {
            if ($control instanceof $class && $control->getLabel() === $label) {
                $this->selectControlByIndex($index);
                return true;
            }
        }

        return false;
    }

    private function isSelectedComponentHeader(?InputControl $control): bool
    {
        if (!$control instanceof SectionControl) {
            return false;
        }

        $metadata = $this->getSelectedControlMetadata($control);

        return ($metadata['kind'] ?? null) === 'component_header'
            && is_int($metadata['componentIndex'] ?? null);
    }

    private function handleComponentMoveModeToggle(InputControl $selectedControl): void
    {
        if (!$this->isSelectedComponentHeader($selectedControl) || !$this->canMutateCurrentComponentList()) {
            $this->isComponentMoveModeActive = false;
            return;
        }

        $this->isComponentMoveModeActive = !$this->isComponentMoveModeActive;
    }

    private function canMutateCurrentComponentList(): bool
    {
        return is_array($this->inspectionTarget)
            && in_array($this->inspectionTarget['context'] ?? null, ['hierarchy', 'prefab'], true)
            && is_string($this->inspectionTarget['path'] ?? null)
            && $this->inspectionTarget['path'] !== '';
    }

    private function showDeleteComponentModal(InputControl $selectedControl): void
    {
        $metadata = $this->getSelectedControlMetadata($selectedControl);
        $componentIndex = $metadata['componentIndex'] ?? null;

        if (!is_int($componentIndex)) {
            return;
        }

        $inspectionValue = is_array($this->inspectionTarget['value'] ?? null)
            ? $this->inspectionTarget['value']
            : [];
        $components = is_array($inspectionValue['components'] ?? null)
            ? array_values($inspectionValue['components'])
            : [];
        $component = $components[$componentIndex] ?? null;

        if (!is_array($component)) {
            return;
        }

        $componentName = $this->resolveClassName($component['class'] ?? null, 'this component');
        $this->pendingComponentDeletionIndex = $componentIndex;
        $this->isComponentMoveModeActive = false;
        $this->deleteComponentModal->show(
            ['Delete', 'Cancel'],
            1,
            'Remove ' . $componentName . ' from this object?'
        );
        $terminalSize = get_max_terminal_size();
        $terminalWidth = $terminalSize['width'] ?? DEFAULT_TERMINAL_WIDTH;
        $terminalHeight = $terminalSize['height'] ?? DEFAULT_TERMINAL_HEIGHT;
        $this->syncModalLayout($terminalWidth, $terminalHeight);
    }

    private function removeComponentAtIndex(int $componentIndex): void
    {
        if (
            !is_array($this->inspectionTarget)
            || !in_array($this->inspectionTarget['context'] ?? null, ['hierarchy', 'prefab'], true)
            || !is_string($this->inspectionTarget['path'] ?? null)
            || !is_array($this->inspectionTarget['value'] ?? null)
        ) {
            return;
        }

        $inspectionValue = $this->inspectionTarget['value'];
        $components = is_array($inspectionValue['components'] ?? null)
            ? array_values($inspectionValue['components'])
            : [];

        if (!array_key_exists($componentIndex, $components)) {
            return;
        }

        array_splice($components, $componentIndex, 1);
        $inspectionValue['components'] = array_values($components);

        $nextComponentIndex = $components === []
            ? null
            : min($componentIndex, count($components) - 1);

        $this->rebuildHierarchyInspection($inspectionValue, $nextComponentIndex);
    }

    private function moveSelectedComponent(int $direction): void
    {
        $selectedControl = $this->getSelectedControl();
        $metadata = $this->getSelectedControlMetadata($selectedControl);
        $componentIndex = $metadata['componentIndex'] ?? null;

        if (
            !is_int($componentIndex)
            || !in_array($direction, [-1, 1], true)
            || !is_array($this->inspectionTarget)
            || !in_array($this->inspectionTarget['context'] ?? null, ['hierarchy', 'prefab'], true)
            || !is_array($this->inspectionTarget['value'] ?? null)
        ) {
            return;
        }

        $inspectionValue = $this->inspectionTarget['value'];
        $components = is_array($inspectionValue['components'] ?? null)
            ? array_values($inspectionValue['components'])
            : [];
        $componentCount = count($components);

        if ($componentCount < 2 || !array_key_exists($componentIndex, $components)) {
            return;
        }

        $targetIndex = ($componentIndex + $direction + $componentCount) % $componentCount;
        $component = $components[$componentIndex];
        array_splice($components, $componentIndex, 1);
        array_splice($components, $targetIndex, 0, [$component]);
        $inspectionValue['components'] = array_values($components);

        $this->rebuildHierarchyInspection($inspectionValue, $targetIndex, true);
    }

    private function rebuildHierarchyInspection(
        array $inspectionValue,
        ?int $focusComponentIndex = null,
        bool $preserveMoveMode = false,
    ): void
    {
        if (
            !is_array($this->inspectionTarget)
            || !in_array($this->inspectionTarget['context'] ?? null, ['hierarchy', 'prefab'], true)
            || !is_string($this->inspectionTarget['path'] ?? null)
        ) {
            return;
        }

        $updatedTarget = $this->inspectionTarget;
        $updatedTarget['value'] = $inspectionValue;
        $updatedTarget['name'] = $inspectionValue['name'] ?? ($updatedTarget['name'] ?? 'Unnamed Object');
        $updatedTarget['type'] = $this->resolveDisplayType($updatedTarget, $inspectionValue);
        $this->inspectTarget($updatedTarget);

        if (is_int($focusComponentIndex)) {
            $this->focusComponentHeaderByIndex($focusComponentIndex);
        }

        $this->isComponentMoveModeActive = $preserveMoveMode && is_int($focusComponentIndex);
        $this->queueObjectInspectionMutation($updatedTarget, $inspectionValue);
    }

    private function focusComponentHeaderByIndex(int $componentIndex): void
    {
        foreach ($this->focusableControls as $index => $control) {
            if (!$control instanceof InputControl) {
                continue;
            }

            $metadata = $this->getSelectedControlMetadata($control);

            if (
                ($metadata['kind'] ?? null) === 'component_header'
                && ($metadata['componentIndex'] ?? null) === $componentIndex
            ) {
                $this->selectControlByIndex($index);
                return;
            }
        }
    }

    private function selectControlByIndex(int $index): void
    {
        if (!isset($this->focusableControls[$index])) {
            return;
        }

        $this->selectedControlIndex = $index;
        $this->applyControlSelection();

        if (!$this->isSelectedComponentHeader($this->getSelectedControl())) {
            $this->isComponentMoveModeActive = false;
        }

        $this->refreshContent();
    }

    private function resolveControlIndexFromPoint(int $x, int $y): ?int
    {
        $contentIndex = $this->resolveContentIndexFromPointY($y);

        if (!is_int($contentIndex)) {
            return null;
        }

        $controlIndex = $this->lineControlIndexes[$contentIndex] ?? null;

        return is_int($controlIndex) ? $controlIndex : null;
    }

    private function resolveSelectedContentIndex(): ?int
    {
        if ($this->selectedControlIndex === null) {
            return null;
        }

        foreach ($this->lineControlIndexes as $contentIndex => $controlIndex) {
            if ($controlIndex === $this->selectedControlIndex) {
                return $contentIndex;
            }
        }

        return null;
    }

    private function registerControlClickAndCheckDoubleClick(int $controlIndex): bool
    {
        $now = microtime(true);
        $isDoubleClick = $this->lastClickedControlIndex === $controlIndex
            && ($now - $this->lastClickedControlAt) <= self::DOUBLE_CLICK_THRESHOLD_SECONDS;

        $this->lastClickedControlIndex = $controlIndex;
        $this->lastClickedControlAt = $now;

        return $isDoubleClick;
    }

    private function buildTexturePreviewLines(string $texturePath, array $offset, array $size, bool $naturalSizeFallback = true): array
    {
        if ($texturePath === 'None') {
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

        $offsetX = max(0, (int) ($offset['x'] ?? 0));
        $offsetY = max(0, (int) ($offset['y'] ?? 0));
        $previewWidth = (int) ($size['x'] ?? 0);
        $previewHeight = (int) ($size['y'] ?? 0);

        if ($naturalSizeFallback && $previewWidth <= 0) {
            $previewWidth = $this->resolveTextureRowWidth($textureRows) - $offsetX;
        }

        if ($naturalSizeFallback && $previewHeight <= 0) {
            $previewHeight = count($textureRows) - $offsetY;
        }

        if (!$naturalSizeFallback) {
            $previewWidth = max(1, $previewWidth);
            $previewHeight = max(1, $previewHeight);
        }

        if ($previewWidth <= 0 || $previewHeight <= 0) {
            return ['[unavailable]'];
        }

        if (count($textureRows) <= 1) {
            $textureRows = $this->expandSingleLineTexture(
                $textureRows[0] ?? '',
                $previewWidth
            );
        }

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

    private function registerTexturePreview(
        PathInputControl $textureControl,
        VectorInputControl $sizeControl,
        PreviewWindowControl $previewControl,
        ?VectorInputControl $offsetControl = null,
        bool $naturalSizeFallback = true,
    ): void {
        $this->texturePreviewRegistrations[] = [
            'texture' => $textureControl,
            'size' => $sizeControl,
            'preview' => $previewControl,
            'offset' => $offsetControl,
            'naturalSizeFallback' => $naturalSizeFallback,
        ];
    }

    private function resolveInspectableSize(array $item): array
    {
        $size = $this->normalizeVector($item['size'] ?? null);

        if ($this->isGuiTextureItem($item)) {
            return $this->normalizeGuiTextureSize($size);
        }

        return $size;
    }

    private function normalizeGuiTextureSize(array $size): array
    {
        return [
            'x' => max(1, (int) ($size['x'] ?? 0)),
            'y' => max(1, (int) ($size['y'] ?? 0)),
        ];
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

    private function isGuiTextureItem(array $item): bool
    {
        $type = $item['type'] ?? null;

        if (!is_string($type) || $type === '') {
            return false;
        }

        $normalizedType = ltrim($type, '\\');
        $normalizedType = preg_replace('/::class$/', '', $normalizedType) ?? $normalizedType;

        return $normalizedType === self::GUI_TEXTURE_TYPE;
    }

    private function resolveGuiTextureColorOptions(): array
    {
        return self::GUI_TEXTURE_COLOR_OPTIONS;
    }

    private function normalizeGuiTextureColor(mixed $value): string
    {
        if (enum_exists(EngineColor::class) && $value instanceof EngineColor) {
            return $value->getPhoneticName();
        }

        if (!is_string($value) || trim($value) === '') {
            return 'White';
        }

        $normalizedColor = strtoupper(str_replace([' ', '-'], '_', trim($value)));

        if (enum_exists(EngineColor::class)) {
            foreach (EngineColor::cases() as $color) {
                $normalizedCaseName = strtoupper($color->name);
                $normalizedPhoneticName = strtoupper(str_replace([' ', '-'], '_', $color->getPhoneticName()));
                $normalizedEscapeValue = strtoupper(trim($color->value));

                if (
                    $normalizedColor === $normalizedCaseName
                    || $normalizedColor === $normalizedPhoneticName
                    || $normalizedColor === $normalizedEscapeValue
                ) {
                    return $color === EngineColor::RESET
                        ? 'White'
                        : $color->getPhoneticName();
                }
            }
        }

        foreach (self::GUI_TEXTURE_COLOR_OPTIONS as $colorLabel) {
            if (strtoupper(str_replace([' ', '-'], '_', $colorLabel)) === $normalizedColor) {
                return $colorLabel;
            }
        }

        return 'White';
    }

    private function resolvePrefabDisplayLabelsByPath(): array
    {
        $displayLabelsByPath = [];

        foreach ($this->resolveAvailablePrefabOptions() as $prefabOption) {
            $path = $prefabOption['path'] ?? null;
            $label = $prefabOption['display'] ?? null;

            if (is_string($path) && $path !== '' && is_string($label) && $label !== '') {
                $displayLabelsByPath[$path] = $label;
            }
        }

        return $displayLabelsByPath;
    }

    private function resolveAvailablePrefabOptions(): array
    {
        $prefabsDirectory = Path::join($this->resolveAssetsWorkingDirectory(), 'Prefabs');

        if (!is_dir($prefabsDirectory)) {
            return [];
        }

        $prefabLoader = new PrefabLoader($this->projectDirectory);
        $prefabOptions = [];
        $usedLabels = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($prefabsDirectory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $fileName = $file->getFilename();

            if (!is_string($fileName) || !str_ends_with(strtolower($fileName), '.prefab.php')) {
                continue;
            }

            $absolutePath = $file->getPathname();
            $relativePath = $this->buildRelativePrefabPath($absolutePath);

            if ($relativePath === null) {
                continue;
            }

            $prefabData = $prefabLoader->load($absolutePath);
            $displayName = is_array($prefabData) && is_string($prefabData['name'] ?? null) && $prefabData['name'] !== ''
                ? $prefabData['name']
                : basename($relativePath);
            $displayLabel = $this->buildUniquePrefabOptionLabel(
                $displayName,
                basename($relativePath),
                $usedLabels,
            );

            $prefabOptions[$displayLabel] = [
                'path' => $relativePath,
                'display' => $displayLabel,
                'name' => $displayName,
            ];
        }

        ksort($prefabOptions);

        return $prefabOptions;
    }

    private function resolveUIElementDisplayLabelsByName(?string $fieldType = null): array
    {
        $displayLabelsByName = [];

        foreach ($this->resolveAvailableUIElementReferenceOptions($fieldType) as $uiElementOption) {
            $name = $uiElementOption['name'] ?? null;
            $label = $uiElementOption['display'] ?? null;

            if (is_string($name) && $name !== '' && is_string($label) && $label !== '') {
                $displayLabelsByName[$name] = $label;
            }
        }

        return $displayLabelsByName;
    }

    private function resolveAvailableUIElementReferenceOptions(?string $fieldType = null): array
    {
        if ($this->sceneHierarchy === []) {
            return [];
        }

        $normalizedFieldType = is_string($fieldType) ? $fieldType : self::UI_ELEMENT_TYPE;
        $options = [];
        $usedLabels = [];

        $this->collectAvailableUIElementReferenceOptions(
            $this->sceneHierarchy,
            $normalizedFieldType,
            $options,
            $usedLabels,
        );

        ksort($options);

        return $options;
    }

    private function collectAvailableUIElementReferenceOptions(
        array $hierarchy,
        string $fieldType,
        array &$options,
        array &$usedLabels,
    ): void {
        foreach ($hierarchy as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemType = is_string($item['type'] ?? null)
                ? ltrim(trim((string) $item['type']), '\\')
                : null;
            $itemName = is_string($item['name'] ?? null)
                ? trim((string) $item['name'])
                : '';

            if (
                is_string($itemType)
                && $itemName !== ''
                && $this->isAssignableSceneUIElementType($itemType, $fieldType)
            ) {
                $labelBase = sprintf('%s (%s)', $itemName, $this->shortTypeName($itemType));
                $label = $this->buildUniqueReferenceOptionLabel($labelBase, $usedLabels);

                $options[$label] = [
                    'name' => $itemName,
                    'type' => $itemType,
                    'display' => $label,
                ];
            }

            $children = $item['children'] ?? null;

            if (is_array($children) && $children !== []) {
                $this->collectAvailableUIElementReferenceOptions($children, $fieldType, $options, $usedLabels);
            }
        }
    }

    private function resolveSelectedControlAssignableUIElementType(InputControl $control): ?string
    {
        $controlMetadata = $this->getSelectedControlMetadata($control);
        $fieldTypeFromMetadata = is_string($controlMetadata['fieldType'] ?? null)
            ? $controlMetadata['fieldType']
            : $this->resolveFieldSchemaType(
                is_array($controlMetadata['fieldSchema'] ?? null)
                    ? $controlMetadata['fieldSchema']
                    : [],
            );

        if (is_string($fieldTypeFromMetadata) && trim($fieldTypeFromMetadata) !== '') {
            return $this->resolveAssignableUIElementFieldType($fieldTypeFromMetadata) ?? self::UI_ELEMENT_TYPE;
        }

        $controlPath = $this->controlBindings[spl_object_id($control)] ?? null;

        if (!is_array($controlPath) || $controlPath === []) {
            return self::UI_ELEMENT_TYPE;
        }

        $fieldTypes = $this->resolveCurrentInspectionComponentFieldTypes();
        $resolvedFieldType = $this->resolveFieldTypeForControlPath($fieldTypes, $controlPath);

        return $this->resolveAssignableUIElementFieldType($resolvedFieldType) ?? self::UI_ELEMENT_TYPE;
    }

    private function resolveCurrentInspectionComponentFieldTypes(): array
    {
        if (!is_array($this->inspectionTarget)) {
            return [];
        }

        $value = $this->inspectionTarget['value'] ?? null;

        if (!is_array($value)) {
            return [];
        }

        $components = $value['components'] ?? null;

        if (!is_array($components)) {
            return [];
        }

        $fieldTypes = [
            'components' => [],
        ];

        foreach ($components as $index => $component) {
            if (!is_array($component)) {
                continue;
            }

            $fieldTypes['components'][$index] = [
                'data' => is_array($component['__editorFieldTypes'] ?? null)
                    ? $component['__editorFieldTypes']
                    : [],
            ];
        }

        return $fieldTypes;
    }

    private function resolveFieldTypeForControlPath(array $fieldTypes, array $controlPath): ?string
    {
        $current = $fieldTypes;

        foreach ($controlPath as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
                continue;
            }

            return null;
        }

        return is_string($current) ? $current : null;
    }

    private function isAssignableSceneUIElementType(string $itemType, string $fieldType): bool
    {
        if ($fieldType === self::UI_ELEMENT_TYPE || $fieldType === self::UI_ELEMENT_INTERFACE_TYPE) {
            if ($this->isKnownEngineUIElementType($itemType)) {
                return true;
            }
        }

        if ($itemType === $fieldType) {
            return true;
        }

        if (!(class_exists($itemType) || interface_exists($itemType))) {
            return false;
        }

        if ($fieldType === self::UI_ELEMENT_TYPE || $fieldType === self::UI_ELEMENT_INTERFACE_TYPE) {
            return is_a($itemType, self::UI_ELEMENT_TYPE, true);
        }

        return is_a($itemType, $fieldType, true);
    }

    private function isKnownEngineUIElementType(string $type): bool
    {
        $normalizedType = ltrim(trim($type), '\\');

        if ($normalizedType === '' || str_contains($normalizedType, '\\Interfaces\\')) {
            return false;
        }

        return str_starts_with($normalizedType, 'Sendama\\Engine\\UI\\');
    }

    private function buildUniqueReferenceOptionLabel(string $baseLabel, array &$usedLabels): string
    {
        $label = $baseLabel;
        $suffix = 2;

        while (isset($usedLabels[$label])) {
            $label = sprintf('%s #%d', $baseLabel, $suffix);
            $suffix++;
        }

        $usedLabels[$label] = true;

        return $label;
    }

    private function shortTypeName(string $type): string
    {
        $normalizedType = ltrim(trim($type), '\\');
        $segments = explode('\\', $normalizedType);

        return $segments[array_key_last($segments)] ?? $normalizedType;
    }

    private function buildRelativePrefabPath(string $absolutePath): ?string
    {
        $assetsDirectory = $this->resolveAssetsWorkingDirectory();
        $normalizedAssetsDirectory = rtrim(str_replace('\\', '/', $assetsDirectory), '/');
        $normalizedAbsolutePath = str_replace('\\', '/', $absolutePath);

        if (!str_starts_with($normalizedAbsolutePath, $normalizedAssetsDirectory . '/')) {
            return null;
        }

        return substr($normalizedAbsolutePath, strlen($normalizedAssetsDirectory) + 1) ?: null;
    }

    private function buildUniquePrefabOptionLabel(string $displayName, string $fileName, array &$usedLabels): string
    {
        $label = $displayName;

        if (!isset($usedLabels[$label])) {
            $usedLabels[$label] = true;
            return $label;
        }

        $label = $displayName . ' (' . $fileName . ')';

        if (!isset($usedLabels[$label])) {
            $usedLabels[$label] = true;
            return $label;
        }

        $suffix = 2;

        while (isset($usedLabels[$label . ' #' . $suffix])) {
            $suffix++;
        }

        $label .= ' #' . $suffix;
        $usedLabels[$label] = true;

        return $label;
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

    private function resolveTextureRowWidth(array $textureRows): int
    {
        $maxWidth = 0;

        foreach ($textureRows as $textureRow) {
            $maxWidth = max(
                $maxWidth,
                function_exists('mb_strlen')
                    ? mb_strlen((string) $textureRow, 'UTF-8')
                    : strlen((string) $textureRow),
            );
        }

        return $maxWidth;
    }

    private function humanizeKey(string $key): string
    {
        $spacedKey = preg_replace('/(?<!^)([A-Z])/', ' $1', $key) ?? $key;
        $spacedKey = str_replace(['_', '-'], ' ', $spacedKey);

        return ucwords(trim($spacedKey));
    }

    private function applyControlValueToInspectionTarget(InputControl $control): void
    {
        if (!is_array($this->inspectionTarget)) {
            return;
        }

        $controlMetadata = $this->getSelectedControlMetadata($control);
        $context = $this->inspectionTarget['context'] ?? null;

        if (
            ($controlMetadata['kind'] ?? null) === 'prefab_file_name'
            && $context === 'prefab'
            && is_array($this->inspectionTarget['asset'] ?? null)
        ) {
            $asset = $this->inspectionTarget['asset'];
            $asset['name'] = (string) $control->getValue();
            $this->inspectionTarget['asset'] = $asset;
            $this->pendingAssetMutation = [
                'path' => $asset['path'] ?? null,
                'relativePath' => $asset['relativePath'] ?? null,
                'name' => (string) $control->getValue(),
                'activatePrefab' => true,
            ];
            return;
        }

        if (
            !isset($this->inspectionTarget['value'])
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

        if ($context === 'asset') {
            if (
                $valuePath === ['name']
                && !($inspectionValue['isDirectory'] ?? false)
                && is_string($inspectionValue['path'] ?? null)
            ) {
                $this->pendingAssetMutation = [
                    'path' => $inspectionValue['path'],
                    'relativePath' => $inspectionValue['relativePath'] ?? basename($inspectionValue['path']),
                    'name' => (string) $control->getValue(),
                ];
            }

            return;
        }

        if (!in_array($context, ['hierarchy', 'scene'], true)) {
            if ($context === 'prefab') {
                $this->queueObjectInspectionMutation($this->inspectionTarget, $inspectionValue);
            }

            return;
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

    private function resolveVisibleControlIndexes(): array
    {
        $visibleControlIndexes = [];
        $collapsedSectionIndentLevels = [];

        foreach ($this->elements as $element) {
            $control = $element['control'] ?? null;

            if (!$control instanceof InputControl) {
                continue;
            }

            $controlIndentLevel = $control->getIndentLevel();

            while (
                $collapsedSectionIndentLevels !== []
                && $controlIndentLevel <= end($collapsedSectionIndentLevels)
            ) {
                array_pop($collapsedSectionIndentLevels);
            }

            if ($collapsedSectionIndentLevels !== []) {
                continue;
            }

            $controlIndex = array_search($control, $this->focusableControls, true);

            if (is_int($controlIndex)) {
                $visibleControlIndexes[] = $controlIndex;
            }

            if ($control instanceof SectionControl && $control->isCollapsed()) {
                $collapsedSectionIndentLevels[] = $controlIndentLevel;
            }
        }

        return $visibleControlIndexes;
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
        return Path::resolveAssetsDirectory($this->projectDirectory);
    }

}

<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\IO\Enumerations\Color;
use Sendama\Console\Editor\FocusTargetContext;
use Sendama\Console\Editor\IO\Enumerations\KeyCode;
use Sendama\Console\Editor\IO\Input;
use Sendama\Console\Editor\Widgets\Controls\CompoundInputControl;
use Sendama\Console\Editor\Widgets\Controls\InputControl;
use Sendama\Console\Editor\Widgets\Controls\InputControlFactory;
use Sendama\Console\Editor\Widgets\Controls\PreviewWindowControl;
use Sendama\Console\Editor\Widgets\Controls\TextInputControl;
use Sendama\Console\Editor\Widgets\Controls\VectorInputControl;

class InspectorPanel extends Widget
{
    private const string STATE_CONTROL_SELECTION = 'control_selection';
    private const string STATE_PROPERTY_SELECTION = 'property_selection';
    private const string STATE_CONTROL_EDIT = 'control_edit';
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
    protected ?TextInputControl $rendererTextureControl = null;
    protected ?VectorInputControl $rendererOffsetControl = null;
    protected ?VectorInputControl $rendererSizeControl = null;
    protected ?PreviewWindowControl $rendererPreviewControl = null;

    public function __construct(
        array $position = ['x' => 135, 'y' => 1],
        int $width = 35,
        int $height = 29
    )
    {
        parent::__construct('Inspector', '', $position, $width, $height);
        $this->inputControlFactory = new InputControlFactory();
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
        if (!$this->hasFocus() || $this->selectedControlIndex === null) {
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
        $this->addControl(new TextInputControl('Name', $item['name'] ?? $target['name'] ?? 'Unnamed Object', 0));
        $this->addControl(new TextInputControl('Tag', $item['tag'] ?? 'None', 0));

        $this->addSectionHeader('Transform');
        $this->addControl(new VectorInputControl('Position', $this->normalizeVector($item['position'] ?? null), 1));
        $this->addControl(new VectorInputControl('Rotation', $this->normalizeVector($item['rotation'] ?? null), 1));
        $this->addControl(new VectorInputControl('Scale', $this->normalizeVector($item['scale'] ?? ['x' => 1, 'y' => 1]), 1));

        if (isset($item['size']) && is_array($item['size'])) {
            $this->addControl(new VectorInputControl('Size', $this->normalizeVector($item['size']), 1));
        }

        $this->addSectionHeader('Renderer');
        $this->addRendererControls($item);
        $this->addScriptComponents($item['components'] ?? []);
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

        $this->rendererTextureControl = new TextInputControl('Texture', $texturePath, 1);
        $this->rendererOffsetControl = new VectorInputControl('Offset', $offset, 1);
        $this->rendererSizeControl = new VectorInputControl('Size', $size, 1);
        $this->rendererPreviewControl = new PreviewWindowControl(
            'Preview',
            $this->buildTexturePreviewLines($texturePath, $offset, $size),
            1,
        );

        $this->addControl($this->rendererTextureControl);
        $this->addControl($this->rendererOffsetControl);
        $this->addControl($this->rendererSizeControl);
        $this->addControl($this->rendererPreviewControl);

        if (array_key_exists('text', $item)) {
            $this->addControl(new TextInputControl('Text', $item['text'], 1));
        }
    }

    private function addScriptComponents(mixed $components): void
    {
        if (!is_array($components)) {
            return;
        }

        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }

            $this->addSectionHeader($this->resolveClassName($component['class'] ?? null, 'Component'));

            foreach ($component as $key => $value) {
                if ($key === 'class') {
                    continue;
                }

                $this->addControl($this->inputControlFactory->create(
                    $this->humanizeKey((string) $key),
                    $value,
                    1,
                ));
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
            !$this->rendererTextureControl instanceof TextInputControl
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
            $selectedControl->commitActiveEdit();
            $this->interactionState = self::STATE_PROPERTY_SELECTION;
            return;
        }

        $selectedControl->commitEdit();
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
        $this->interactionState = self::STATE_CONTROL_SELECTION;
    }

    private function resetInteractionState(): void
    {
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

        $workingDirectory = getcwd() ?: '.';
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
}

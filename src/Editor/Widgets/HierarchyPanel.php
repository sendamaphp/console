<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\Events\MouseEvent;
use Atatusoft\Termutil\Events\Interfaces\ObservableInterface;
use Atatusoft\Termutil\Events\Traits\ObservableTrait;
use Atatusoft\Termutil\IO\Enumerations\Color;
use Sendama\Console\Editor\FocusTargetContext;
use Sendama\Console\Editor\Events\EditorEvent;
use Sendama\Console\Editor\Events\Enumerations\EventType;
use Sendama\Console\Editor\IO\Enumerations\KeyCode;
use Sendama\Console\Editor\IO\Input;

/**
 * HierarchyPanel class.
 *
 * @package
 */
class HierarchyPanel extends Widget implements ObservableInterface
{
    use ObservableTrait;

    private const float DOUBLE_CLICK_THRESHOLD_SECONDS = 0.35;
    private const string ROOT_PATH = 'scene';
    private const string ADD_MODAL_OBJECT_KIND = 'object_kind';
    private const string ADD_MODAL_OBJECT_PLACEMENT = 'object_placement';
    private const string ADD_MODAL_UI_KIND = 'ui_kind';
    private const string DELETE_MODAL_CONFIRM = 'delete_confirm';
    private const string HIERARCHY_MODE_SELECT = 'select';
    private const string HIERARCHY_MODE_MOVE = 'move';
    private const string COLLAPSED_ICON = '►';
    private const string EXPANDED_ICON = '▼';
    private const string LEAF_ICON = '•';
    private const string SELECTED_ROW_SEQUENCE = "\033[30;46m";
    private const string SELECTED_ROW_FOCUSED_SEQUENCE = "\033[5;30;46m";
    private const string MOVE_ROW_SEQUENCE = "\033[30;43m";
    private const string MOVE_ROW_FOCUSED_SEQUENCE = "\033[5;30;43m";
    private const string GAME_OBJECT_TYPE = 'Sendama\\Engine\\Core\\GameObject';
    private const string GUI_TEXTURE_TYPE = 'Sendama\\Engine\\UI\\GUITexture\\GUITexture';
    private const string LABEL_TYPE = 'Sendama\\Engine\\UI\\Label\\Label';
    private const string TEXT_TYPE = 'Sendama\\Engine\\UI\\Text\\Text';

    protected string $sceneName = 'Scene';
    protected bool $isSceneDirty = false;
    protected int $sceneWidth = DEFAULT_TERMINAL_WIDTH;
    protected int $sceneHeight = DEFAULT_TERMINAL_HEIGHT;
    protected string $environmentTileMapPath = 'Maps/example';
    protected string $environmentCollisionMapPath = '';
    protected array $hierarchy = [];
    protected array $visibleHierarchy = [];
    protected array $expandedPaths = [];
    protected ?string $selectedPath = null;
    protected array $selectedPaths = [];
    protected ?array $pendingInspectionItem = null;
    protected ?array $pendingCreationItem = null;
    protected ?array $pendingDeletionItem = null;
    protected ?array $pendingDuplicationItems = null;
    protected ?array $pendingPrefabCreationItem = null;
    protected ?array $pendingMoveItem = null;
    protected ?string $draggedPath = null;
    protected ?string $dragHoverPath = null;
    protected OptionListModal $addObjectModal;
    protected OptionListModal $addPlacementModal;
    protected OptionListModal $addUiElementModal;
    protected OptionListModal $deleteConfirmModal;
    protected ?string $addModalState = null;
    protected string $interactionMode = self::HIERARCHY_MODE_SELECT;
    protected ?string $lastClickedPath = null;
    protected float $lastClickedAt = 0.0;

    public function __construct(
        array $position = ['x' => 1, 'y' => 1],
        int $width = 35,
        int $height = 14,
        string $sceneName = 'Scene',
        bool $isSceneDirty = false,
        array $hierarchy = [],
        int $sceneWidth = DEFAULT_TERMINAL_WIDTH,
        int $sceneHeight = DEFAULT_TERMINAL_HEIGHT,
        string $environmentTileMapPath = 'Maps/example',
        string $environmentCollisionMapPath = '',
    )
    {
        $this->initializeObservers();
        parent::__construct('Hierarchy', '', $position, $width, $height);
        $this->addObjectModal = new OptionListModal(title: 'Add Object');
        $this->addPlacementModal = new OptionListModal(title: 'Place GameObject');
        $this->addUiElementModal = new OptionListModal(title: 'Add UI Element');
        $this->deleteConfirmModal = new OptionListModal(title: 'Delete Object');
        $this->sceneName = $sceneName;
        $this->isSceneDirty = $isSceneDirty;
        $this->sceneWidth = $sceneWidth;
        $this->sceneHeight = $sceneHeight;
        $this->environmentTileMapPath = $environmentTileMapPath;
        $this->environmentCollisionMapPath = $environmentCollisionMapPath;
        $this->setHierarchy($hierarchy);
    }

    public function getHierarchy(): array
    {
        return $this->hierarchy;
    }

    public function setHierarchy(array $hierarchy): void
    {
        $this->hierarchy = array_values($hierarchy);
        $this->expandedPaths = [self::ROOT_PATH => true];
        $this->selectedPath = self::ROOT_PATH;
        $this->selectedPaths = [self::ROOT_PATH];
        $this->refreshContent();

        $this->notify(new EditorEvent(EventType::HIERARCHY_CHANGED->value, $this));
    }

    public function syncHierarchy(array $hierarchy): void
    {
        $this->hierarchy = array_values($hierarchy);
        $this->refreshContent();
    }

    public function setSceneState(
        string $sceneName,
        bool $isDirty = false,
        ?int $sceneWidth = null,
        ?int $sceneHeight = null,
        ?string $environmentTileMapPath = null,
        ?string $environmentCollisionMapPath = null,
    ): void
    {
        $this->sceneName = $sceneName;
        $this->isSceneDirty = $isDirty;
        $this->sceneWidth = $sceneWidth ?? $this->sceneWidth;
        $this->sceneHeight = $sceneHeight ?? $this->sceneHeight;
        $this->environmentTileMapPath = $environmentTileMapPath ?? $this->environmentTileMapPath;
        $this->environmentCollisionMapPath = $environmentCollisionMapPath ?? $this->environmentCollisionMapPath;
        $this->refreshContent();
    }

    public function selectPath(?string $path): void
    {
        if ($path === null) {
            return;
        }

        $this->selectedPath = $path;
        $this->selectedPaths = [$path];
        $this->refreshContent();
    }

    public function selectPaths(array $paths, ?string $primaryPath = null): void
    {
        $normalizedPaths = array_values(array_unique(array_filter(
            $paths,
            static fn (mixed $path): bool => is_string($path) && $path !== ''
        )));

        if ($normalizedPaths === []) {
            return;
        }

        $this->selectedPath = is_string($primaryPath) && in_array($primaryPath, $normalizedPaths, true)
            ? $primaryPath
            : end($normalizedPaths);
        $this->selectedPaths = $normalizedPaths;
        $this->refreshContent();
    }

    public function getSelectedHierarchyObject(): ?array
    {
        $selectedNode = $this->getSelectedVisibleNode();

        if (($selectedNode['kind'] ?? null) !== 'object') {
            return null;
        }

        return $this->getSelectedVisibleNode()['item'] ?? null;
    }

    public function moveSelection(int $offset): void
    {
        if (!$this->visibleHierarchy) {
            return;
        }

        $selectedIndex = $this->getSelectedVisibleIndex() ?? 0;
        $nextIndex = max(0, min($selectedIndex + $offset, count($this->visibleHierarchy) - 1));
        $this->selectedPath = $this->visibleHierarchy[$nextIndex]['path'] ?? $this->selectedPath;
        $this->selectedPaths = $this->selectedPath !== null ? [$this->selectedPath] : [];
        $this->refreshContent();
    }

    public function expandSelection(): void
    {
        $selectedNode = $this->getSelectedVisibleNode();

        if (!$selectedNode || !$selectedNode['hasChildren']) {
            return;
        }

        if (!$selectedNode['isExpanded']) {
            $this->expandedPaths[$selectedNode['path']] = true;
            $this->refreshContent();
            return;
        }

        $selectedDepth = $selectedNode['depth'];
        $selectedPath = $selectedNode['path'];

        foreach ($this->visibleHierarchy as $entry) {
            if (
                str_starts_with($entry['path'], $selectedPath . '.')
                && $entry['depth'] === $selectedDepth + 1
            ) {
                $this->selectedPath = $entry['path'];
                $this->selectedPaths = $this->selectedPath !== null ? [$this->selectedPath] : [];
                $this->refreshContent();
                return;
            }
        }
    }

    public function collapseSelection(): void
    {
        $selectedNode = $this->getSelectedVisibleNode();

        if (!$selectedNode) {
            return;
        }

        if ($selectedNode['hasChildren'] && $selectedNode['isExpanded']) {
            unset($this->expandedPaths[$selectedNode['path']]);
            $this->refreshContent();
            return;
        }

        $parentPath = $this->getParentPath($selectedNode['path']);

        if ($parentPath === null) {
            return;
        }

        $this->selectedPath = $parentPath;
        $this->selectedPaths = [$parentPath];
        $this->refreshContent();
    }

    public function activateSelection(): void
    {
        $selectedNode = $this->getSelectedVisibleNode();

        if (($selectedNode['kind'] ?? null) === 'scene') {
            $this->pendingInspectionItem = [
                'context' => 'scene',
                'name' => $this->sceneName,
                'type' => 'Scene',
                'path' => self::ROOT_PATH,
                'value' => [
                    'name' => $this->sceneName,
                    'width' => $this->sceneWidth,
                    'height' => $this->sceneHeight,
                    'environmentTileMapPath' => $this->environmentTileMapPath,
                    'environmentCollisionMapPath' => $this->environmentCollisionMapPath,
                ],
            ];
            return;
        }

        if (($selectedNode['kind'] ?? null) !== 'object') {
            return;
        }

        $selectedItem = $this->getSelectedHierarchyObject();

        if ($selectedItem === null) {
            return;
        }

        $this->pendingInspectionItem = [
            'context' => 'hierarchy',
            'name' => $selectedItem['name'] ?? 'Unnamed Object',
            'type' => $this->resolveInspectableType($selectedItem),
            'path' => $selectedNode['path'] ?? null,
            'value' => $selectedItem,
        ];
    }

    public function consumeInspectionRequest(): ?array
    {
        $pendingInspectionItem = $this->pendingInspectionItem;
        $this->pendingInspectionItem = null;

        return $pendingInspectionItem;
    }

    public function consumeCreationRequest(): ?array
    {
        $pendingCreationItem = $this->pendingCreationItem;
        $this->pendingCreationItem = null;

        return $pendingCreationItem;
    }

    public function consumeDeletionRequest(): ?array
    {
        $pendingDeletionItem = $this->pendingDeletionItem;
        $this->pendingDeletionItem = null;

        return $pendingDeletionItem;
    }

    public function consumeDuplicationRequest(): ?array
    {
        $pendingDuplicationItems = $this->pendingDuplicationItems;
        $this->pendingDuplicationItems = null;

        return $pendingDuplicationItems;
    }

    public function consumePrefabCreationRequest(): ?array
    {
        $pendingPrefabCreationItem = $this->pendingPrefabCreationItem;
        $this->pendingPrefabCreationItem = null;

        return $pendingPrefabCreationItem;
    }

    public function consumeMoveRequest(): ?array
    {
        $pendingMoveItem = $this->pendingMoveItem;
        $this->pendingMoveItem = null;

        return $pendingMoveItem;
    }

    public function expandPath(string $path): void
    {
        $this->expandedPaths[$path] = true;
        $this->refreshContent();
    }

    public function beginAddWorkflow(): void
    {
        $this->showAddObjectModal();
    }

    public function beginPrefabCreationWorkflow(): void
    {
        $selectedNode = $this->getSelectedVisibleNode();

        if (($selectedNode['kind'] ?? null) !== 'object') {
            return;
        }

        $selectedItem = $selectedNode['item'] ?? null;

        if (!is_array($selectedItem)) {
            return;
        }

        $this->pendingPrefabCreationItem = [
            'path' => $selectedNode['path'] ?? null,
            'name' => $selectedItem['name'] ?? 'Prefab',
            'value' => $selectedItem,
        ];
    }

    public function beginDuplicationWorkflow(): void
    {
        $selectedItems = [];

        foreach ($this->visibleHierarchy as $entry) {
            $path = $entry['path'] ?? null;

            if (
                !is_string($path)
                || !in_array($path, $this->selectedPaths, true)
                || ($entry['kind'] ?? null) !== 'object'
                || !is_array($entry['item'] ?? null)
            ) {
                continue;
            }

            $selectedItems[] = [
                'path' => $path,
                'value' => $entry['item'],
            ];
        }

        if ($selectedItems === []) {
            return;
        }

        $this->pendingDuplicationItems = [
            'items' => $selectedItems,
            'primaryPath' => $this->selectedPath,
        ];
    }

    public function hasActiveModal(): bool
    {
        return $this->addObjectModal->isVisible()
            || $this->addPlacementModal->isVisible()
            || $this->addUiElementModal->isVisible()
            || $this->deleteConfirmModal->isVisible();
    }

    public function isModalDirty(): bool
    {
        return $this->addObjectModal->isDirty()
            || $this->addPlacementModal->isDirty()
            || $this->addUiElementModal->isDirty()
            || $this->deleteConfirmModal->isDirty();
    }

    public function markModalClean(): void
    {
        $this->addObjectModal->markClean();
        $this->addPlacementModal->markClean();
        $this->addUiElementModal->markClean();
        $this->deleteConfirmModal->markClean();
    }

    public function syncModalLayout(int $terminalWidth, int $terminalHeight): void
    {
        $this->addObjectModal->syncLayout($terminalWidth, $terminalHeight);
        $this->addPlacementModal->syncLayout($terminalWidth, $terminalHeight);
        $this->addUiElementModal->syncLayout($terminalWidth, $terminalHeight);
        $this->deleteConfirmModal->syncLayout($terminalWidth, $terminalHeight);
    }

    public function renderActiveModal(): void
    {
        if ($this->addObjectModal->isVisible()) {
            $this->addObjectModal->render();
        }

        if ($this->addPlacementModal->isVisible()) {
            $this->addPlacementModal->render();
        }

        if ($this->addUiElementModal->isVisible()) {
            $this->addUiElementModal->render();
        }

        if ($this->deleteConfirmModal->isVisible()) {
            $this->deleteConfirmModal->render();
        }
    }

    public function handleModalMouseEvent(MouseEvent $mouseEvent): bool
    {
        $activeModal = match ($this->addModalState) {
            self::ADD_MODAL_OBJECT_KIND => $this->addObjectModal,
            self::ADD_MODAL_OBJECT_PLACEMENT => $this->addPlacementModal,
            self::ADD_MODAL_UI_KIND => $this->addUiElementModal,
            self::DELETE_MODAL_CONFIRM => $this->deleteConfirmModal,
            default => null,
        };

        if (!$activeModal instanceof OptionListModal) {
            return false;
        }

        if ($activeModal->handleScrollbarMouseEvent($mouseEvent)) {
            return true;
        }

        if ($mouseEvent->buttonIndex !== 0 || $mouseEvent->action !== 'Pressed') {
            return false;
        }

        $selectedOption = $activeModal->clickOptionAtPoint($mouseEvent->x, $mouseEvent->y);

        if (!is_string($selectedOption) || $selectedOption === '') {
            return false;
        }

        if ($this->addModalState === self::ADD_MODAL_OBJECT_KIND) {
            $this->handleAddObjectTypeSelection($selectedOption);
            return true;
        }

        if ($this->addModalState === self::ADD_MODAL_OBJECT_PLACEMENT) {
            $this->handleAddObjectPlacementSelection($selectedOption);
            return true;
        }

        if ($this->addModalState === self::ADD_MODAL_UI_KIND) {
            $this->handleAddUiElementSelection($selectedOption);
            return true;
        }

        if ($this->addModalState === self::DELETE_MODAL_CONFIRM) {
            $this->handleDeleteConfirmationSelection($selectedOption);
            return true;
        }

        return false;
    }

    public function focus(FocusTargetContext $context): void
    {
        parent::focus($context);
        $this->refreshContent();
    }

    public function blur(FocusTargetContext $context): void
    {
        $this->dismissAddModals();
        $this->draggedPath = null;
        $this->dragHoverPath = null;
        parent::blur($context);
        $this->refreshContent();
    }

    public function handleMouseClick(int $x, int $y): void
    {
        if (!$this->containsPoint($x, $y)) {
            return;
        }

        $index = $this->resolveContentIndexFromPointY($y);

        if (!is_int($index) || !isset($this->visibleHierarchy[$index])) {
            return;
        }

        $entry = $this->visibleHierarchy[$index] ?? null;

        if (!is_array($entry)) {
            return;
        }

        $path = is_string($entry['path'] ?? null) ? $entry['path'] : null;

        if ($path === null) {
            return;
        }

        $this->draggedPath = ($entry['kind'] ?? null) === 'object' ? $path : null;
        $this->dragHoverPath = null;

        $isAdditiveSelection = Input::getMouseEvent()?->isCtrlPressed === true;

        if ($isAdditiveSelection) {
            $this->selectedPath = $path;

            if (!in_array($path, $this->selectedPaths, true)) {
                $this->selectedPaths[] = $path;
            }
        } else {
            $this->selectedPath = $path;
            $this->selectedPaths = [$path];
        }

        if ($this->isExpandToggleClick($entry, $x)) {
            $this->draggedPath = null;
            $this->toggleEntryExpansion($entry);
            $this->resetClickTracking();
            return;
        }

        $isDoubleClick = $this->registerClickAndCheckDoubleClick($path);
        $this->refreshContent();

        if ($isDoubleClick) {
            $this->activateSelection();
        }
    }

    public function handleMouseDrag(int $x, int $y): void
    {
        if (
            !$this->containsPoint($x, $y)
            || !is_string($this->draggedPath)
            || $this->draggedPath === ''
        ) {
            $this->dragHoverPath = null;
            return;
        }

        $contentIndex = $this->resolveContentIndexFromPointY($y);
        $entry = is_int($contentIndex) ? ($this->visibleHierarchy[$contentIndex] ?? null) : null;
        $candidatePath = is_array($entry) ? ($entry['path'] ?? null) : null;
        $candidateKind = is_array($entry) ? ($entry['kind'] ?? null) : null;

        if (
            !is_string($candidatePath)
            || $candidatePath === $this->draggedPath
            || !in_array($candidateKind, ['object', 'scene'], true)
            || str_starts_with($candidatePath, $this->draggedPath . '.')
        ) {
            $this->dragHoverPath = null;
            return;
        }

        $this->dragHoverPath = $candidatePath;
    }

    public function handleMouseRelease(int $x, int $y): void
    {
        if (!is_string($this->draggedPath) || $this->draggedPath === '') {
            $this->dragHoverPath = null;
            return;
        }

        $targetPath = $this->dragHoverPath;

        if ($targetPath === null && $this->containsPoint($x, $y)) {
            $contentIndex = $this->resolveContentIndexFromPointY($y);
            $entry = is_int($contentIndex) ? ($this->visibleHierarchy[$contentIndex] ?? null) : null;
            $candidatePath = is_array($entry) ? ($entry['path'] ?? null) : null;
            $candidateKind = is_array($entry) ? ($entry['kind'] ?? null) : null;

            if (
                is_string($candidatePath)
                && $candidatePath !== $this->draggedPath
                && in_array($candidateKind, ['object', 'scene'], true)
                && !str_starts_with($candidatePath, $this->draggedPath . '.')
            ) {
                $targetPath = $candidatePath;
            }
        }

        if (is_string($targetPath) && $targetPath !== '') {
            $this->queueMouseDropMoveRequest($targetPath);
        } elseif ($this->containsPoint($x, $y)) {
            $this->queueMouseDropMoveRequest(self::ROOT_PATH);
        }

        $this->draggedPath = null;
        $this->dragHoverPath = null;
    }

    /**
     * @inheritDoc
     */
    public function update(): void
    {
        if (!$this->hasFocus()) {
            return;
        }

        if ($this->hasActiveModal()) {
            $this->handleModalInput();
            return;
        }

        if (Input::getCurrentInput() === 'Q') {
            $this->interactionMode = self::HIERARCHY_MODE_SELECT;
            $this->refreshContent();
            return;
        }

        if (Input::getCurrentInput() === 'W') {
            $this->beginMoveWorkflow();
            return;
        }

        if ($this->interactionMode === self::HIERARCHY_MODE_MOVE) {
            if (Input::isKeyDown(KeyCode::DELETE)) {
                $this->showDeleteConfirmModal();
                return;
            }

            if (Input::isKeyDown(KeyCode::UP)) {
                $this->queueMoveSelection(-1);
                return;
            }

            if (Input::isKeyDown(KeyCode::DOWN)) {
                $this->queueMoveSelection(1);
                return;
            }

            return;
        }

        if (Input::getCurrentInput() === 'A') {
            $this->showAddObjectModal();
            return;
        }

        if (Input::getCurrentInput() === 'E') {
            $this->beginPrefabCreationWorkflow();
            return;
        }

        if (Input::getCurrentInput() === 'D') {
            $this->beginDuplicationWorkflow();
            return;
        }

        if (Input::isKeyDown(KeyCode::DELETE)) {
            $this->showDeleteConfirmModal();
            return;
        }

        if (Input::isKeyDown(KeyCode::UP)) {
            $this->moveSelection(-1);
            return;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $this->moveSelection(1);
            return;
        }

        if (Input::isKeyDown(KeyCode::RIGHT)) {
            $this->expandSelection();
            return;
        }

        if (Input::isKeyDown(KeyCode::LEFT)) {
            $this->collapseSelection();
            return;
        }

        if (Input::isKeyDown(KeyCode::ENTER)) {
            $this->activateSelection();
        }
    }

    protected function decorateContentLine(string $line, ?Color $contentColor, int $lineIndex): string
    {
        $contentIndex = $this->getContentIndexForLineIndex($lineIndex);

        if (!is_int($contentIndex)) {
            return parent::decorateContentLine($line, $contentColor, $lineIndex);
        }

        $entry = $this->visibleHierarchy[$contentIndex] ?? null;
        $path = is_array($entry) ? ($entry['path'] ?? null) : null;

        if (!is_string($path) || !in_array($path, $this->selectedPaths, true)) {
            return parent::decorateContentLine($line, $contentColor, $lineIndex);
        }

        $visibleLine = mb_substr($line, 0, $this->width);
        $visibleLength = mb_strlen($visibleLine);

        if ($visibleLength <= 1) {
            return parent::decorateContentLine($line, $contentColor, $lineIndex);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleLength > 2 ? mb_substr($visibleLine, 1, $visibleLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);
        $borderColor = $this->hasFocus() ? $this->focusBorderColor : $contentColor;
        $selectionSequence = $this->hasFocus()
            ? ($this->interactionMode === self::HIERARCHY_MODE_MOVE && $path === $this->selectedPath
                ? self::MOVE_ROW_FOCUSED_SEQUENCE
                : self::SELECTED_ROW_FOCUSED_SEQUENCE)
            : ($this->interactionMode === self::HIERARCHY_MODE_MOVE && $path === $this->selectedPath
                ? self::MOVE_ROW_SEQUENCE
                : self::SELECTED_ROW_SEQUENCE);

        return $this->wrapWithColor($leftBorder, $borderColor)
            . $this->wrapWithSequence($middle, $selectionSequence)
            . $this->wrapWithColor($rightBorder, $borderColor);
    }

    private function refreshContent(): void
    {
        $this->visibleHierarchy = $this->buildVisibleHierarchy();
        $this->syncSelectedPath();
        $this->content = array_map(
            fn(array $entry) => $this->formatVisibleHierarchyEntry($entry),
            $this->visibleHierarchy
        );
        $this->ensureContentLineVisible($this->getSelectedVisibleIndex());
    }

    private function buildVisibleHierarchy(): array
    {
        $rootNode = [
            'kind' => 'scene',
            'path' => self::ROOT_PATH,
            'item' => [
                'name' => $this->sceneName,
                'displayName' => $this->getDisplaySceneName(),
                'type' => 'Scene',
                'width' => $this->sceneWidth,
                'height' => $this->sceneHeight,
                'environmentTileMapPath' => $this->environmentTileMapPath,
            ],
            'depth' => 0,
            'hasChildren' => $this->hierarchy !== [],
            'isExpanded' => isset($this->expandedPaths[self::ROOT_PATH]),
        ];

        $visibleHierarchy = [$rootNode];

        if ($rootNode['isExpanded']) {
            $visibleHierarchy = [
                ...$visibleHierarchy,
                ...$this->buildChildHierarchy($this->hierarchy, 1, self::ROOT_PATH),
            ];
        }

        return $visibleHierarchy;
    }

    private function buildChildHierarchy(array $items, int $depth, string $parentPath): array
    {
        $visibleHierarchy = [];

        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $path = $parentPath . '.' . $index;
            $children = $this->getChildItems($item);
            $hasChildren = $children !== [];
            $isExpanded = $hasChildren && isset($this->expandedPaths[$path]);

            $visibleHierarchy[] = [
                'kind' => 'object',
                'path' => $path,
                'item' => $item,
                'depth' => $depth,
                'hasChildren' => $hasChildren,
                'isExpanded' => $isExpanded,
            ];

            if ($isExpanded) {
                $visibleHierarchy = [
                    ...$visibleHierarchy,
                    ...$this->buildChildHierarchy($children, $depth + 1, $path),
                ];
            }
        }

        return $visibleHierarchy;
    }

    private function syncSelectedPath(): void
    {
        $this->selectedPaths = array_values(array_filter(
            $this->selectedPaths,
            fn (mixed $path): bool => is_string($path) && $this->findVisibleIndexByPath($path) !== null
        ));

        if ($this->selectedPath !== null && $this->findVisibleIndexByPath($this->selectedPath) !== null) {
            if ($this->selectedPaths === []) {
                $this->selectedPaths = [$this->selectedPath];
            }
            return;
        }

        $candidatePath = $this->selectedPath;

        while ($candidatePath !== null) {
            $candidatePath = $this->getParentPath($candidatePath);

            if ($candidatePath !== null && $this->findVisibleIndexByPath($candidatePath) !== null) {
                $this->selectedPath = $candidatePath;
                $this->selectedPaths = [$candidatePath];
                return;
            }
        }

        $this->selectedPath = $this->visibleHierarchy[0]['path'] ?? null;
        $this->selectedPaths = $this->selectedPath !== null ? [$this->selectedPath] : [];
    }

    private function getSelectedVisibleNode(): ?array
    {
        $selectedVisibleIndex = $this->getSelectedVisibleIndex();

        if ($selectedVisibleIndex === null) {
            return null;
        }

        return $this->visibleHierarchy[$selectedVisibleIndex] ?? null;
    }

    private function getSelectedVisibleIndex(): ?int
    {
        return $this->findVisibleIndexByPath($this->selectedPath);
    }

    private function findVisibleIndexByPath(?string $path): ?int
    {
        if ($path === null) {
            return null;
        }

        foreach ($this->visibleHierarchy as $index => $entry) {
            if (($entry['path'] ?? null) === $path) {
                return $index;
            }
        }

        return null;
    }

    private function formatVisibleHierarchyEntry(array $entry): string
    {
        $icon = match (true) {
            ($entry['hasChildren'] ?? false) && ($entry['isExpanded'] ?? false) => self::EXPANDED_ICON,
            ($entry['hasChildren'] ?? false) => self::COLLAPSED_ICON,
            default => self::LEAF_ICON,
        };
        $name = $entry['item']['displayName'] ?? $entry['item']['name'] ?? 'Unnamed Object';
        $indentation = str_repeat('  ', (int)($entry['depth'] ?? 0));

        return $indentation . $icon . ' ' . $name;
    }

    private function getDisplaySceneName(): string
    {
        return $this->isSceneDirty ? $this->sceneName . '*' : $this->sceneName;
    }

    private function resolveInspectableType(array $selectedItem): string
    {
        $type = $selectedItem['type'] ?? null;

        if (!is_string($type) || $type === '') {
            return 'Unknown';
        }

        $normalizedType = ltrim($type, '\\');
        $normalizedType = preg_replace('/::class$/', '', $normalizedType) ?? $normalizedType;
        $typeSegments = explode('\\', $normalizedType);

        return end($typeSegments) ?: $normalizedType;
    }

    private function getChildItems(array $item): array
    {
        $children = $item['children'] ?? [];

        if (!is_array($children)) {
            return [];
        }

        return array_values($children);
    }

    private function isExpandToggleClick(array $entry, int $x): bool
    {
        if (!($entry['hasChildren'] ?? false)) {
            return false;
        }

        $depth = max(0, (int) ($entry['depth'] ?? 0));
        $iconColumn = $this->getContentAreaLeft() + ($depth * 2);

        return $x === $iconColumn;
    }

    private function toggleEntryExpansion(array $entry): void
    {
        $path = $entry['path'] ?? null;

        if (!is_string($path) || !($entry['hasChildren'] ?? false)) {
            return;
        }

        if ($entry['isExpanded'] ?? false) {
            unset($this->expandedPaths[$path]);
        } else {
            $this->expandedPaths[$path] = true;
        }

        $this->refreshContent();
    }

    private function registerClickAndCheckDoubleClick(string $path): bool
    {
        $now = microtime(true);
        $isDoubleClick = $this->lastClickedPath === $path
            && ($now - $this->lastClickedAt) <= self::DOUBLE_CLICK_THRESHOLD_SECONDS;

        $this->lastClickedPath = $path;
        $this->lastClickedAt = $now;

        return $isDoubleClick;
    }

    private function resetClickTracking(): void
    {
        $this->lastClickedPath = null;
        $this->lastClickedAt = 0.0;
    }

    private function getParentPath(string $path): ?string
    {
        $separatorPosition = strrpos($path, '.');

        if ($separatorPosition === false) {
            return null;
        }

        return substr($path, 0, $separatorPosition);
    }

    private function queueMouseDropMoveRequest(string $targetPath): void
    {
        if (!is_string($this->draggedPath) || $this->draggedPath === '') {
            return;
        }

        $normalizedTargetPath = $targetPath === '' ? self::ROOT_PATH : $targetPath;

        if (
            $normalizedTargetPath === self::ROOT_PATH
            && $this->getParentPath($this->draggedPath) === self::ROOT_PATH
        ) {
            return;
        }

        if (
            $normalizedTargetPath !== self::ROOT_PATH
            && ($normalizedTargetPath === $this->draggedPath || str_starts_with($normalizedTargetPath, $this->draggedPath . '.'))
        ) {
            return;
        }

        $this->pendingMoveItem = [
            'path' => $this->draggedPath,
            'targetPath' => $normalizedTargetPath,
            'position' => 'append_child',
        ];
    }

    private function showAddObjectModal(): void
    {
        $this->addObjectModal->show(['GameObject', 'UIElement']);
        $this->addPlacementModal->hide();
        $this->addUiElementModal->hide();
        $this->deleteConfirmModal->hide();
        $this->addModalState = self::ADD_MODAL_OBJECT_KIND;
    }

    private function showAddPlacementModal(string $selectedName): void
    {
        $this->addPlacementModal->show(['Root', 'Child of ' . $selectedName]);
        $this->addObjectModal->hide();
        $this->addUiElementModal->hide();
        $this->deleteConfirmModal->hide();
        $this->addModalState = self::ADD_MODAL_OBJECT_PLACEMENT;
    }

    private function showAddUiElementModal(): void
    {
        $this->addUiElementModal->show(['Text', 'Label', 'GUITexture']);
        $this->addObjectModal->hide();
        $this->addPlacementModal->hide();
        $this->deleteConfirmModal->hide();
        $this->addModalState = self::ADD_MODAL_UI_KIND;
    }

    private function showDeleteConfirmModal(): void
    {
        $selectedNode = $this->getSelectedVisibleNode();

        if (($selectedNode['kind'] ?? null) !== 'object') {
            return;
        }

        $selectedItem = $selectedNode['item'] ?? null;
        $selectedName = is_array($selectedItem) ? ($selectedItem['name'] ?? 'this object') : 'this object';

        $this->deleteConfirmModal->show(
            ['Delete', 'Cancel'],
            1,
            'Are you sure you want to delete ' . $selectedName . '?'
        );
        $this->addObjectModal->hide();
        $this->addPlacementModal->hide();
        $this->addUiElementModal->hide();
        $this->addModalState = self::DELETE_MODAL_CONFIRM;
    }

    private function dismissAddModals(): void
    {
        $this->addObjectModal->hide();
        $this->addPlacementModal->hide();
        $this->addUiElementModal->hide();
        $this->deleteConfirmModal->hide();
        $this->addModalState = null;
    }

    private function handleModalInput(): void
    {
        if (Input::isKeyDown(KeyCode::ESCAPE)) {
            if ($this->addModalState === self::ADD_MODAL_OBJECT_PLACEMENT) {
                $this->showAddObjectModal();
                return;
            }

            if ($this->addModalState === self::ADD_MODAL_UI_KIND) {
                $this->showAddObjectModal();
                return;
            }

            $this->dismissAddModals();
            return;
        }

        $activeModal = match ($this->addModalState) {
            self::ADD_MODAL_OBJECT_KIND => $this->addObjectModal,
            self::ADD_MODAL_OBJECT_PLACEMENT => $this->addPlacementModal,
            self::ADD_MODAL_UI_KIND => $this->addUiElementModal,
            self::DELETE_MODAL_CONFIRM => $this->deleteConfirmModal,
            default => null,
        };

        if (!$activeModal instanceof OptionListModal) {
            return;
        }

        if (Input::isKeyDown(KeyCode::UP)) {
            $activeModal->moveSelection(-1);
            return;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $activeModal->moveSelection(1);
            return;
        }

        if (!Input::isKeyDown(KeyCode::ENTER)) {
            return;
        }

        $selectedOption = $activeModal->getSelectedOption();

        if (!is_string($selectedOption) || $selectedOption === '') {
            return;
        }

        if ($this->addModalState === self::ADD_MODAL_OBJECT_KIND) {
            $this->handleAddObjectTypeSelection($selectedOption);
            return;
        }

        if ($this->addModalState === self::ADD_MODAL_OBJECT_PLACEMENT) {
            $this->handleAddObjectPlacementSelection($selectedOption);
            return;
        }

        if ($this->addModalState === self::ADD_MODAL_UI_KIND) {
            $this->handleAddUiElementSelection($selectedOption);
            return;
        }

        if ($this->addModalState === self::DELETE_MODAL_CONFIRM) {
            $this->handleDeleteConfirmationSelection($selectedOption);
        }
    }

    private function handleAddObjectTypeSelection(string $selection): void
    {
        if ($selection === 'UIElement') {
            $this->showAddUiElementModal();
            return;
        }

        $selectedNode = $this->getSelectedVisibleNode();
        $selectedName = is_array($selectedNode['item'] ?? null)
            ? (string) ($selectedNode['item']['name'] ?? 'Selected')
            : 'Selected';

        if ($selection === 'GameObject' && $this->resolveSelectedGameObjectPathForPlacement() !== null) {
            $this->showAddPlacementModal($selectedName);
            return;
        }

        $this->pendingCreationItem = $this->buildCreationRequest($selection, null);
        $this->dismissAddModals();
    }

    private function handleAddUiElementSelection(string $selection): void
    {
        $this->pendingCreationItem = $this->buildCreationRequest($selection, null);
        $this->dismissAddModals();
    }

    private function handleAddObjectPlacementSelection(string $selection): void
    {
        $parentPath = $selection === 'Root'
            ? null
            : $this->resolveSelectedGameObjectPathForPlacement();

        $this->pendingCreationItem = $this->buildCreationRequest('GameObject', $parentPath);
        $this->dismissAddModals();
    }

    private function handleDeleteConfirmationSelection(string $selection): void
    {
        if ($selection !== 'Delete') {
            $this->dismissAddModals();
            return;
        }

        $selectedNode = $this->getSelectedVisibleNode();

        if (($selectedNode['kind'] ?? null) !== 'object') {
            $this->dismissAddModals();
            return;
        }

        $selectedItem = $selectedNode['item'] ?? null;
        $this->pendingDeletionItem = [
            'path' => $selectedNode['path'] ?? null,
            'name' => is_array($selectedItem) ? ($selectedItem['name'] ?? 'Unnamed Object') : 'Unnamed Object',
        ];
        $this->dismissAddModals();
    }

    private function buildCreationRequest(string $selection, ?string $parentPath): array
    {
        return [
            'value' => $this->buildDefaultObjectDefinition($selection),
            'parentPath' => $parentPath,
        ];
    }

    private function buildDefaultObjectDefinition(string $selection): array
    {
        $instanceName = $selection . ' #' . $this->getNextInstanceCount($selection);

        return match ($selection) {
            'GameObject' => [
                'type' => self::GAME_OBJECT_TYPE,
                'name' => $instanceName,
                'tag' => 'None',
                'position' => ['x' => 0, 'y' => 0],
                'rotation' => ['x' => 0, 'y' => 0],
                'scale' => ['x' => 1, 'y' => 1],
                'components' => [],
            ],
            'Text' => [
                'type' => self::TEXT_TYPE,
                'name' => $instanceName,
                'tag' => 'UI',
                'position' => ['x' => 0, 'y' => 0],
                'size' => ['x' => 1, 'y' => 1],
                'text' => $instanceName,
            ],
            'Label' => [
                'type' => self::LABEL_TYPE,
                'name' => $instanceName,
                'tag' => 'UI',
                'position' => ['x' => 0, 'y' => 0],
                'size' => ['x' => 1, 'y' => 1],
                'text' => $instanceName,
            ],
            'GUITexture' => [
                'type' => self::GUI_TEXTURE_TYPE,
                'name' => $instanceName,
                'tag' => 'UI',
                'position' => ['x' => 0, 'y' => 0],
                'size' => ['x' => 1, 'y' => 1],
                'texture' => 'None',
                'color' => 'White',
            ],
            default => [],
        };
    }

    private function getNextInstanceCount(string $type): int
    {
        return $this->countInstancesOfType($this->hierarchy, $type) + 1;
    }

    private function countInstancesOfType(array $items, string $type): int
    {
        $count = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ($this->resolveInspectableType($item) === $type) {
                $count++;
            }

            $count += $this->countInstancesOfType($this->getChildItems($item), $type);
        }

        return $count;
    }

    private function resolveSelectedGameObjectPathForPlacement(): ?string
    {
        $selectedNode = $this->getSelectedVisibleNode();

        if (($selectedNode['kind'] ?? null) !== 'object') {
            return null;
        }

        $selectedItem = $selectedNode['item'] ?? null;
        $selectedPath = $selectedNode['path'] ?? null;

        if (!is_array($selectedItem) || !is_string($selectedPath) || $selectedPath === '') {
            return null;
        }

        $normalizedType = ltrim((string) ($selectedItem['type'] ?? ''), '\\');
        $normalizedType = preg_replace('/::class$/', '', $normalizedType) ?? $normalizedType;

        return $normalizedType === self::GAME_OBJECT_TYPE ? $selectedPath : null;
    }

    private function beginMoveWorkflow(): void
    {
        $selectedNode = $this->getSelectedVisibleNode();

        if (($selectedNode['kind'] ?? null) !== 'object') {
            return;
        }

        $this->interactionMode = self::HIERARCHY_MODE_MOVE;
        $this->selectedPaths = $this->selectedPath !== null ? [$this->selectedPath] : [];
        $this->refreshContent();
    }

    private function queueMoveSelection(int $offset): void
    {
        if (!is_string($this->selectedPath) || $this->selectedPath === '') {
            return;
        }

        $movableEntries = array_values(array_filter(
            $this->visibleHierarchy,
            fn(array $entry): bool => ($entry['kind'] ?? null) === 'object'
                && ($entry['path'] ?? null) !== null
                && !str_starts_with((string) ($entry['path'] ?? ''), $this->selectedPath . '.')
        ));

        $selectedIndex = null;

        foreach ($movableEntries as $index => $entry) {
            if (($entry['path'] ?? null) === $this->selectedPath) {
                $selectedIndex = $index;
                break;
            }
        }

        if (!is_int($selectedIndex)) {
            return;
        }

        $targetIndex = max(0, min($selectedIndex + $offset, count($movableEntries) - 1));

        if ($targetIndex === $selectedIndex) {
            return;
        }

        $targetPath = $movableEntries[$targetIndex]['path'] ?? null;

        if (!is_string($targetPath) || $targetPath === '') {
            return;
        }

        $this->pendingMoveItem = [
            'path' => $this->selectedPath,
            'targetPath' => $targetPath,
            'position' => $offset < 0 ? 'before' : 'after',
        ];
    }
}

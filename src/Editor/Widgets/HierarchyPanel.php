<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\Events\Interfaces\ObservableInterface;
use Atatusoft\Termutil\Events\Traits\ObservableTrait;
use Atatusoft\Termutil\IO\Enumerations\Color;
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

    private const string ROOT_PATH = 'scene';
    private const string COLLAPSED_ICON = '►';
    private const string EXPANDED_ICON = '▼';
    private const string LEAF_ICON = '•';
    private const string SELECTED_ROW_SEQUENCE = "\033[30;46m";
    private const string SELECTED_ROW_FOCUSED_SEQUENCE = "\033[5;30;46m";

    protected string $sceneName = 'Scene';
    protected bool $isSceneDirty = false;
    protected array $hierarchy = [];
    protected array $visibleHierarchy = [];
    protected array $expandedPaths = [];
    protected ?string $selectedPath = null;
    protected ?array $pendingInspectionItem = null;

    public function __construct(
        array $position = ['x' => 1, 'y' => 1],
        int $width = 35,
        int $height = 14,
        string $sceneName = 'Scene',
        bool $isSceneDirty = false,
        array $hierarchy = []
    )
    {
        $this->initializeObservers();
        parent::__construct('Hierarchy', '', $position, $width, $height);
        $this->sceneName = $sceneName;
        $this->isSceneDirty = $isSceneDirty;
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
        $this->refreshContent();

        $this->notify(new EditorEvent(EventType::HIERARCHY_CHANGED->value, $this));
    }

    public function syncHierarchy(array $hierarchy): void
    {
        $this->hierarchy = array_values($hierarchy);
        $this->refreshContent();
    }

    public function setSceneState(string $sceneName, bool $isDirty = false): void
    {
        $this->sceneName = $sceneName;
        $this->isSceneDirty = $isDirty;
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
        $this->refreshContent();
    }

    public function activateSelection(): void
    {
        $selectedNode = $this->getSelectedVisibleNode();

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

    public function handleMouseClick(int $x, int $y): void
    {
        if (!$this->containsPoint($x, $y)) {
            return;
        }

        $index = $y - $this->getContentAreaTop();

        if (!isset($this->visibleHierarchy[$index])) {
            return;
        }

        $this->selectedPath = $this->visibleHierarchy[$index]['path'] ?? $this->selectedPath;
        $this->refreshContent();
    }

    /**
     * @inheritDoc
     */
    public function update(): void
    {
        if (!$this->hasFocus()) {
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
        $selectedVisibleIndex = $this->getSelectedVisibleIndex();
        $selectedLineIndex = $selectedVisibleIndex === null
            ? null
            : $this->padding->topPadding + $selectedVisibleIndex;

        if ($lineIndex !== $selectedLineIndex) {
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
            ? self::SELECTED_ROW_FOCUSED_SEQUENCE
            : self::SELECTED_ROW_SEQUENCE;

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
    }

    private function buildVisibleHierarchy(): array
    {
        $rootNode = [
            'kind' => 'scene',
            'path' => self::ROOT_PATH,
            'item' => [
                'name' => $this->getDisplaySceneName(),
                'type' => 'Scene',
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
        if ($this->selectedPath !== null && $this->findVisibleIndexByPath($this->selectedPath) !== null) {
            return;
        }

        $candidatePath = $this->selectedPath;

        while ($candidatePath !== null) {
            $candidatePath = $this->getParentPath($candidatePath);

            if ($candidatePath !== null && $this->findVisibleIndexByPath($candidatePath) !== null) {
                $this->selectedPath = $candidatePath;
                return;
            }
        }

        $this->selectedPath = $this->visibleHierarchy[0]['path'] ?? null;
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
        $name = $entry['item']['name'] ?? 'Unnamed Object';
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

    private function getParentPath(string $path): ?string
    {
        $separatorPosition = strrpos($path, '.');

        if ($separatorPosition === false) {
            return null;
        }

        return substr($path, 0, $separatorPosition);
    }
}

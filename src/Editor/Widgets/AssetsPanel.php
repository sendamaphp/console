<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\IO\Enumerations\Color;
use Sendama\Console\Debug\Debug;
use Sendama\Console\Editor\IO\Enumerations\KeyCode;
use Sendama\Console\Editor\IO\Input;
use Sendama\Console\Util\Path;

/**
 * AssetsPanel class.
 *
 * This panel is responsible for displaying the assets in the current project's Assets directory.
 */
class AssetsPanel extends Widget
{
    private const string DELETE_MODAL_CONFIRM = 'delete_confirm';
    private const string COLLAPSED_ICON = '►';
    private const string EXPANDED_ICON = '▼';
    private const string LEAF_ICON = '•';
    private const string SELECTED_ROW_SEQUENCE = "\033[30;46m";
    private const string SELECTED_ROW_FOCUSED_SEQUENCE = "\033[5;30;46m";

    protected array $assetTree = [];
    protected array $visibleAssets = [];
    protected array $expandedPaths = [];
    protected ?string $selectedPath = null;
    protected ?array $pendingInspectionTarget = null;
    protected ?array $pendingDeletionTarget = null;
    protected OptionListModal $deleteConfirmModal;
    protected ?string $modalState = null;

    public function __construct(
        array $position = ['x' => 1, 'y' => 15],
        int $width = 35,
        int $height = 14,
        protected ?string $assetsDirectoryPath = null
    )
    {
        parent::__construct('Assets', '', $position, $width, $height);
        $this->deleteConfirmModal = new OptionListModal(title: 'Delete Asset');
        $this->loadAssetEntries();
        $this->refreshContent();
    }

    public function getSelectedAssetEntry(): ?array
    {
        return $this->getSelectedVisibleAsset()['item'] ?? null;
    }

    public function moveSelection(int $offset): void
    {
        if (!$this->visibleAssets) {
            return;
        }

        $selectedIndex = $this->getSelectedVisibleIndex() ?? 0;
        $nextIndex = max(0, min($selectedIndex + $offset, count($this->visibleAssets) - 1));
        $this->selectedPath = $this->visibleAssets[$nextIndex]['path'] ?? $this->selectedPath;
        $this->refreshContent();
    }

    public function expandSelection(): void
    {
        $selectedAsset = $this->getSelectedVisibleAsset();

        if (!$selectedAsset || !($selectedAsset['isDirectory'] ?? false)) {
            return;
        }

        if (!($selectedAsset['isExpanded'] ?? false)) {
            $this->expandedPaths[$selectedAsset['path']] = true;
            $this->refreshContent();
            return;
        }

        $selectedDepth = $selectedAsset['depth'];
        $selectedPath = $selectedAsset['path'];

        foreach ($this->visibleAssets as $entry) {
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
        $selectedAsset = $this->getSelectedVisibleAsset();

        if (!$selectedAsset) {
            return;
        }

        if (($selectedAsset['isDirectory'] ?? false) && ($selectedAsset['isExpanded'] ?? false)) {
            unset($this->expandedPaths[$selectedAsset['path']]);
            $this->refreshContent();
            return;
        }

        $parentPath = $this->getParentPath($selectedAsset['path']);

        if ($parentPath === null) {
            return;
        }

        $this->selectedPath = $parentPath;
        $this->refreshContent();
    }

    public function activateSelection(): void
    {
        $selectedAsset = $this->getSelectedAssetEntry();

        if ($selectedAsset === null) {
            return;
        }

        $this->pendingInspectionTarget = [
            'context' => 'asset',
            'name' => $selectedAsset['name'] ?? 'Unnamed Asset',
            'type' => ($selectedAsset['isDirectory'] ?? false) ? 'Folder' : 'File',
            'value' => $selectedAsset,
        ];
    }

    public function consumeInspectionRequest(): ?array
    {
        $pendingInspectionTarget = $this->pendingInspectionTarget;
        $this->pendingInspectionTarget = null;

        return $pendingInspectionTarget;
    }

    public function consumeDeletionRequest(): ?array
    {
        $pendingDeletionTarget = $this->pendingDeletionTarget;
        $this->pendingDeletionTarget = null;

        return $pendingDeletionTarget;
    }

    public function hasActiveModal(): bool
    {
        return $this->deleteConfirmModal->isVisible();
    }

    public function isModalDirty(): bool
    {
        return $this->deleteConfirmModal->isDirty();
    }

    public function markModalClean(): void
    {
        $this->deleteConfirmModal->markClean();
    }

    public function syncModalLayout(int $terminalWidth, int $terminalHeight): void
    {
        $this->deleteConfirmModal->syncLayout($terminalWidth, $terminalHeight);
    }

    public function renderActiveModal(): void
    {
        if ($this->deleteConfirmModal->isVisible()) {
            $this->deleteConfirmModal->render();
        }
    }

    public function reloadAssets(): void
    {
        $this->loadAssetEntries();
        $this->refreshContent();
    }

    public function selectAssetByAbsolutePath(?string $absolutePath): void
    {
        if (!is_string($absolutePath) || $absolutePath === '') {
            return;
        }

        $matchedPath = $this->findAssetPathByAbsolutePath($this->assetTree, $absolutePath);

        if ($matchedPath === null) {
            return;
        }

        $this->selectedPath = $matchedPath;
        $this->refreshContent();
    }

    public function handleMouseClick(int $x, int $y): void
    {
        if (!$this->containsPoint($x, $y)) {
            return;
        }

        $index = $y - $this->getContentAreaTop();

        if (!isset($this->visibleAssets[$index])) {
            return;
        }

        $this->selectedPath = $this->visibleAssets[$index]['path'] ?? $this->selectedPath;
        $this->refreshContent();
        $this->activateSelection();
    }

    public function update(): void
    {
        if (!$this->hasFocus()) {
            return;
        }

        if ($this->hasActiveModal()) {
            $this->handleModalInput();
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
            return;
        }

        if (Input::isKeyDown(KeyCode::DELETE)) {
            $this->showDeleteConfirmModal();
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

    private function loadAssetEntries(): void
    {
        if (!$this->assetsDirectoryPath) {
            $this->assetsDirectoryPath = Path::getWorkingDirectoryAssetsPath();
        }

        if (!$this->assetsDirectoryPath || !is_dir($this->assetsDirectoryPath)) {
            Debug::warn("Assets directory not found at {$this->assetsDirectoryPath}. Please create the directory and add your assets.");
            $this->assetTree = [];
            return;
        }

        $this->assetTree = $this->buildAssetTree($this->assetsDirectoryPath);
    }

    private function buildAssetTree(string $directory): array
    {
        $entries = scandir($directory);

        if ($entries === false) {
            Debug::error("Failed to read contents of assets directory at {$directory}.");
            return [];
        }

        $assetEntries = [];

        foreach ($entries as $entryName) {
            if ($entryName === '.' || $entryName === '..') {
                continue;
            }

            $entryPath = Path::join($directory, $entryName);
            $isDirectory = is_dir($entryPath);

            $assetEntries[] = [
                'name' => $entryName,
                'path' => $entryPath,
                'relativePath' => $this->buildRelativePath($entryPath),
                'isDirectory' => $isDirectory,
                'children' => $isDirectory ? $this->buildAssetTree($entryPath) : [],
            ];
        }

        usort($assetEntries, function (array $left, array $right) {
            if (($left['isDirectory'] ?? false) !== ($right['isDirectory'] ?? false)) {
                return ($left['isDirectory'] ?? false) ? -1 : 1;
            }

            return strcasecmp($left['name'] ?? '', $right['name'] ?? '');
        });

        return $assetEntries;
    }

    private function buildRelativePath(string $path): string
    {
        if (!$this->assetsDirectoryPath) {
            return basename($path);
        }

        $relativePath = substr($path, strlen($this->assetsDirectoryPath));

        return ltrim($relativePath ?: basename($path), DIRECTORY_SEPARATOR);
    }

    private function refreshContent(): void
    {
        $this->visibleAssets = $this->buildVisibleAssets($this->assetTree);
        $this->syncSelectedPath();
        $this->content = array_map(
            fn(array $entry) => $this->formatVisibleAssetEntry($entry),
            $this->visibleAssets
        );
    }

    private function buildVisibleAssets(array $items, int $depth = 0, string $parentPath = ''): array
    {
        $visibleAssets = [];

        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $path = $parentPath === '' ? (string)$index : $parentPath . '.' . $index;
            $isDirectory = (bool)($item['isDirectory'] ?? false);
            $isExpanded = $isDirectory && isset($this->expandedPaths[$path]);

            $visibleAssets[] = [
                'path' => $path,
                'item' => $item,
                'depth' => $depth,
                'isDirectory' => $isDirectory,
                'isExpanded' => $isExpanded,
            ];

            if ($isExpanded) {
                $visibleAssets = [
                    ...$visibleAssets,
                    ...$this->buildVisibleAssets($this->getChildItems($item), $depth + 1, $path),
                ];
            }
        }

        return $visibleAssets;
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

        $this->selectedPath = $this->visibleAssets[0]['path'] ?? null;
    }

    private function getSelectedVisibleAsset(): ?array
    {
        $selectedVisibleIndex = $this->getSelectedVisibleIndex();

        if ($selectedVisibleIndex === null) {
            return null;
        }

        return $this->visibleAssets[$selectedVisibleIndex] ?? null;
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

        foreach ($this->visibleAssets as $index => $entry) {
            if (($entry['path'] ?? null) === $path) {
                return $index;
            }
        }

        return null;
    }

    private function formatVisibleAssetEntry(array $entry): string
    {
        $icon = match (true) {
            ($entry['isDirectory'] ?? false) && ($entry['isExpanded'] ?? false) => self::EXPANDED_ICON,
            ($entry['isDirectory'] ?? false) => self::COLLAPSED_ICON,
            default => self::LEAF_ICON,
        };
        $name = $entry['item']['name'] ?? 'Unnamed Asset';
        $indentation = str_repeat('  ', (int)($entry['depth'] ?? 0));

        return $indentation . $icon . ' ' . $name;
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

    private function findAssetPathByAbsolutePath(array $items, string $targetAbsolutePath, string $parentPath = ''): ?string
    {
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $path = $parentPath === '' ? (string) $index : $parentPath . '.' . $index;

            if (($item['path'] ?? null) === $targetAbsolutePath) {
                return $path;
            }

            $children = $item['children'] ?? [];

            if (!is_array($children) || $children === []) {
                continue;
            }

            $childPath = $this->findAssetPathByAbsolutePath($children, $targetAbsolutePath, $path);

            if ($childPath !== null) {
                $this->expandedPaths[$path] = true;
                return $childPath;
            }
        }

        return null;
    }

    private function showDeleteConfirmModal(): void
    {
        $selectedAsset = $this->getSelectedAssetEntry();

        if ($selectedAsset === null) {
            return;
        }

        $selectedName = $selectedAsset['name'] ?? 'this asset';
        $this->deleteConfirmModal->show(
            ['Delete', 'Cancel'],
            1,
            'Are you sure you want to delete ' . $selectedName . '?'
        );
        $this->modalState = self::DELETE_MODAL_CONFIRM;
    }

    private function dismissModal(): void
    {
        $this->deleteConfirmModal->hide();
        $this->modalState = null;
    }

    private function handleModalInput(): void
    {
        if (Input::isKeyDown(KeyCode::ESCAPE)) {
            $this->dismissModal();
            return;
        }

        if ($this->modalState !== self::DELETE_MODAL_CONFIRM) {
            return;
        }

        if (Input::isKeyDown(KeyCode::UP)) {
            $this->deleteConfirmModal->moveSelection(-1);
            return;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $this->deleteConfirmModal->moveSelection(1);
            return;
        }

        if (!Input::isKeyDown(KeyCode::ENTER)) {
            return;
        }

        $selection = $this->deleteConfirmModal->getSelectedOption();

        if ($selection !== 'Delete') {
            $this->dismissModal();
            return;
        }

        $selectedVisibleAsset = $this->getSelectedVisibleAsset();
        $selectedAsset = $selectedVisibleAsset['item'] ?? null;

        if (!is_array($selectedAsset)) {
            $this->dismissModal();
            return;
        }

        $this->pendingDeletionTarget = [
            'path' => $selectedVisibleAsset['path'] ?? null,
            'assetPath' => $selectedAsset['path'] ?? null,
            'name' => $selectedAsset['name'] ?? 'Unnamed Asset',
            'isDirectory' => (bool) ($selectedAsset['isDirectory'] ?? false),
        ];
        $this->selectedPath = is_string($selectedVisibleAsset['path'] ?? null)
            ? $this->getParentPath($selectedVisibleAsset['path'])
            : $this->selectedPath;
        $this->dismissModal();
    }
}

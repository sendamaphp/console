<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\IO\Enumerations\Color;
use Sendama\Console\Editor\EditorColorScheme;
use Sendama\Console\Util\Path;

class FileDialogModal extends Widget
{
    private const float DOUBLE_CLICK_THRESHOLD_SECONDS = 0.35;
    private const string COLLAPSED_ICON = '►';
    private const string EXPANDED_ICON = '▼';
    private const string LEAF_ICON = '•';
    private const string SELECTED_ROW_SEQUENCE = EditorColorScheme::SELECTED_ROW_SEQUENCE;

    protected bool $isVisible = false;
    protected bool $isDirty = false;
    protected string $workingDirectory = '.';
    protected array $entryTree = [];
    protected array $visibleEntries = [];
    protected array $expandedPaths = [];
    protected ?string $selectedPath = null;
    protected array $allowedExtensions = [];
    protected ?string $lastClickedPath = null;
    protected float $lastClickedAt = 0.0;

    public function __construct()
    {
        parent::__construct(
            title: 'Choose File',
            help: 'Arrows Navigate Enter Select Esc Back',
            position: ['x' => 1, 'y' => 1],
            width: 48,
            height: 16,
        );
    }

    public function show(
        string $workingDirectory,
        ?string $selectedRelativePath = null,
        array $allowedExtensions = [],
    ): void
    {
        $this->workingDirectory = Path::normalize($workingDirectory);
        $this->allowedExtensions = $this->normalizeAllowedExtensions($allowedExtensions);
        $this->entryTree = $this->buildEntryTree($this->workingDirectory);
        $this->expandedPaths = [];
        $this->selectedPath = null;
        $this->lastClickedPath = null;
        $this->lastClickedAt = 0.0;
        $this->isVisible = true;
        $this->refreshContent();
        $this->markDirty();

        if (is_string($selectedRelativePath) && $selectedRelativePath !== '') {
            $this->selectRelativePath($selectedRelativePath);
        }
    }

    public function hide(): void
    {
        if (!$this->isVisible) {
            return;
        }

        $this->isVisible = false;
        $this->markDirty();
    }

    public function isVisible(): bool
    {
        return $this->isVisible;
    }

    public function isDirty(): bool
    {
        return $this->isDirty;
    }

    public function markClean(): void
    {
        $this->isDirty = false;
    }

    public function moveSelection(int $offset): void
    {
        if ($this->visibleEntries === []) {
            return;
        }

        $selectedIndex = $this->getSelectedVisibleIndex() ?? 0;
        $nextIndex = max(0, min($selectedIndex + $offset, count($this->visibleEntries) - 1));
        $this->selectedPath = $this->visibleEntries[$nextIndex]['path'] ?? $this->selectedPath;
        $this->refreshContent();
    }

    public function expandSelection(): void
    {
        $selectedEntry = $this->getSelectedVisibleEntry();

        if (!$selectedEntry || !($selectedEntry['isDirectory'] ?? false)) {
            return;
        }

        if (!($selectedEntry['isExpanded'] ?? false)) {
            $this->expandedPaths[$selectedEntry['path']] = true;
            $this->refreshContent();
            return;
        }

        $selectedDepth = $selectedEntry['depth'] ?? 0;
        $selectedPath = $selectedEntry['path'] ?? '';

        foreach ($this->visibleEntries as $entry) {
            if (
                str_starts_with((string) ($entry['path'] ?? ''), $selectedPath . '.')
                && ($entry['depth'] ?? -1) === $selectedDepth + 1
            ) {
                $this->selectedPath = $entry['path'];
                $this->refreshContent();
                return;
            }
        }
    }

    public function collapseSelection(): void
    {
        $selectedEntry = $this->getSelectedVisibleEntry();

        if (!$selectedEntry) {
            return;
        }

        if (($selectedEntry['isDirectory'] ?? false) && ($selectedEntry['isExpanded'] ?? false)) {
            unset($this->expandedPaths[$selectedEntry['path']]);
            $this->refreshContent();
            return;
        }

        $parentPath = $this->getParentPath((string) $selectedEntry['path']);

        if ($parentPath === null) {
            return;
        }

        $this->selectedPath = $parentPath;
        $this->refreshContent();
    }

    public function submitSelection(): ?string
    {
        $selectedEntry = $this->getSelectedVisibleEntry();

        if (!$selectedEntry) {
            return null;
        }

        if ($selectedEntry['isDirectory'] ?? false) {
            return null;
        }

        return $selectedEntry['item']['relativePath'] ?? null;
    }

    public function clickEntryAtPoint(int $x, int $y): ?string
    {
        if (!$this->isVisible || !$this->containsPoint($x, $y)) {
            return null;
        }

        $entryIndex = $this->resolveContentIndexFromPointY($y);
        $entry = $this->visibleEntries[$entryIndex] ?? null;

        if (!is_array($entry)) {
            return null;
        }

        if ($this->isExpandToggleClick($entry, $x)) {
            $this->toggleEntryExpansion($entry);
            $this->lastClickedPath = null;
            $this->lastClickedAt = 0.0;
            return null;
        }

        $path = is_string($entry['path'] ?? null) ? $entry['path'] : null;

        if ($path === null) {
            return null;
        }

        $isDoubleClick = $this->registerClickAndCheckDoubleClick($path);
        $this->selectedPath = $path;
        $this->refreshContent();

        if (!$isDoubleClick) {
            return null;
        }

        return $this->submitSelection();
    }

    public function syncLayout(int $terminalWidth, int $terminalHeight): void
    {
        $desiredWidth = max(
            36,
            intdiv($terminalWidth * 2, 3),
            mb_strlen($this->title) + 4,
            mb_strlen($this->help) + 4,
        );
        $modalWidth = min($desiredWidth, max(3, $terminalWidth - 2));
        $modalHeight = min(max(10, intdiv($terminalHeight * 2, 3)), max(3, $terminalHeight - 2));
        $modalX = max(1, intdiv($terminalWidth - $modalWidth, 2) + 1);
        $modalY = max(1, intdiv($terminalHeight - $modalHeight, 2) + 1);
        $layoutChanged =
            $this->width !== $modalWidth
            || $this->height !== $modalHeight
            || $this->x !== $modalX
            || $this->y !== $modalY;

        $this->setDimensions($modalWidth, $modalHeight);
        $this->setPosition($modalX, $modalY);

        if ($layoutChanged) {
            $this->markDirty();
        }
    }

    public function update(): void
    {
    }

    protected function usesAutomaticVerticalScrolling(): bool
    {
        return true;
    }

    protected function handleScrollbarOffsetChanged(): void
    {
        $this->markDirty();
    }

    protected function decorateContentLine(string $line, ?Color $contentColor, int $lineIndex): string
    {
        $selectedVisibleIndex = $this->getSelectedVisibleIndex();
        $selectedLineIndex = is_int($selectedVisibleIndex)
            ? $this->getRenderedLineIndexForContentIndex($selectedVisibleIndex)
            : null;

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

        return $this->wrapWithColor($leftBorder, $contentColor)
            . $this->wrapWithSequence($middle, self::SELECTED_ROW_SEQUENCE)
            . $this->wrapWithColor($rightBorder, $contentColor);
    }

    private function buildEntryTree(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return [];
        }

        $tree = [];

        foreach ($entries as $entryName) {
            if ($entryName === '.' || $entryName === '..') {
                continue;
            }

            $entryPath = Path::join($directory, $entryName);
            $isDirectory = is_dir($entryPath);
            $children = $isDirectory ? $this->buildEntryTree($entryPath) : [];

            if (!$isDirectory && !$this->matchesAllowedExtension($entryName)) {
                continue;
            }

            if (
                $isDirectory
                && $this->allowedExtensions !== []
                && $children === []
            ) {
                continue;
            }

            $tree[] = [
                'name' => $entryName,
                'absolutePath' => $entryPath,
                'relativePath' => $this->buildRelativePath($entryPath),
                'isDirectory' => $isDirectory,
                'children' => $children,
            ];
        }

        usort($tree, function (array $left, array $right) {
            if (($left['isDirectory'] ?? false) !== ($right['isDirectory'] ?? false)) {
                return ($left['isDirectory'] ?? false) ? -1 : 1;
            }

            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        return $tree;
    }

    private function normalizeAllowedExtensions(array $allowedExtensions): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(string $extension): string => ltrim(strtolower($extension), '.'),
            array_filter($allowedExtensions, 'is_string'),
        ))));
    }

    private function matchesAllowedExtension(string $entryName): bool
    {
        if ($this->allowedExtensions === []) {
            return true;
        }

        $extension = strtolower((string) pathinfo($entryName, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, $this->allowedExtensions, true);
    }

    private function buildRelativePath(string $absolutePath): string
    {
        $relativePath = substr($absolutePath, strlen($this->workingDirectory));

        return ltrim((string) $relativePath, DIRECTORY_SEPARATOR);
    }

    private function refreshContent(): void
    {
        $this->visibleEntries = $this->buildVisibleEntries($this->entryTree);
        $this->syncSelectedPath();
        $this->content = array_map(fn(array $entry) => $this->formatVisibleEntry($entry), $this->visibleEntries);
        $this->ensureContentLineVisible($this->getSelectedVisibleIndex());
        $this->markDirty();
    }

    private function buildVisibleEntries(array $items, int $depth = 0, string $parentPath = ''): array
    {
        $visibleEntries = [];

        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $path = $parentPath === '' ? (string) $index : $parentPath . '.' . $index;
            $isDirectory = (bool) ($item['isDirectory'] ?? false);
            $isExpanded = $isDirectory && isset($this->expandedPaths[$path]);

            $visibleEntries[] = [
                'path' => $path,
                'item' => $item,
                'depth' => $depth,
                'isDirectory' => $isDirectory,
                'isExpanded' => $isExpanded,
            ];

            if ($isExpanded) {
                $visibleEntries = [
                    ...$visibleEntries,
                    ...$this->buildVisibleEntries($item['children'] ?? [], $depth + 1, $path),
                ];
            }
        }

        return $visibleEntries;
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

        $this->selectedPath = $this->visibleEntries[0]['path'] ?? null;
    }

    private function getSelectedVisibleIndex(): ?int
    {
        return $this->findVisibleIndexByPath($this->selectedPath);
    }

    private function getSelectedVisibleEntry(): ?array
    {
        $selectedIndex = $this->getSelectedVisibleIndex();

        if ($selectedIndex === null) {
            return null;
        }

        return $this->visibleEntries[$selectedIndex] ?? null;
    }

    private function findVisibleIndexByPath(?string $path): ?int
    {
        if ($path === null) {
            return null;
        }

        foreach ($this->visibleEntries as $index => $entry) {
            if (($entry['path'] ?? null) === $path) {
                return $index;
            }
        }

        return null;
    }

    private function formatVisibleEntry(array $entry): string
    {
        $icon = match (true) {
            ($entry['isDirectory'] ?? false) && ($entry['isExpanded'] ?? false) => self::EXPANDED_ICON,
            ($entry['isDirectory'] ?? false) => self::COLLAPSED_ICON,
            default => self::LEAF_ICON,
        };
        $name = $entry['item']['name'] ?? 'Unnamed';
        $indentation = str_repeat('  ', (int) ($entry['depth'] ?? 0));

        return $indentation . $icon . ' ' . $name;
    }

    private function getParentPath(string $path): ?string
    {
        $separatorPosition = strrpos($path, '.');

        if ($separatorPosition === false) {
            return null;
        }

        return substr($path, 0, $separatorPosition);
    }

    private function isExpandToggleClick(array $entry, int $x): bool
    {
        if (!($entry['isDirectory'] ?? false)) {
            return false;
        }

        $iconColumn = $this->getContentAreaLeft() + (((int) ($entry['depth'] ?? 0)) * 2);

        return $x === $iconColumn;
    }

    private function toggleEntryExpansion(array $entry): void
    {
        $path = $entry['path'] ?? null;

        if (!is_string($path) || $path === '' || !($entry['isDirectory'] ?? false)) {
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

    private function selectRelativePath(string $relativePath): void
    {
        $normalizedTarget = str_replace('\\', '/', $relativePath);
        $matchedPath = $this->findEntryPathByRelativePath($this->entryTree, $normalizedTarget);

        if ($matchedPath === null) {
            return;
        }

        $this->selectedPath = $matchedPath;
        $this->refreshContent();
    }

    private function findEntryPathByRelativePath(
        array $items,
        string $targetRelativePath,
        string $parentPath = ''
    ): ?string
    {
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $path = $parentPath === '' ? (string) $index : $parentPath . '.' . $index;
            $entryRelativePath = str_replace('\\', '/', (string) ($item['relativePath'] ?? ''));

            if ($entryRelativePath === $targetRelativePath) {
                return $path;
            }

            $children = $item['children'] ?? [];

            if (!is_array($children) || $children === []) {
                continue;
            }

            $childPath = $this->findEntryPathByRelativePath($children, $targetRelativePath, $path);

            if ($childPath !== null) {
                $this->expandedPaths[$path] = true;
                return $childPath;
            }
        }

        return null;
    }

    private function markDirty(): void
    {
        $this->isDirty = true;
    }
}

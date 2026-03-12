<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\IO\Enumerations\Color;
use Sendama\Console\Editor\IO\Enumerations\KeyCode;
use Sendama\Console\Editor\IO\Input;

class MainPanel extends Widget
{
    private const string DIVIDER_LINE_CHARACTER = '─';
    private const string TAB_DIVIDER_LINE_CHARACTER = '■';
    private const array TAB_TITLES = ['Scene', 'Game', 'Sprite'];
    private const string SCENE_TAB_TITLE = 'Scene';
    private const string SCENE_VIEW_MODE_SELECT = 'select';
    private const string SCENE_VIEW_MODE_MOVE = 'move';
    private const string SCENE_VIEW_MODE_PAN = 'pan';
    private const string SCENE_SELECTION_SEQUENCE = "\033[30;46m";
    private const string SCENE_SELECTION_FOCUSED_SEQUENCE = "\033[5;30;46m";
    private const string SCENE_MOVE_SEQUENCE = "\033[30;43m";
    private const string SCENE_MOVE_FOCUSED_SEQUENCE = "\033[5;30;43m";
    private const string SCENE_PAN_SEQUENCE = "\033[30;44m";
    private const string SCENE_PAN_FOCUSED_SEQUENCE = "\033[5;30;44m";
    private const string GAME_IDLE_PATTERN_CHARACTER = '/';
    private const string GAME_IDLE_PROMPT = 'Shift+5 to Play';
    private const Color DEFAULT_FOCUS_COLOR = Color::LIGHT_CYAN;
    private const Color PLAY_MODE_FOCUS_COLOR = Color::BROWN;

    protected int $activeTabIndex = 0;
    protected int $activeTabOffset = 0;
    protected int $activeTabLength = 0;
    protected Color $activeIndicatorColor = Color::LIGHT_CYAN;
    protected bool $isPlayModeActive = false;
    protected array $gameIdleContentIndexes = [];
    protected ?int $gameIdlePromptContentIndex = null;
    protected array $sceneObjects = [];
    protected array $visibleSceneObjects = [];
    protected ?string $selectedScenePath = null;
    protected ?array $pendingInspectionItem = null;
    protected ?array $pendingHierarchyMutation = null;
    protected string $sceneInteractionMode = self::SCENE_VIEW_MODE_SELECT;
    protected array $sceneLineHighlights = [];
    protected string $projectDirectory;
    protected int $sceneWidth = DEFAULT_TERMINAL_WIDTH;
    protected int $sceneHeight = DEFAULT_TERMINAL_HEIGHT;
    protected string $environmentTileMapPath = 'Maps/example';
    protected int $sceneViewportOffsetX = 0;
    protected int $sceneViewportOffsetY = 0;
    protected string $modeHelpLabel = '';

    public function __construct(
        array $position = ['x' => 37, 'y' => 1],
        int $width = 96,
        int $height = 21,
        array $sceneObjects = [],
        ?string $workingDirectory = null,
        ?int $sceneWidth = null,
        ?int $sceneHeight = null,
        ?string $environmentTileMapPath = null,
    )
    {
        parent::__construct('', '', $position, $width, $height);
        $this->focusBorderColor = self::DEFAULT_FOCUS_COLOR;
        $this->sceneObjects = array_values($sceneObjects);
        $this->projectDirectory = is_string($workingDirectory) && $workingDirectory !== ''
            ? $workingDirectory
            : (getcwd() ?: '.');
        $this->sceneWidth = max(1, $sceneWidth ?? DEFAULT_TERMINAL_WIDTH);
        $this->sceneHeight = max(1, $sceneHeight ?? DEFAULT_TERMINAL_HEIGHT);
        $this->environmentTileMapPath = is_string($environmentTileMapPath) && $environmentTileMapPath !== ''
            ? $environmentTileMapPath
            : 'Maps/example';

        $this->refreshContent();
    }

    public function getActiveTab(): string
    {
        return self::TAB_TITLES[$this->activeTabIndex];
    }

    public function activateNextTab(): void
    {
        $this->activeTabIndex = ($this->activeTabIndex + 1) % count(self::TAB_TITLES);
        $this->refreshContent();
    }

    public function activatePreviousTab(): void
    {
        $this->activeTabIndex = ($this->activeTabIndex - 1 + count(self::TAB_TITLES)) % count(self::TAB_TITLES);
        $this->refreshContent();
    }

    public function selectTab(string $tabTitle): void
    {
        $tabIndex = array_search($tabTitle, self::TAB_TITLES, true);

        if ($tabIndex === false) {
            return;
        }

        $this->activeTabIndex = $tabIndex;
        $this->refreshContent();
    }

    public function setSceneObjects(array $sceneObjects): void
    {
        $this->sceneObjects = array_values($sceneObjects);
        $this->syncSelectedScenePath();
        $this->refreshContent();
    }

    public function selectSceneObject(?string $path): void
    {
        $this->selectedScenePath = $path;
        $this->syncSelectedScenePath();
        $this->refreshContent();
    }

    public function setSceneDimensions(int $sceneWidth, int $sceneHeight): void
    {
        $this->sceneWidth = max(1, $sceneWidth);
        $this->sceneHeight = max(1, $sceneHeight);
        $this->refreshContent();
    }

    public function setEnvironmentTileMapPath(string $environmentTileMapPath): void
    {
        $this->environmentTileMapPath = $environmentTileMapPath !== ''
            ? $environmentTileMapPath
            : 'Maps/example';
        $this->refreshContent();
    }

    public function consumeInspectionRequest(): ?array
    {
        $pendingInspectionItem = $this->pendingInspectionItem;
        $this->pendingInspectionItem = null;

        return $pendingInspectionItem;
    }

    public function consumeHierarchyMutation(): ?array
    {
        $pendingHierarchyMutation = $this->pendingHierarchyMutation;
        $this->pendingHierarchyMutation = null;

        return $pendingHierarchyMutation;
    }

    public function cycleFocusForward(): bool
    {
        $this->activateNextTab();

        return true;
    }

    public function cycleFocusBackward(): bool
    {
        $this->activatePreviousTab();

        return true;
    }

    public function setPlayModeActive(bool $isPlayModeActive): void
    {
        if ($this->isPlayModeActive === $isPlayModeActive) {
            return;
        }

        $this->isPlayModeActive = $isPlayModeActive;
        $this->focusBorderColor = $isPlayModeActive
            ? self::PLAY_MODE_FOCUS_COLOR
            : self::DEFAULT_FOCUS_COLOR;
        $this->refreshContent();
    }

    public function update(): void
    {
        if ($this->hasFocus() && $this->isSceneTabActive() && !$this->isPlayModeActive) {
            if (Input::getCurrentInput() === 'Q') {
                $this->sceneInteractionMode = self::SCENE_VIEW_MODE_SELECT;
                $this->refreshContent();
                return;
            }

            if (Input::getCurrentInput() === 'W') {
                $this->sceneInteractionMode = self::SCENE_VIEW_MODE_MOVE;
                $this->syncSelectedScenePath();
                $this->queueInspectionForSelectedSceneObject();
                $this->refreshContent();
                return;
            }

            if (Input::getCurrentInput() === 'E') {
                $this->sceneInteractionMode = self::SCENE_VIEW_MODE_PAN;
                $this->refreshContent();
                return;
            }

            if ($this->sceneInteractionMode === self::SCENE_VIEW_MODE_MOVE) {
                if ($this->handleSceneMoveModeInput()) {
                    return;
                }
            } elseif ($this->sceneInteractionMode === self::SCENE_VIEW_MODE_PAN) {
                if ($this->handleScenePanModeInput()) {
                    return;
                }
            } elseif ($this->handleSceneSelectModeInput()) {
                return;
            }
        }

        $this->refreshContent();
    }

    public function handleMouseClick(int $x, int $y): void
    {
        if (!$this->containsPoint($x, $y) || $y !== $this->getContentAreaTop()) {
            return;
        }

        $currentX = $this->getContentAreaLeft();

        foreach (self::TAB_TITLES as $index => $tabTitle) {
            if ($index > 0) {
                $currentX += 2;
            }

            $tabStart = $currentX;
            $tabEnd = $tabStart + mb_strlen($tabTitle) - 1;

            if ($x >= $tabStart && $x <= $tabEnd) {
                $this->activeTabIndex = $index;
                $this->refreshContent();
                return;
            }

            $currentX = $tabEnd + 1;
        }
    }

    protected function decorateContentLine(string $line, ?Color $contentColor, int $lineIndex): string
    {
        $contentIndex = $lineIndex - $this->padding->topPadding;

        if ($lineIndex !== 1) {
            if (isset($this->sceneLineHighlights[$contentIndex])) {
                return $this->decorateSceneLine($line, $contentColor, $contentIndex);
            }

            if (!in_array($contentIndex, $this->gameIdleContentIndexes, true)) {
                return parent::decorateContentLine($line, $contentColor, $lineIndex);
            }

            return $this->decorateGameIdleLine($line, $contentColor, $contentIndex);
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
        $indicatorStart = $this->padding->leftPadding + $this->activeTabOffset;
        $indicatorLength = $this->activeTabLength;
        $beforeIndicator = mb_substr($middle, 0, $indicatorStart);
        $indicator = mb_substr($middle, $indicatorStart, $indicatorLength);
        $afterIndicator = mb_substr($middle, $indicatorStart + $indicatorLength);

        return $this->wrapWithColor($leftBorder, $borderColor)
            . $this->wrapWithColor($beforeIndicator, $contentColor)
            . $this->wrapWithColor($indicator, $this->activeIndicatorColor)
            . $this->wrapWithColor($afterIndicator, $contentColor)
            . $this->wrapWithColor($rightBorder, $borderColor);
    }

    protected function buildBorderLine(string $label, bool $isTopBorder): string
    {
        if ($isTopBorder) {
            return parent::buildBorderLine($label, true);
        }

        return $this->buildSplitHelpBorder($this->help, $this->modeHelpLabel);
    }

    private function decorateGameIdleLine(string $line, ?Color $contentColor, int $contentIndex): string
    {
        $visibleLine = mb_substr($line, 0, $this->width);
        $visibleLength = mb_strlen($visibleLine);

        if ($visibleLength <= 1) {
            return parent::decorateContentLine($line, $contentColor, $contentIndex);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleLength > 2 ? mb_substr($visibleLine, 1, $visibleLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);
        $borderColor = $this->hasFocus() ? $this->focusBorderColor : $contentColor;
        $decoratedMiddle = $this->colorizeGameIdleMiddle($middle, $contentIndex === $this->gameIdlePromptContentIndex);

        return $this->wrapWithColor($leftBorder, $borderColor)
            . $decoratedMiddle
            . $this->wrapWithColor($rightBorder, $borderColor);
    }

    private function colorizeGameIdleMiddle(string $middle, bool $isPromptLine): string
    {
        $output = '';
        $promptLength = mb_strlen(self::GAME_IDLE_PROMPT);
        $promptStart = $isPromptLine
            ? max(0, intdiv(mb_strlen($middle) - $promptLength, 2))
            : -1;
        $promptEnd = $promptStart >= 0 ? $promptStart + $promptLength : -1;

        for ($index = 0; $index < mb_strlen($middle); $index++) {
            $character = mb_substr($middle, $index, 1);

            if ($isPromptLine && $index >= $promptStart && $index < $promptEnd) {
                $output .= $this->wrapWithColor($character, Color::LIGHT_GRAY);
                continue;
            }

            if ($character === self::GAME_IDLE_PATTERN_CHARACTER) {
                $output .= $this->wrapWithColor($character, Color::BLUE);
                continue;
            }

            $output .= $character;
        }

        return $output;
    }

    private function refreshContent(): void
    {
        $tabsLine = '';
        $this->activeTabOffset = 0;
        $this->gameIdleContentIndexes = [];
        $this->gameIdlePromptContentIndex = null;
        $this->sceneLineHighlights = [];
        $this->visibleSceneObjects = $this->flattenSceneObjects($this->sceneObjects);
        $this->syncSelectedScenePath();
        $this->updateHelpInfo();

        foreach (self::TAB_TITLES as $index => $tabTitle) {
            if ($index > 0) {
                $tabsLine .= '  ';
            }

            if ($index === $this->activeTabIndex) {
                $this->activeTabOffset = mb_strlen($tabsLine);
            }

            $tabsLine .= $tabTitle;
        }

        $dividerWidth = max(0, $this->innerWidth - 2);
        $activeTabTitle = self::TAB_TITLES[$this->activeTabIndex];
        $this->activeTabLength = mb_strlen($activeTabTitle);
        $dividerLine = $this->buildDividerLine($dividerWidth);
        $content = [$tabsLine, $dividerLine];

        if ($this->isSceneTabActive()) {
            $content = [...$content, ...$this->buildSceneCanvasContent()];
        } elseif ($this->shouldRenderIdleGameView()) {
            $contentWidth = max(0, $this->innerWidth - $this->padding->leftPadding - $this->padding->rightPadding);
            $idleRows = max(0, $this->innerHeight - count($content));
            $promptRow = $idleRows > 0 ? intdiv($idleRows, 2) : null;

            for ($row = 0; $row < $idleRows; $row++) {
                $content[] = $this->buildGameIdleLine(
                    $contentWidth,
                    $row,
                    $promptRow !== null && $row === $promptRow,
                );
                $contentIndex = count($content) - 1;
                $this->gameIdleContentIndexes[] = $contentIndex;

                if ($promptRow !== null && $row === $promptRow) {
                    $this->gameIdlePromptContentIndex = $contentIndex;
                }
            }
        }

        $this->content = $content;
    }

    private function updateHelpInfo(): void
    {
        if ($this->isPlayModeActive) {
            $this->help = 'Tab/Shift+Tab tabs  Shift+5 stop play';
            $this->modeHelpLabel = 'Mode: Play';
            return;
        }

        if ($this->isSceneTabActive()) {
            [$this->help, $this->modeHelpLabel] = match ($this->sceneInteractionMode) {
                self::SCENE_VIEW_MODE_MOVE => [
                    'Arrows move  Shift+Q select  Shift+E pan',
                    'Mode: Scene Move',
                ],
                self::SCENE_VIEW_MODE_PAN => [
                    'Arrows pan  Shift+Q select  Shift+W move',
                    'Mode: Scene Pan',
                ],
                default => [
                    'Arrows cycle  Enter inspect  Shift+W move  Shift+E pan',
                    'Mode: Scene Select',
                ],
            };
            return;
        }

        if ($this->getActiveTab() === 'Game') {
            $this->help = 'Tab/Shift+Tab tabs  Shift+5 play';
            $this->modeHelpLabel = 'Mode: Game';
            return;
        }

        $this->help = 'Tab/Shift+Tab tabs';
        $this->modeHelpLabel = 'Mode: ' . $this->getActiveTab();
    }

    private function shouldRenderIdleGameView(): bool
    {
        return $this->getActiveTab() === 'Game' && !$this->isPlayModeActive;
    }

    private function isSceneTabActive(): bool
    {
        return $this->getActiveTab() === self::SCENE_TAB_TITLE;
    }

    private function handleSceneSelectModeInput(): bool
    {
        if ($this->visibleSceneObjects === []) {
            return false;
        }

        if (Input::isKeyDown(KeyCode::UP) || Input::isKeyDown(KeyCode::LEFT)) {
            $this->moveSceneSelection(-1);
            return true;
        }

        if (Input::isKeyDown(KeyCode::DOWN) || Input::isKeyDown(KeyCode::RIGHT)) {
            $this->moveSceneSelection(1);
            return true;
        }

        if (Input::isKeyDown(KeyCode::ENTER)) {
            $this->activateSceneSelection();
            return true;
        }

        return false;
    }

    private function handleSceneMoveModeInput(): bool
    {
        if ($this->visibleSceneObjects === []) {
            return false;
        }

        $this->syncSelectedScenePath();

        if (Input::isKeyDown(KeyCode::UP)) {
            $this->moveSelectedSceneObject(0, -1);
            return true;
        }

        if (Input::isKeyDown(KeyCode::RIGHT)) {
            $this->moveSelectedSceneObject(1, 0);
            return true;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $this->moveSelectedSceneObject(0, 1);
            return true;
        }

        if (Input::isKeyDown(KeyCode::LEFT)) {
            $this->moveSelectedSceneObject(-1, 0);
            return true;
        }

        return false;
    }

    private function handleScenePanModeInput(): bool
    {
        if (Input::isKeyDown(KeyCode::UP)) {
            $this->panSceneViewport(0, -1);
            return true;
        }

        if (Input::isKeyDown(KeyCode::RIGHT)) {
            $this->panSceneViewport(1, 0);
            return true;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $this->panSceneViewport(0, 1);
            return true;
        }

        if (Input::isKeyDown(KeyCode::LEFT)) {
            $this->panSceneViewport(-1, 0);
            return true;
        }

        return false;
    }

    private function moveSceneSelection(int $offset): void
    {
        if ($this->visibleSceneObjects === []) {
            return;
        }

        $selectedIndex = $this->getSelectedSceneObjectIndex() ?? 0;
        $nextIndex = ($selectedIndex + $offset + count($this->visibleSceneObjects)) % count($this->visibleSceneObjects);
        $this->selectedScenePath = $this->visibleSceneObjects[$nextIndex]['path'] ?? $this->selectedScenePath;
        $this->queueInspectionForSelectedSceneObject();
        $this->refreshContent();
    }

    private function activateSceneSelection(): void
    {
        $this->queueInspectionForSelectedSceneObject();
    }

    private function moveSelectedSceneObject(int $deltaX, int $deltaY): void
    {
        $selectedNode = $this->getSelectedSceneNode();

        if (!is_array($selectedNode) || !is_array($selectedNode['item'] ?? null)) {
            return;
        }

        $selectedPath = $selectedNode['path'] ?? null;

        if (!is_string($selectedPath) || $selectedPath === '') {
            return;
        }

        $selectedItem = $selectedNode['item'];
        $position = $this->normalizeVector($selectedItem['position'] ?? null);
        $selectedItem['position'] = [
            'x' => $position['x'] + $deltaX,
            'y' => $position['y'] + $deltaY,
        ];

        if (!$this->applySceneObjectMutation($selectedPath, $selectedItem)) {
            return;
        }

        $this->pendingHierarchyMutation = [
            'path' => $selectedPath,
            'value' => $selectedItem,
        ];
        $this->pendingInspectionItem = [
            'context' => 'hierarchy',
            'name' => $selectedItem['name'] ?? 'Unnamed Object',
            'type' => $this->resolveInspectableType($selectedItem),
            'path' => $selectedPath,
            'value' => $selectedItem,
        ];
        $this->refreshContent();
    }

    private function panSceneViewport(int $deltaX, int $deltaY): void
    {
        $canvasWidth = max(0, $this->innerWidth - $this->padding->leftPadding - $this->padding->rightPadding);
        $canvasHeight = max(0, $this->innerHeight - 2);
        $maxOffsetX = max(0, $this->sceneWidth - max(1, $canvasWidth));
        $maxOffsetY = max(0, $this->sceneHeight - max(1, $canvasHeight));

        $this->sceneViewportOffsetX = max(0, min($this->sceneViewportOffsetX + $deltaX, $maxOffsetX));
        $this->sceneViewportOffsetY = max(0, min($this->sceneViewportOffsetY + $deltaY, $maxOffsetY));
        $this->refreshContent();
    }

    private function decorateSceneLine(string $line, ?Color $contentColor, int $contentIndex): string
    {
        $highlight = $this->sceneLineHighlights[$contentIndex] ?? null;

        if (!is_array($highlight)) {
            return parent::decorateContentLine($line, $contentColor, $contentIndex);
        }

        $visibleLine = mb_substr($line, 0, $this->width);
        $visibleLength = mb_strlen($visibleLine);

        if ($visibleLength <= 1) {
            return parent::decorateContentLine($line, $contentColor, $contentIndex);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleLength > 2 ? mb_substr($visibleLine, 1, $visibleLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);
        $borderColor = $this->hasFocus() ? $this->focusBorderColor : $contentColor;
        $highlightStart = min(
            max(0, $this->padding->leftPadding + (int) ($highlight['start'] ?? 0)),
            mb_strlen($middle),
        );
        $highlightLength = max(
            0,
            min((int) ($highlight['length'] ?? 0), mb_strlen($middle) - $highlightStart),
        );

        if ($highlightLength === 0) {
            return parent::decorateContentLine($line, $contentColor, $contentIndex);
        }

        $beforeHighlight = mb_substr($middle, 0, $highlightStart);
        $highlightText = mb_substr($middle, $highlightStart, $highlightLength);
        $afterHighlight = mb_substr($middle, $highlightStart + $highlightLength);

        return $this->wrapWithColor($leftBorder, $borderColor)
            . $this->wrapWithColor($beforeHighlight, $contentColor)
            . $this->wrapWithSequence($highlightText, $this->resolveSceneHighlightSequence())
            . $this->wrapWithColor($afterHighlight, $contentColor)
            . $this->wrapWithColor($rightBorder, $borderColor);
    }

    private function resolveSceneHighlightSequence(): string
    {
        return match ($this->sceneInteractionMode) {
            self::SCENE_VIEW_MODE_MOVE => $this->hasFocus()
                ? self::SCENE_MOVE_FOCUSED_SEQUENCE
                : self::SCENE_MOVE_SEQUENCE,
            self::SCENE_VIEW_MODE_PAN => $this->hasFocus()
                ? self::SCENE_PAN_FOCUSED_SEQUENCE
                : self::SCENE_PAN_SEQUENCE,
            default => $this->hasFocus()
                ? self::SCENE_SELECTION_FOCUSED_SEQUENCE
                : self::SCENE_SELECTION_SEQUENCE,
        };
    }

    private function buildSceneCanvasContent(): array
    {
        $canvasWidth = max(0, $this->innerWidth - $this->padding->leftPadding - $this->padding->rightPadding);
        $canvasHeight = max(0, $this->innerHeight - 2);

        if ($canvasWidth <= 0 || $canvasHeight <= 0) {
            return [];
        }

        $canvas = [];

        for ($row = 0; $row < $canvasHeight; $row++) {
            $canvas[$row] = array_fill(0, $canvasWidth, ' ');
        }

        $this->renderEnvironmentTileMap($canvas, $canvasWidth, $canvasHeight);

        foreach ($this->visibleSceneObjects as $sceneObject) {
            $item = $sceneObject['item'] ?? null;

            if (!is_array($item)) {
                continue;
            }

            $position = $sceneObject['position'] ?? $this->normalizeVector($item['position'] ?? null);
            $row = (int) ($position['y'] ?? 0) - $this->sceneViewportOffsetY;
            $column = (int) ($position['x'] ?? 0) - $this->sceneViewportOffsetX;
            $renderLines = is_array($sceneObject['renderLines'] ?? null)
                ? $sceneObject['renderLines']
                : [];

            foreach ($renderLines as $lineOffset => $renderLine) {
                $targetRow = $row + $lineOffset;

                if ($targetRow < 0 || $targetRow >= $canvasHeight) {
                    continue;
                }

                $characters = preg_split('//u', $renderLine, -1, PREG_SPLIT_NO_EMPTY);

                if (!is_array($characters) || $characters === []) {
                    continue;
                }

                $startCharacterIndex = max(0, -$column);
                $targetColumn = max(0, $column);

                for (
                    $characterIndex = $startCharacterIndex;
                    $characterIndex < count($characters) && $targetColumn < $canvasWidth;
                    $characterIndex++, $targetColumn++
                ) {
                    $canvas[$targetRow][$targetColumn] = $characters[$characterIndex];
                }

                if (($sceneObject['path'] ?? null) !== $this->selectedScenePath) {
                    continue;
                }

                $visibleLength = min(
                    count($characters) - $startCharacterIndex,
                    $canvasWidth - max(0, $column),
                );

                if ($visibleLength <= 0) {
                    continue;
                }

                $this->sceneLineHighlights[2 + $targetRow] = [
                    'start' => max(0, $column),
                    'length' => $visibleLength,
                ];
            }
        }

        return array_map(
            static fn(array $lineCharacters): string => implode('', $lineCharacters),
            $canvas,
        );
    }

    private function flattenSceneObjects(array $items, string $parentPath = 'scene'): array
    {
        $flattenedObjects = [];

        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $path = $parentPath . '.' . $index;
            $renderLines = $this->resolveSceneObjectRenderLines($item);

            if ($renderLines !== []) {
                $flattenedObjects[] = [
                    'path' => $path,
                    'item' => $item,
                    'position' => $this->normalizeVector($item['position'] ?? null),
                    'renderLines' => $renderLines,
                ];
            }

            if (is_array($item['children'] ?? null) && $item['children'] !== []) {
                $flattenedObjects = [
                    ...$flattenedObjects,
                    ...$this->flattenSceneObjects($item['children'], $path),
                ];
            }
        }

        return $flattenedObjects;
    }

    private function syncSelectedScenePath(): void
    {
        if ($this->visibleSceneObjects === []) {
            $this->selectedScenePath = null;
            return;
        }

        foreach ($this->visibleSceneObjects as $sceneObject) {
            if (($sceneObject['path'] ?? null) === $this->selectedScenePath) {
                return;
            }
        }

        $this->selectedScenePath = $this->visibleSceneObjects[0]['path'] ?? null;
    }

    private function queueInspectionForSelectedSceneObject(): void
    {
        $selectedNode = $this->getSelectedSceneNode();

        if (!is_array($selectedNode) || !is_array($selectedNode['item'] ?? null)) {
            return;
        }

        $selectedItem = $selectedNode['item'];
        $selectedPath = $selectedNode['path'] ?? null;

        if (!is_string($selectedPath) || $selectedPath === '') {
            return;
        }

        $this->pendingInspectionItem = [
            'context' => 'hierarchy',
            'name' => $selectedItem['name'] ?? 'Unnamed Object',
            'type' => $this->resolveInspectableType($selectedItem),
            'path' => $selectedPath,
            'value' => $selectedItem,
        ];
    }

    private function getSelectedSceneNode(): ?array
    {
        $selectedIndex = $this->getSelectedSceneObjectIndex();

        if ($selectedIndex === null) {
            return null;
        }

        return $this->visibleSceneObjects[$selectedIndex] ?? null;
    }

    private function getSelectedSceneObjectIndex(): ?int
    {
        if ($this->selectedScenePath === null) {
            return null;
        }

        foreach ($this->visibleSceneObjects as $index => $sceneObject) {
            if (($sceneObject['path'] ?? null) === $this->selectedScenePath) {
                return $index;
            }
        }

        return null;
    }

    private function resolveSceneObjectRenderLines(array $item): array
    {
        $spriteRenderLines = $this->buildSpriteRenderLines($item);

        if ($spriteRenderLines !== []) {
            return $spriteRenderLines;
        }

        if (is_string($item['text'] ?? null) && $item['text'] !== '') {
            $textLines = preg_split('/\R/u', (string) $item['text']);

            return is_array($textLines) ? $textLines : [(string) $item['text']];
        }

        return [];
    }

    private function resolveInspectableType(array $item): string
    {
        $type = $item['type'] ?? null;

        if (!is_string($type) || $type === '') {
            return 'Unknown';
        }

        $normalizedType = ltrim($type, '\\');
        $normalizedType = preg_replace('/::class$/', '', $normalizedType) ?? $normalizedType;
        $typeSegments = explode('\\', $normalizedType);

        return end($typeSegments) ?: $normalizedType;
    }

    private function normalizeVector(mixed $value): array
    {
        if (!is_array($value)) {
            return ['x' => 0, 'y' => 0];
        }

        return [
            'x' => $this->normalizeSceneCoordinate($value['x'] ?? 0),
            'y' => $this->normalizeSceneCoordinate($value['y'] ?? 0),
        ];
    }

    private function normalizeSceneCoordinate(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        return 0;
    }

    private function buildSpriteRenderLines(array $item): array
    {
        $sprite = is_array($item['sprite'] ?? null) ? $item['sprite'] : [];
        $texture = is_array($sprite['texture'] ?? null) ? $sprite['texture'] : [];
        $texturePath = is_string($texture['path'] ?? null) && $texture['path'] !== ''
            ? $texture['path']
            : null;

        if ($texturePath === null) {
            return [];
        }

        $offset = $this->normalizeVector($texture['position'] ?? null);
        $size = $this->normalizeVector($texture['size'] ?? null);

        return $this->buildTexturePreviewLines($texturePath, $offset, $size);
    }

    private function buildTexturePreviewLines(string $texturePath, array $offset, array $size): array
    {
        if ((int) $size['x'] <= 0 || (int) $size['y'] <= 0) {
            return [];
        }

        $resolvedTextureFilePath = $this->resolveAssetFilePath($texturePath, 'texture');

        if ($resolvedTextureFilePath === null) {
            return [];
        }

        $textureContents = file_get_contents($resolvedTextureFilePath);

        if ($textureContents === false || $textureContents === '') {
            return [];
        }

        $textureRows = preg_split('/\R/u', rtrim($textureContents, "\r\n"));

        if ($textureRows === false) {
            return [];
        }

        if (count($textureRows) <= 1) {
            $textureRows = $this->expandSingleLineTexture(
                $textureRows[0] ?? '',
                (int) $size['x'],
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

        return $previewLines;
    }

    private function resolveAssetFilePath(string $assetPath, string $defaultExtension): ?string
    {
        $normalizedTexturePath = str_replace('\\', '/', $assetPath);
        $candidatePaths = [];

        if ($this->hasFileExtension($normalizedTexturePath)) {
            $candidatePaths[] = $normalizedTexturePath;
        } else {
            $candidatePaths[] = $normalizedTexturePath . '.' . ltrim($defaultExtension, '.');
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

    private function renderEnvironmentTileMap(array &$canvas, int $canvasWidth, int $canvasHeight): void
    {
        $tileMapLines = $this->buildEnvironmentTileMapLines();

        if ($tileMapLines === []) {
            return;
        }

        foreach ($tileMapLines as $rowIndex => $tileMapLine) {
            $targetRow = $rowIndex - $this->sceneViewportOffsetY;

            if ($targetRow < 0 || $targetRow >= $canvasHeight) {
                continue;
            }

            $characters = preg_split('//u', $tileMapLine, -1, PREG_SPLIT_NO_EMPTY);

            if (!is_array($characters) || $characters === []) {
                continue;
            }

            $startCharacterIndex = max(0, $this->sceneViewportOffsetX);
            $targetColumn = 0;

            for (
                $characterIndex = $startCharacterIndex;
                $characterIndex < count($characters) && $targetColumn < $canvasWidth;
                $characterIndex++, $targetColumn++
            ) {
                $canvas[$targetRow][$targetColumn] = $characters[$characterIndex];
            }
        }
    }

    private function buildEnvironmentTileMapLines(): array
    {
        if ($this->environmentTileMapPath === '') {
            return [];
        }

        $resolvedTileMapPath = $this->resolveAssetFilePath($this->environmentTileMapPath, 'tmap');

        if ($resolvedTileMapPath === null) {
            return [];
        }

        $tileMapContents = file_get_contents($resolvedTileMapPath);

        if ($tileMapContents === false || $tileMapContents === '') {
            return [];
        }

        $tileMapLines = preg_split('/\R/u', rtrim($tileMapContents, "\r\n"));

        return is_array($tileMapLines) ? $tileMapLines : [];
    }

    private function buildSplitHelpBorder(string $leftLabel, string $rightLabel): string
    {
        $availableLabelWidth = max(0, $this->width - 3);
        $visibleRightLabel = $this->clipContentToWidth($rightLabel, $availableLabelWidth);
        $remainingWidth = max(0, $availableLabelWidth - mb_strlen($visibleRightLabel));
        $visibleLeftLabel = $this->clipContentToWidth($leftLabel, $remainingWidth);
        $fillerWidth = max(0, $availableLabelWidth - mb_strlen($visibleLeftLabel) - mb_strlen($visibleRightLabel));

        return $this->borderPack->bottomLeft
            . $this->borderPack->horizontal
            . $visibleLeftLabel
            . str_repeat($this->borderPack->horizontal, $fillerWidth)
            . $visibleRightLabel
            . $this->borderPack->bottomRight;
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

    private function applySceneObjectMutation(string $path, array $value): bool
    {
        $segments = explode('.', $path);

        if (($segments[0] ?? null) !== 'scene') {
            return false;
        }

        array_shift($segments);

        if ($segments === []) {
            return false;
        }

        $sceneObjects = $this->sceneObjects;
        $nodeArray = &$sceneObjects;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if (!ctype_digit((string) $segment)) {
                return false;
            }

            $numericSegment = (int) $segment;

            if (!isset($nodeArray[$numericSegment]) || !is_array($nodeArray[$numericSegment])) {
                return false;
            }

            if ($index === $lastIndex) {
                $nodeArray[$numericSegment] = $value;
                $this->sceneObjects = array_values($sceneObjects);

                return true;
            }

            if (!isset($nodeArray[$numericSegment]['children']) || !is_array($nodeArray[$numericSegment]['children'])) {
                return false;
            }

            $nodeArray = &$nodeArray[$numericSegment]['children'];
        }

        return false;
    }

    private function buildGameIdleLine(int $width, int $row, bool $includePrompt): string
    {
        if ($width <= 0) {
            return '';
        }

        $characters = array_fill(0, $width, ' ');

        for ($column = 0; $column < $width; $column++) {
            if ((($column + ($row * 2)) % 3) === 0) {
                $characters[$column] = self::GAME_IDLE_PATTERN_CHARACTER;
            }
        }

        if (!$includePrompt) {
            return implode('', $characters);
        }

        $promptLength = mb_strlen(self::GAME_IDLE_PROMPT);
        $promptStart = max(0, intdiv($width - $promptLength, 2));
        $clearStart = max(0, $promptStart - 2);
        $clearEnd = min($width, $promptStart + $promptLength + 2);

        for ($index = $clearStart; $index < $clearEnd; $index++) {
            $characters[$index] = ' ';
        }

        for ($index = 0; $index < $promptLength && ($promptStart + $index) < $width; $index++) {
            $characters[$promptStart + $index] = mb_substr(self::GAME_IDLE_PROMPT, $index, 1);
        }

        return implode('', $characters);
    }

    private function buildDividerLine(int $dividerWidth): string
    {
        if ($dividerWidth <= 0) {
            return '';
        }

        $characters = array_fill(0, $dividerWidth, self::DIVIDER_LINE_CHARACTER);

        for ($index = 0; $index < $this->activeTabLength; $index++) {
            $characterIndex = $this->activeTabOffset + $index;

            if (!isset($characters[$characterIndex])) {
                break;
            }

            $characters[$characterIndex] = self::TAB_DIVIDER_LINE_CHARACTER;
        }

        return implode('', $characters);
    }
}

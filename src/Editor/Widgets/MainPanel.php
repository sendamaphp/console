<?php

namespace Sendama\Console\Editor\Widgets;

use Atatusoft\Termutil\Events\MouseEvent;
use Atatusoft\Termutil\IO\Enumerations\Color;
use Sendama\Console\Editor\FocusTargetContext;
use Sendama\Console\Editor\IO\Enumerations\KeyCode;
use Sendama\Console\Editor\IO\Input;
use Sendama\Console\Util\Path;

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
    private const string SPRITE_CURSOR_SEQUENCE = "\033[30;47m";
    private const string SPRITE_CURSOR_FOCUSED_SEQUENCE = "\033[5;30;47m";
    private const string GAME_IDLE_PATTERN_CHARACTER = '/';
    private const string GAME_IDLE_PROMPT = 'Shift+5 to Play';
    private const string SCENE_PLACEHOLDER_CHARACTER = 'x';
    private const Color DEFAULT_FOCUS_COLOR = Color::LIGHT_CYAN;
    private const Color PLAY_MODE_FOCUS_COLOR = Color::BROWN;
    private const string SPRITE_MODAL_CREATE = 'create_asset';
    private const string SPRITE_MODAL_DELETE = 'delete_asset';
    private const string SPRITE_MODAL_CHARACTER = 'character_picker';
    private const int DEFAULT_TEXTURE_WIDTH = 16;
    private const int DEFAULT_TEXTURE_HEIGHT = 16;
    private const float DOUBLE_CLICK_THRESHOLD_SECONDS = 0.35;
    private const array SPECIAL_CHARACTER_OPTIONS = [
        '█ Full Block',
        '▓ Dark Shade',
        '▒ Medium Shade',
        '░ Light Shade',
        '■ Square',
        '□ Hollow Square',
        '▣ Filled Outline Square',
        '▢ Dotted Outline Square',
        '▲ Triangle Up',
        '▼ Triangle Down',
        '◄ Triangle Left',
        '► Triangle Right',
        '◢ Triangle Lower Right',
        '◣ Triangle Lower Left',
        '◤ Triangle Upper Left',
        '◥ Triangle Upper Right',
        '● Circle',
        '○ Hollow Circle',
        '★ Star',
        '☆ Hollow Star',
        '◆ Diamond',
        '◇ Hollow Diamond',
        '♥ Heart',
        '♦ Diamond Suit',
        '♣ Club',
        '♠ Spade',
        '• Bullet',
        '◦ Hollow Bullet',
        '· Dot',
        '│ Vertical',
        '─ Horizontal',
        '┌ Corner TL',
        '┐ Corner TR',
        '└ Corner BL',
        '┘ Corner BR',
        '├ Tee Left',
        '┤ Tee Right',
        '┬ Tee Down',
        '┴ Tee Up',
        '┼ Cross',
        '╭ Rounded Corner TL',
        '╮ Rounded Corner TR',
        '╰ Rounded Corner BL',
        '╯ Rounded Corner BR',
        '║ Double Vertical',
        '═ Double Horizontal',
        '╔ Double Corner TL',
        '╗ Double Corner TR',
        '╚ Double Corner BL',
        '╝ Double Corner BR',
        '╠ Double Tee Left',
        '╣ Double Tee Right',
        '╦ Double Tee Down',
        '╩ Double Tee Up',
        '╬ Double Cross',
        '╱ Diagonal Slash',
        '╲ Diagonal Backslash',
        '╳ Diagonal Cross',
        '← Arrow Left',
        '↑ Arrow Up',
        '→ Arrow Right',
        '↓ Arrow Down',
        '↔ Arrow Left Right',
        '↕ Arrow Up Down',
        '⇐ Double Arrow Left',
        '⇑ Double Arrow Up',
        '⇒ Double Arrow Right',
        '⇓ Double Arrow Down',
    ];

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
    protected array $sceneClickTargets = [];
    protected string $projectDirectory;
    protected int $sceneWidth = DEFAULT_TERMINAL_WIDTH;
    protected int $sceneHeight = DEFAULT_TERMINAL_HEIGHT;
    protected string $environmentTileMapPath = 'Maps/example';
    protected int $sceneViewportOffsetX = 0;
    protected int $sceneViewportOffsetY = 0;
    protected string $modeHelpLabel = '';
    protected array $spriteLineHighlights = [];
    protected ?array $activeSpriteAsset = null;
    protected array $spriteGrid = [];
    protected int $spriteGridWidth = 0;
    protected int $spriteGridHeight = 0;
    protected int $spriteCursorX = 0;
    protected int $spriteCursorY = 0;
    protected int $spriteViewportOffsetX = 0;
    protected int $spriteViewportOffsetY = 0;
    protected array $spriteOriginalGrid = [];
    protected array $spriteUndoStack = [];
    protected array $spriteRedoStack = [];
    protected OptionListModal $createSpriteAssetModal;
    protected OptionListModal $deleteSpriteAssetModal;
    protected OptionListModal $characterPickerModal;
    protected ?string $spriteModalState = null;
    protected ?array $pendingAssetSyncRequest = null;
    protected ?string $lastPrintedSpriteCharacter = null;
    protected ?string $lastClickedScenePath = null;
    protected float $lastClickedSceneAt = 0.0;
    protected bool $isSpriteMousePainting = false;
    protected bool $isSpriteMouseErasing = false;
    protected ?array $lastSpritePaintedCell = null;

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
        $this->createSpriteAssetModal = new OptionListModal(title: 'Create Asset');
        $this->deleteSpriteAssetModal = new OptionListModal(title: 'Delete Asset');
        $this->characterPickerModal = new OptionListModal(title: 'Insert Character');
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

    public function focus(FocusTargetContext $context): void
    {
        parent::focus($context);
        $this->refreshContent();
    }

    public function blur(FocusTargetContext $context): void
    {
        $this->isSpriteMousePainting = false;
        $this->isSpriteMouseErasing = false;
        $this->lastSpritePaintedCell = null;
        $this->lastClickedScenePath = null;
        $this->lastClickedSceneAt = 0.0;
        parent::blur($context);
        $this->refreshContent();
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

    public function consumeAssetSyncRequest(): ?array
    {
        $pendingAssetSyncRequest = $this->pendingAssetSyncRequest;
        $this->pendingAssetSyncRequest = null;

        return $pendingAssetSyncRequest;
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

    public function hasActiveModal(): bool
    {
        return $this->createSpriteAssetModal->isVisible()
            || $this->deleteSpriteAssetModal->isVisible()
            || $this->characterPickerModal->isVisible();
    }

    public function isModalDirty(): bool
    {
        return $this->createSpriteAssetModal->isDirty()
            || $this->deleteSpriteAssetModal->isDirty()
            || $this->characterPickerModal->isDirty();
    }

    public function markModalClean(): void
    {
        $this->createSpriteAssetModal->markClean();
        $this->deleteSpriteAssetModal->markClean();
        $this->characterPickerModal->markClean();
    }

    public function syncModalLayout(int $terminalWidth, int $terminalHeight): void
    {
        $this->createSpriteAssetModal->syncLayout($terminalWidth, $terminalHeight);
        $this->deleteSpriteAssetModal->syncLayout($terminalWidth, $terminalHeight);
        $this->characterPickerModal->syncLayout($terminalWidth, $terminalHeight);
    }

    public function renderActiveModal(): void
    {
        if ($this->createSpriteAssetModal->isVisible()) {
            $this->createSpriteAssetModal->render();
        }

        if ($this->deleteSpriteAssetModal->isVisible()) {
            $this->deleteSpriteAssetModal->render();
        }

        if ($this->characterPickerModal->isVisible()) {
            $this->characterPickerModal->render();
        }
    }

    public function handleModalMouseEvent(MouseEvent $mouseEvent): bool
    {
        if ($mouseEvent->buttonIndex !== 0 || $mouseEvent->action !== 'Pressed') {
            return false;
        }

        $activeModal = match ($this->spriteModalState) {
            self::SPRITE_MODAL_CREATE => $this->createSpriteAssetModal,
            self::SPRITE_MODAL_DELETE => $this->deleteSpriteAssetModal,
            self::SPRITE_MODAL_CHARACTER => $this->characterPickerModal,
            default => null,
        };

        if (!$activeModal instanceof OptionListModal) {
            return false;
        }

        $selection = $activeModal->clickOptionAtPoint($mouseEvent->x, $mouseEvent->y);

        if (!is_string($selection) || $selection === '') {
            return false;
        }

        $this->handleSpriteModalSelection($selection);

        return true;
    }

    public function loadSpriteAsset(?array $asset): void
    {
        if (!$this->isEditableSpriteAsset($asset)) {
            $this->activeSpriteAsset = null;
            $this->spriteGrid = [];
            $this->spriteGridWidth = 0;
            $this->spriteGridHeight = 0;
            $this->spriteCursorX = 0;
            $this->spriteCursorY = 0;
            $this->spriteViewportOffsetX = 0;
            $this->spriteViewportOffsetY = 0;
            $this->spriteOriginalGrid = [];
            $this->spriteUndoStack = [];
            $this->spriteRedoStack = [];
            $this->lastPrintedSpriteCharacter = null;
            $this->refreshContent();
            return;
        }

        $absolutePath = $asset['path'];
        $grid = $this->loadSpriteGridFromFile(
            $absolutePath,
            strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION)),
        );
        $this->activeSpriteAsset = [
            'name' => $asset['name'] ?? basename($absolutePath),
            'path' => $absolutePath,
            'relativePath' => $asset['relativePath'] ?? basename($absolutePath),
            'extension' => strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION)),
        ];
        $this->spriteGrid = $grid['rows'];
        $this->spriteGridWidth = $grid['width'];
        $this->spriteGridHeight = $grid['height'];
        $this->spriteCursorX = 0;
        $this->spriteCursorY = 0;
        $this->spriteViewportOffsetX = 0;
        $this->spriteViewportOffsetY = 0;
        $this->spriteOriginalGrid = $this->copySpriteGrid($this->spriteGrid);
        $this->spriteUndoStack = [];
        $this->spriteRedoStack = [];
        $this->lastPrintedSpriteCharacter = null;
        $this->refreshContent();
    }

    public function beginSpriteCreateWorkflow(): bool
    {
        if (!$this->isSpriteTabActive() || $this->isPlayModeActive || $this->hasActiveModal()) {
            return false;
        }

        $this->showCreateSpriteAssetModal();

        return true;
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

        if ($this->hasFocus() && $this->isSpriteTabActive() && !$this->isPlayModeActive) {
            if ($this->hasActiveModal()) {
                $this->handleSpriteModalInput();
                return;
            }

            if (Input::getCurrentInput() === '@') {
                $this->showCharacterPickerModal();
                return;
            }

            if (Input::isKeyDown(KeyCode::DELETE)) {
                $this->showDeleteSpriteAssetModal();
                return;
            }

            if (Input::isKeyDown(KeyCode::CTRL_Z)) {
                $this->undoSpriteEdit();
                return;
            }

            if (Input::isKeyDown(KeyCode::CTRL_Y)) {
                $this->redoSpriteEdit();
                return;
            }

            if (Input::getCurrentInput() === 'R') {
                $this->resetSpriteEdits();
                return;
            }

            if ($this->handleSpriteEditorInput()) {
                return;
            }
        }

        $this->refreshContent();
    }

    public function handleMouseClick(int $x, int $y): void
    {
        if (!$this->containsPoint($x, $y)) {
            return;
        }

        if ($y === $this->getContentAreaTop()) {
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

            return;
        }

        if ($this->isSceneTabActive() && !$this->isPlayModeActive) {
            $this->handleSceneCanvasClick($x, $y);
            return;
        }

        if ($this->isSpriteTabActive() && !$this->isPlayModeActive) {
            $this->handleSpriteCanvasPress($x, $y);
        }
    }

    public function handleMouseDrag(int $x, int $y): void
    {
        if (!$this->containsPoint($x, $y)) {
            return;
        }

        if ($this->isSpriteTabActive() && !$this->isPlayModeActive) {
            $this->handleSpriteCanvasDrag($x, $y);
        }
    }

    public function handleMouseRelease(int $x, int $y): void
    {
        $this->isSpriteMousePainting = false;
        $this->isSpriteMouseErasing = false;
        $this->lastSpritePaintedCell = null;
    }

    protected function decorateContentLine(string $line, ?Color $contentColor, int $lineIndex): string
    {
        $contentIndex = $lineIndex - $this->padding->topPadding;

        if ($lineIndex !== 1) {
            if (isset($this->spriteLineHighlights[$contentIndex])) {
                return $this->decorateSpriteLine($line, $contentColor, $contentIndex);
            }

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

    private function decorateSpriteLine(string $line, ?Color $contentColor, int $contentIndex): string
    {
        $highlight = $this->spriteLineHighlights[$contentIndex] ?? null;

        if (!is_array($highlight)) {
            return parent::decorateContentLine($line, $contentColor, $contentIndex);
        }

        $visibleLine = $this->clipContentToWidth($line, $this->width);
        $visibleCharacterLength = mb_strlen($visibleLine);

        if ($visibleCharacterLength <= 1) {
            return parent::decorateContentLine($line, $contentColor, $contentIndex);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleCharacterLength > 2 ? mb_substr($visibleLine, 1, $visibleCharacterLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);
        $borderColor = $this->hasFocus() ? $this->focusBorderColor : $contentColor;
        $middleWidth = $this->getDisplayWidth($middle);
        $highlightStart = min(
            max(0, $this->padding->leftPadding + (int) ($highlight['start'] ?? 0)),
            $middleWidth,
        );
        $highlightLength = max(
            0,
            min((int) ($highlight['length'] ?? 0), $middleWidth - $highlightStart),
        );

        if ($highlightLength === 0) {
            return parent::decorateContentLine($line, $contentColor, $contentIndex);
        }

        [
            'before' => $beforeHighlight,
            'highlight' => $highlightText,
            'after' => $afterHighlight,
        ] = $this->splitContentByDisplayWidth($middle, $highlightStart, $highlightLength);
        $highlightSequence = $this->hasFocus()
            ? self::SPRITE_CURSOR_FOCUSED_SEQUENCE
            : self::SPRITE_CURSOR_SEQUENCE;

        return $this->wrapWithColor($leftBorder, $borderColor)
            . $this->wrapWithColor($beforeHighlight, $contentColor)
            . $this->wrapWithSequence($highlightText, $highlightSequence)
            . $this->wrapWithColor($afterHighlight, $contentColor)
            . $this->wrapWithColor($rightBorder, $borderColor);
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
        $this->sceneClickTargets = [];
        $this->spriteLineHighlights = [];
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
        } elseif ($this->isSpriteTabActive()) {
            $content = [...$content, ...$this->buildSpriteEditorContent()];
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

        if ($this->isSpriteTabActive()) {
            if ($this->activeSpriteAsset === null) {
                $this->help = 'Select .texture or .tmap';
                $this->modeHelpLabel = 'Mode: Sprite Editor';
                return;
            }

            $this->help = 'Arrows move  Type draw  Shift+2 chars  Ctrl+Z/Y undo redo  Shift+R reset  Del delete';
            $this->modeHelpLabel = 'Mode: Sprite Editor  ' . $this->buildSpriteCursorPositionLabel();
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

    private function buildSpriteCursorPositionLabel(): string
    {
        return 'Col x Row: ' . ($this->spriteCursorX + 1) . ' x ' . ($this->spriteCursorY + 1);
    }

    private function shouldRenderIdleGameView(): bool
    {
        return $this->getActiveTab() === 'Game' && !$this->isPlayModeActive;
    }

    private function isSceneTabActive(): bool
    {
        return $this->getActiveTab() === self::SCENE_TAB_TITLE;
    }

    private function isSpriteTabActive(): bool
    {
        return $this->getActiveTab() === 'Sprite';
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

        if (Input::isKeyPressed(KeyCode::UP)) {
            $this->moveSelectedSceneObject(0, -1);
            return true;
        }

        if (Input::isKeyPressed(KeyCode::RIGHT)) {
            $this->moveSelectedSceneObject(1, 0);
            return true;
        }

        if (Input::isKeyPressed(KeyCode::DOWN)) {
            $this->moveSelectedSceneObject(0, 1);
            return true;
        }

        if (Input::isKeyPressed(KeyCode::LEFT)) {
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

    private function handleSpriteEditorInput(): bool
    {
        if ($this->activeSpriteAsset === null) {
            return false;
        }

        if (Input::isKeyDown(KeyCode::UP)) {
            $this->moveSpriteCursor(0, -1);
            return true;
        }

        if (Input::isKeyDown(KeyCode::RIGHT)) {
            $this->moveSpriteCursor(1, 0);
            return true;
        }

        if (Input::isKeyDown(KeyCode::DOWN)) {
            $this->moveSpriteCursor(0, 1);
            return true;
        }

        if (Input::isKeyDown(KeyCode::LEFT)) {
            $this->moveSpriteCursor(-1, 0);
            return true;
        }

        if (Input::isKeyDown(KeyCode::ENTER)) {
            if ($this->lastPrintedSpriteCharacter === null) {
                return false;
            }

            $this->writeSpriteCharacter($this->lastPrintedSpriteCharacter);
            return true;
        }

        if (Input::isKeyDown(KeyCode::BACKSPACE)) {
            $this->writeSpriteCharacter(' ');
            return true;
        }

        if (Input::isKeyDown(KeyCode::SPACE)) {
            $this->writeSpriteCharacter(' ');
            return true;
        }

        $currentInput = Input::getCurrentInput();

        if ($this->isPrintableSpriteCharacter($currentInput)) {
            $this->writeSpriteCharacter($currentInput);
            return true;
        }

        return false;
    }

    private function showCreateSpriteAssetModal(): void
    {
        $this->createSpriteAssetModal->show(['Texture', 'Tile Map', 'Cancel'], 0, 'Create Asset');
        $this->deleteSpriteAssetModal->hide();
        $this->spriteModalState = self::SPRITE_MODAL_CREATE;
    }

    private function showDeleteSpriteAssetModal(): void
    {
        if ($this->activeSpriteAsset === null) {
            return;
        }

        $this->deleteSpriteAssetModal->show(
            ['Delete', 'Cancel'],
            1,
            'Delete ' . ($this->activeSpriteAsset['name'] ?? 'asset') . '?'
        );
        $this->createSpriteAssetModal->hide();
        $this->characterPickerModal->hide();
        $this->spriteModalState = self::SPRITE_MODAL_DELETE;
    }

    private function showCharacterPickerModal(): void
    {
        if ($this->activeSpriteAsset === null) {
            return;
        }

        $this->characterPickerModal->show(
            self::SPECIAL_CHARACTER_OPTIONS,
            $this->resolveCharacterPickerSelectedIndex(),
            'Insert Character'
        );
        $this->createSpriteAssetModal->hide();
        $this->deleteSpriteAssetModal->hide();
        $this->spriteModalState = self::SPRITE_MODAL_CHARACTER;
    }

    private function dismissSpriteModals(): void
    {
        $this->createSpriteAssetModal->hide();
        $this->deleteSpriteAssetModal->hide();
        $this->characterPickerModal->hide();
        $this->spriteModalState = null;
    }

    private function handleSpriteModalInput(): void
    {
        if (Input::isKeyDown(KeyCode::ESCAPE)) {
            $this->dismissSpriteModals();
            return;
        }

        $activeModal = match ($this->spriteModalState) {
            self::SPRITE_MODAL_CREATE => $this->createSpriteAssetModal,
            self::SPRITE_MODAL_DELETE => $this->deleteSpriteAssetModal,
            self::SPRITE_MODAL_CHARACTER => $this->characterPickerModal,
            default => null,
        };

        if (!$activeModal instanceof OptionListModal) {
            $this->dismissSpriteModals();
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

        $selection = $activeModal->getSelectedOption();
        $this->handleSpriteModalSelection($selection);
    }

    private function handleSpriteModalSelection(?string $selection): void
    {
        if ($selection === null || $selection === 'Cancel') {
            $this->dismissSpriteModals();
            return;
        }

        if ($this->spriteModalState === self::SPRITE_MODAL_CREATE) {
            $this->createSpriteAsset($selection);
            $this->dismissSpriteModals();
            return;
        }

        if ($this->spriteModalState === self::SPRITE_MODAL_DELETE && $selection === 'Delete') {
            $this->deleteActiveSpriteAsset();
        }

        if ($this->spriteModalState === self::SPRITE_MODAL_CHARACTER) {
            $character = $this->resolveCharacterPickerSelection($selection);

            if ($character !== null) {
                $this->writeSpriteCharacter($character);
            }
        }

        $this->dismissSpriteModals();
    }

    private function resolveCharacterPickerSelection(?string $selection): ?string
    {
        if (!is_string($selection) || $selection === '') {
            return null;
        }

        return mb_substr($selection, 0, 1) ?: null;
    }

    private function resolveCharacterPickerSelectedIndex(): int
    {
        if ($this->lastPrintedSpriteCharacter === null) {
            return 0;
        }

        foreach (self::SPECIAL_CHARACTER_OPTIONS as $index => $option) {
            if (mb_substr($option, 0, 1) === $this->lastPrintedSpriteCharacter) {
                return $index;
            }
        }

        return 0;
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

        if (!$this->hasFocus() && ($highlight['kind'] ?? null) !== 'placeholder') {
            return parent::decorateContentLine($line, $contentColor, $contentIndex);
        }

        $visibleLine = $this->clipContentToWidth($line, $this->width);
        $visibleCharacterLength = mb_strlen($visibleLine);

        if ($visibleCharacterLength <= 1) {
            return parent::decorateContentLine($line, $contentColor, $contentIndex);
        }

        $leftBorder = mb_substr($visibleLine, 0, 1);
        $middle = $visibleCharacterLength > 2 ? mb_substr($visibleLine, 1, $visibleCharacterLength - 2) : '';
        $rightBorder = mb_substr($visibleLine, -1);
        $borderColor = $this->hasFocus() ? $this->focusBorderColor : $contentColor;
        $middleWidth = $this->getDisplayWidth($middle);
        $highlightStart = min(
            max(0, $this->padding->leftPadding + (int) ($highlight['start'] ?? 0)),
            $middleWidth,
        );
        $highlightLength = max(
            0,
            min((int) ($highlight['length'] ?? 0), $middleWidth - $highlightStart),
        );

        if ($highlightLength === 0) {
            return parent::decorateContentLine($line, $contentColor, $contentIndex);
        }

        [
            'before' => $beforeHighlight,
            'highlight' => $highlightText,
            'after' => $afterHighlight,
        ] = $this->splitContentByDisplayWidth($middle, $highlightStart, $highlightLength);

        if (($highlight['kind'] ?? null) === 'placeholder') {
            return $this->wrapWithColor($leftBorder, $borderColor)
                . $this->wrapWithColor($beforeHighlight, $contentColor)
                . $this->wrapWithColor($highlightText, Color::DARK_GRAY)
                . $this->wrapWithColor($afterHighlight, $contentColor)
                . $this->wrapWithColor($rightBorder, $borderColor);
        }

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
        $this->sceneClickTargets = [];

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

            if ($renderLines === []) {
                if (($sceneObject['path'] ?? null) !== $this->selectedScenePath || !$this->hasFocus()) {
                    continue;
                }

                if ($row < 0 || $row >= $canvasHeight || $column < 0 || $column >= $canvasWidth) {
                    continue;
                }

                $canvas[$row][$column] = self::SCENE_PLACEHOLDER_CHARACTER;
                $this->sceneLineHighlights[2 + $row] = [
                    'start' => $column,
                    'length' => 1,
                    'kind' => 'placeholder',
                ];
                continue;
            }

            foreach ($renderLines as $lineOffset => $renderLine) {
                $targetRow = $row + $lineOffset;

                if ($targetRow < 0 || $targetRow >= $canvasHeight) {
                    continue;
                }

                $placement = $this->writeRenderLineToCanvas(
                    $canvas[$targetRow],
                    $renderLine,
                    $column,
                    $canvasWidth,
                );

                if (($placement['length'] ?? 0) <= 0) {
                    continue;
                }

                $this->sceneClickTargets[$targetRow] ??= [];
                $this->sceneClickTargets[$targetRow][] = [
                    'path' => $sceneObject['path'] ?? null,
                    'start' => $placement['start'] ?? max(0, $column),
                    'length' => $placement['length'],
                ];

                if (($sceneObject['path'] ?? null) !== $this->selectedScenePath) {
                    continue;
                }

                $this->sceneLineHighlights[2 + $targetRow] = [
                    'start' => $placement['start'] ?? max(0, $column),
                    'length' => $placement['length'],
                ];
            }
        }

        return array_map(
            static fn(array $lineCharacters): string => implode('', $lineCharacters),
            $canvas,
        );
    }

    private function buildSpriteEditorContent(): array
    {
        $contentWidth = max(0, $this->innerWidth - $this->padding->leftPadding - $this->padding->rightPadding);
        $contentHeight = max(0, $this->innerHeight - 2);

        if ($contentWidth <= 0 || $contentHeight <= 0) {
            return [];
        }

        if ($this->activeSpriteAsset === null) {
            return [
                'Sprite editor',
                'Select a .texture or .tmap asset in Assets to edit it here.',
            ];
        }

        $visibleGridHeight = $contentHeight;
        $rows = [];

        for ($row = 0; $row < $visibleGridHeight; $row++) {
            $gridRowIndex = $this->spriteViewportOffsetY + $row;

            if ($gridRowIndex >= $this->spriteGridHeight) {
                $rows[] = '';
                continue;
            }

            $rowCharacters = $this->spriteGrid[$gridRowIndex] ?? [];
            $line = '';

            for ($column = 0; $column < $contentWidth; $column++) {
                $gridColumnIndex = $this->spriteViewportOffsetX + $column;
                $line .= $rowCharacters[$gridColumnIndex] ?? ' ';
            }

            if (
                $gridRowIndex === $this->spriteCursorY
                && $this->spriteCursorX >= $this->spriteViewportOffsetX
                && $this->spriteCursorX < $this->spriteViewportOffsetX + $contentWidth
            ) {
                $this->spriteLineHighlights[2 + $row] = [
                    'start' => $this->spriteCursorX - $this->spriteViewportOffsetX,
                    'length' => 1,
                ];
            }

            $rows[] = $line;
        }

        return $rows;
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
            $flattenedObjects[] = [
                'path' => $path,
                'item' => $item,
                'position' => $this->normalizeVector($item['position'] ?? null),
                'renderLines' => $renderLines,
            ];

            if (is_array($item['children'] ?? null) && $item['children'] !== []) {
                $flattenedObjects = [
                    ...$flattenedObjects,
                    ...$this->flattenSceneObjects($item['children'], $path),
                ];
            }
        }

        return $flattenedObjects;
    }

    private function isEditableSpriteAsset(?array $asset): bool
    {
        if (!is_array($asset) || ($asset['isDirectory'] ?? false) || !is_string($asset['path'] ?? null)) {
            return false;
        }

        $extension = strtolower((string) pathinfo((string) $asset['path'], PATHINFO_EXTENSION));

        return in_array($extension, ['texture', 'tmap'], true);
    }

    private function loadSpriteGridFromFile(string $absolutePath, string $extension): array
    {
        $contents = file_get_contents($absolutePath);
        [$defaultWidth, $defaultHeight] = $this->resolveDefaultSpriteDimensions($extension);

        if ($contents === false) {
            return $this->createBlankSpriteGrid($defaultWidth, $defaultHeight);
        }

        $normalizedContents = str_replace(["\r\n", "\r"], "\n", $contents);
        $lines = explode("\n", rtrim($normalizedContents, "\n"));

        if ($lines === [''] || $lines === []) {
            return $this->createBlankSpriteGrid($defaultWidth, $defaultHeight);
        }

        $width = max(1, ...array_map(static fn(string $line): int => mb_strlen($line), $lines));
        $rows = [];

        foreach ($lines as $line) {
            $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
            $characters = is_array($characters) ? $characters : [];
            $rows[] = array_pad($characters, $width, ' ');
        }

        $grid = [
            'rows' => $rows,
            'width' => $width,
            'height' => count($rows),
        ];

        if ($extension === 'texture') {
            return $this->expandSpriteGrid($grid, self::DEFAULT_TEXTURE_WIDTH, self::DEFAULT_TEXTURE_HEIGHT);
        }

        if ($extension === 'tmap') {
            return $this->expandSpriteGrid($grid, $defaultWidth, $defaultHeight);
        }

        return $grid;
    }

    private function createBlankSpriteGrid(int $width, int $height): array
    {
        $rows = [];

        for ($row = 0; $row < $height; $row++) {
            $rows[] = array_fill(0, $width, ' ');
        }

        return [
            'rows' => $rows,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function moveSpriteCursor(int $deltaX, int $deltaY): void
    {
        if ($this->activeSpriteAsset === null || $this->spriteGridWidth <= 0 || $this->spriteGridHeight <= 0) {
            return;
        }

        $this->setSpriteCursorPosition($this->spriteCursorX + $deltaX, $this->spriteCursorY + $deltaY);
        $this->refreshContent();
    }

    private function setSpriteCursorPosition(int $x, int $y): void
    {
        if ($this->activeSpriteAsset === null || $this->spriteGridWidth <= 0 || $this->spriteGridHeight <= 0) {
            return;
        }

        $this->spriteCursorX = max(0, min($x, $this->spriteGridWidth - 1));
        $this->spriteCursorY = max(0, min($y, $this->spriteGridHeight - 1));
        $this->syncSpriteViewport();
    }

    private function syncSpriteViewport(): void
    {
        $contentWidth = max(1, $this->innerWidth - $this->padding->leftPadding - $this->padding->rightPadding);
        $visibleGridHeight = max(1, $this->innerHeight - 2);
        $maxOffsetX = max(0, $this->spriteGridWidth - $contentWidth);
        $maxOffsetY = max(0, $this->spriteGridHeight - $visibleGridHeight);

        if ($this->spriteCursorX < $this->spriteViewportOffsetX) {
            $this->spriteViewportOffsetX = $this->spriteCursorX;
        } elseif ($this->spriteCursorX >= $this->spriteViewportOffsetX + $contentWidth) {
            $this->spriteViewportOffsetX = $this->spriteCursorX - $contentWidth + 1;
        }

        if ($this->spriteCursorY < $this->spriteViewportOffsetY) {
            $this->spriteViewportOffsetY = $this->spriteCursorY;
        } elseif ($this->spriteCursorY >= $this->spriteViewportOffsetY + $visibleGridHeight) {
            $this->spriteViewportOffsetY = $this->spriteCursorY - $visibleGridHeight + 1;
        }

        $this->spriteViewportOffsetX = max(0, min($this->spriteViewportOffsetX, $maxOffsetX));
        $this->spriteViewportOffsetY = max(0, min($this->spriteViewportOffsetY, $maxOffsetY));
    }

    private function isPrintableSpriteCharacter(string $input): bool
    {
        return mb_strlen($input) === 1 && !preg_match('/[\x00-\x1F\x7F]/', $input);
    }

    private function writeSpriteCharacter(string $character): void
    {
        $this->writeSpriteCharacterAtCursor($character);
    }

    private function writeSpriteCharacterAtCursor(
        string $character,
        bool $advanceCursor = true,
        bool $rememberCharacter = true,
    ): void
    {
        if ($this->activeSpriteAsset === null) {
            return;
        }

        $nextCharacter = mb_substr($character, 0, 1);

        if ($nextCharacter === '') {
            return;
        }

        if ($rememberCharacter) {
            $this->lastPrintedSpriteCharacter = $nextCharacter;
        }

        if (($this->spriteGrid[$this->spriteCursorY][$this->spriteCursorX] ?? ' ') === $nextCharacter) {
            $this->refreshContent();
            return;
        }

        $this->pushSpriteUndoSnapshot();
        $this->spriteGrid[$this->spriteCursorY][$this->spriteCursorX] = $nextCharacter;
        $this->persistActiveSpriteAsset();

        if ($advanceCursor && $this->spriteCursorX < $this->spriteGridWidth - 1) {
            $this->spriteCursorX++;
        }

        $this->syncSpriteViewport();
        $this->refreshContent();
    }

    private function undoSpriteEdit(): void
    {
        if ($this->activeSpriteAsset === null || $this->spriteUndoStack === []) {
            return;
        }

        $this->spriteRedoStack[] = $this->copySpriteGrid($this->spriteGrid);
        $this->spriteGrid = array_pop($this->spriteUndoStack);
        $this->persistActiveSpriteAsset();
        $this->syncSpriteViewport();
        $this->refreshContent();
    }

    private function redoSpriteEdit(): void
    {
        if ($this->activeSpriteAsset === null || $this->spriteRedoStack === []) {
            return;
        }

        $this->spriteUndoStack[] = $this->copySpriteGrid($this->spriteGrid);
        $this->spriteGrid = array_pop($this->spriteRedoStack);
        $this->persistActiveSpriteAsset();
        $this->syncSpriteViewport();
        $this->refreshContent();
    }

    private function resetSpriteEdits(): void
    {
        if ($this->activeSpriteAsset === null || $this->spriteOriginalGrid === []) {
            return;
        }

        if ($this->spriteGrid === $this->spriteOriginalGrid) {
            return;
        }

        $this->pushSpriteUndoSnapshot();
        $this->spriteGrid = $this->copySpriteGrid($this->spriteOriginalGrid);
        $this->persistActiveSpriteAsset();
        $this->syncSpriteViewport();
        $this->refreshContent();
    }

    private function pushSpriteUndoSnapshot(): void
    {
        $this->spriteUndoStack[] = $this->copySpriteGrid($this->spriteGrid);
        $this->spriteRedoStack = [];
    }

    private function copySpriteGrid(array $grid): array
    {
        return array_map(
            static fn(array $row): array => array_values($row),
            $grid,
        );
    }

    private function resolveDefaultSpriteDimensions(string $extension): array
    {
        if ($extension === 'tmap') {
            $terminalSize = get_max_terminal_size();

            return [
                max(1, (int) ($terminalSize['width'] ?? DEFAULT_TERMINAL_WIDTH)),
                max(1, (int) ($terminalSize['height'] ?? DEFAULT_TERMINAL_HEIGHT)),
            ];
        }

        return [self::DEFAULT_TEXTURE_WIDTH, self::DEFAULT_TEXTURE_HEIGHT];
    }

    private function expandSpriteGrid(array $grid, int $targetWidth, int $targetHeight): array
    {
        $expandedWidth = max($targetWidth, (int) ($grid['width'] ?? 0));
        $expandedHeight = max($targetHeight, (int) ($grid['height'] ?? 0));
        $rows = array_map(
            static fn(array $row): array => array_pad(array_values($row), $expandedWidth, ' '),
            is_array($grid['rows'] ?? null) ? $grid['rows'] : [],
        );

        while (count($rows) < $expandedHeight) {
            $rows[] = array_fill(0, $expandedWidth, ' ');
        }

        return [
            'rows' => $rows,
            'width' => $expandedWidth,
            'height' => $expandedHeight,
        ];
    }

    private function persistActiveSpriteAsset(): void
    {
        if ($this->activeSpriteAsset === null || !is_string($this->activeSpriteAsset['path'] ?? null)) {
            return;
        }

        $lines = array_map(
            static fn(array $row): string => implode('', $row),
            $this->spriteGrid
        );
        file_put_contents($this->activeSpriteAsset['path'], implode("\n", $lines) . "\n");
    }

    private function createSpriteAsset(string $selection): void
    {
        $assetsDirectory = $this->resolveAssetsDirectory();

        if ($assetsDirectory === null) {
            return;
        }

        $isTileMap = $selection === 'Tile Map';
        $targetDirectory = Path::join($assetsDirectory, $isTileMap ? 'Maps' : 'Textures');

        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0777, true);
        }

        $baseName = $isTileMap ? 'new-map' : 'new-texture';
        $extension = $isTileMap ? 'tmap' : 'texture';
        $absolutePath = $this->createNextAvailableAssetPath($targetDirectory, $baseName, $extension);
        [$defaultWidth, $defaultHeight] = $this->resolveDefaultSpriteDimensions($extension);
        $grid = $this->createBlankSpriteGrid($defaultWidth, $defaultHeight);
        $this->spriteGrid = $grid['rows'];
        $this->spriteGridWidth = $grid['width'];
        $this->spriteGridHeight = $grid['height'];
        $this->spriteCursorX = 0;
        $this->spriteCursorY = 0;
        $this->spriteViewportOffsetX = 0;
        $this->spriteViewportOffsetY = 0;
        $this->spriteOriginalGrid = $this->copySpriteGrid($this->spriteGrid);
        $this->spriteUndoStack = [];
        $this->spriteRedoStack = [];
        $this->lastPrintedSpriteCharacter = null;
        $this->activeSpriteAsset = [
            'name' => basename($absolutePath),
            'path' => $absolutePath,
            'relativePath' => $this->buildAssetRelativePath($absolutePath),
            'extension' => $extension,
        ];
        $this->persistActiveSpriteAsset();
        $this->pendingAssetSyncRequest = [
            'path' => $absolutePath,
            'inspectionTarget' => [
                'context' => 'asset',
                'name' => basename($absolutePath),
                'type' => 'File',
                'value' => [
                    'name' => basename($absolutePath),
                    'path' => $absolutePath,
                    'relativePath' => $this->buildAssetRelativePath($absolutePath),
                    'isDirectory' => false,
                ],
            ],
        ];
        $this->refreshContent();
    }

    private function deleteActiveSpriteAsset(): void
    {
        if ($this->activeSpriteAsset === null || !is_string($this->activeSpriteAsset['path'] ?? null)) {
            return;
        }

        $deletedPath = $this->activeSpriteAsset['path'];

        if (!is_file($deletedPath) || !unlink($deletedPath)) {
            return;
        }

        $this->activeSpriteAsset = null;
        $this->spriteGrid = [];
        $this->spriteGridWidth = 0;
        $this->spriteGridHeight = 0;
        $this->spriteCursorX = 0;
        $this->spriteCursorY = 0;
        $this->spriteViewportOffsetX = 0;
        $this->spriteViewportOffsetY = 0;
        $this->spriteOriginalGrid = [];
        $this->spriteUndoStack = [];
        $this->spriteRedoStack = [];
        $this->lastPrintedSpriteCharacter = null;
        $this->pendingAssetSyncRequest = [
            'path' => $deletedPath,
            'clearInspection' => true,
        ];
        $this->refreshContent();
    }

    private function resolveAssetsDirectory(): ?string
    {
        $assetsDirectory = Path::resolveAssetsDirectory($this->projectDirectory);

        return is_dir($assetsDirectory) ? $assetsDirectory : null;
    }

    private function createNextAvailableAssetPath(string $targetDirectory, string $baseName, string $extension): string
    {
        $index = 1;

        do {
            $candidatePath = Path::join($targetDirectory, $baseName . '-' . $index . '.' . $extension);
            $index++;
        } while (file_exists($candidatePath));

        return $candidatePath;
    }

    private function buildAssetRelativePath(string $absolutePath): string
    {
        $assetsDirectory = $this->resolveAssetsDirectory();

        if ($assetsDirectory === null) {
            return basename($absolutePath);
        }

        $relativePath = substr($absolutePath, strlen($assetsDirectory));

        return ltrim((string) $relativePath, DIRECTORY_SEPARATOR);
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

    private function handleSceneCanvasClick(int $x, int $y): void
    {
        $canvasRow = $y - $this->getContentAreaTop() - 2;

        if ($canvasRow < 0) {
            return;
        }

        $canvasColumn = $x - $this->getContentAreaLeft();

        if ($canvasColumn < 0) {
            return;
        }

        $clickedPath = $this->resolveSceneClickPath($canvasRow, $canvasColumn);

        if (!is_string($clickedPath) || $clickedPath === '') {
            return;
        }

        $isDoubleClick = $this->registerSceneClickAndCheckDoubleClick($clickedPath);
        $this->selectedScenePath = $clickedPath;
        $this->queueInspectionForSelectedSceneObject();
        $this->refreshContent();

        if ($isDoubleClick) {
            $this->activateSceneSelection();
        }
    }

    private function registerSceneClickAndCheckDoubleClick(string $path): bool
    {
        $now = microtime(true);
        $isDoubleClick = $this->lastClickedScenePath === $path
            && ($now - $this->lastClickedSceneAt) <= self::DOUBLE_CLICK_THRESHOLD_SECONDS;

        $this->lastClickedScenePath = $path;
        $this->lastClickedSceneAt = $now;

        return $isDoubleClick;
    }

    private function handleSpriteCanvasPress(int $x, int $y): void
    {
        $mouseEvent = Input::getMouseEvent();
        $buttonIndex = $mouseEvent?->buttonIndex;
        $this->lastSpritePaintedCell = null;
        $this->isSpriteMouseErasing = $buttonIndex === 2;
        $this->isSpriteMousePainting = $this->isSpriteMouseErasing || $this->lastPrintedSpriteCharacter !== null;
        $this->applySpriteMouseInteraction($x, $y, $this->isSpriteMousePainting, $this->isSpriteMouseErasing);
    }

    private function handleSpriteCanvasDrag(int $x, int $y): void
    {
        if (!$this->isSpriteMousePainting && !$this->isSpriteMouseErasing) {
            return;
        }

        $this->applySpriteMouseInteraction($x, $y, true, $this->isSpriteMouseErasing);
    }

    private function applySpriteMouseInteraction(int $x, int $y, bool $shouldPaint, bool $shouldErase = false): void
    {
        $gridPosition = $this->resolveSpriteGridPositionFromPoint($x, $y);

        if (!is_array($gridPosition)) {
            return;
        }

        if ($shouldPaint && $this->lastSpritePaintedCell === $gridPosition) {
            return;
        }

        $this->setSpriteCursorPosition($gridPosition['x'], $gridPosition['y']);

        if (!$shouldPaint) {
            $this->refreshContent();
            return;
        }

        $character = $shouldErase ? ' ' : $this->lastPrintedSpriteCharacter;

        if ($character === null) {
            $this->refreshContent();
            return;
        }

        $this->lastSpritePaintedCell = $gridPosition;
        $this->writeSpriteCharacterAtCursor($character, false, !$shouldErase);
    }

    private function resolveSpriteGridPositionFromPoint(int $x, int $y): ?array
    {
        if ($this->activeSpriteAsset === null) {
            return null;
        }

        $gridRow = $y - $this->getContentAreaTop() - 2;
        $gridColumn = $x - $this->getContentAreaLeft();

        if ($gridRow < 0 || $gridColumn < 0) {
            return null;
        }

        $gridX = $this->spriteViewportOffsetX + $gridColumn;
        $gridY = $this->spriteViewportOffsetY + $gridRow;

        if ($gridX < 0 || $gridY < 0 || $gridX >= $this->spriteGridWidth || $gridY >= $this->spriteGridHeight) {
            return null;
        }

        return ['x' => $gridX, 'y' => $gridY];
    }

    private function resolveSceneClickPath(int $row, int $column): ?string
    {
        $rowTargets = $this->sceneClickTargets[$row] ?? null;

        if (!is_array($rowTargets) || $rowTargets === []) {
            return null;
        }

        for ($index = count($rowTargets) - 1; $index >= 0; $index--) {
            $target = $rowTargets[$index] ?? null;

            if (!is_array($target)) {
                continue;
            }

            $start = (int) ($target['start'] ?? -1);
            $length = (int) ($target['length'] ?? 0);

            if ($length <= 0 || $column < $start || $column >= ($start + $length)) {
                continue;
            }

            return is_string($target['path'] ?? null) ? $target['path'] : null;
        }

        return null;
    }

    private function resolveSceneObjectRenderLines(array $item): array
    {
        if ($this->isSceneObjectRendererDisabled($item)) {
            return [];
        }

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

    private function isSceneObjectRendererDisabled(array $item): bool
    {
        if (($item['renderer']['enabled'] ?? null) === false || ($item['render']['enabled'] ?? null) === false) {
            return true;
        }

        if (($item['sprite']['enabled'] ?? null) === false) {
            return true;
        }

        $components = $item['components'] ?? null;

        if (!is_array($components)) {
            return false;
        }

        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }

            $componentClass = is_string($component['class'] ?? null) ? $component['class'] : '';

            if ($componentClass === '') {
                continue;
            }

            $normalizedClass = ltrim($componentClass, '\\');
            $normalizedClass = preg_replace('/::class$/', '', $normalizedClass) ?? $normalizedClass;

            if ($normalizedClass !== 'Sendama\\Engine\\Core\\Rendering\\Renderer' && !str_ends_with($normalizedClass, '\\Renderer')) {
                continue;
            }

            if (($component['data']['enabled'] ?? null) === false || ($component['enabled'] ?? null) === false) {
                return true;
            }
        }

        return false;
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

            $this->writeRenderLineToCanvas(
                $canvas[$targetRow],
                $tileMapLine,
                -$this->sceneViewportOffsetX,
                $canvasWidth,
            );
        }
    }

    private function writeRenderLineToCanvas(
        array &$targetRow,
        string $renderLine,
        int $startColumn,
        int $canvasWidth,
    ): array {
        $characters = preg_split('//u', $renderLine, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($characters) || $characters === []) {
            return ['start' => max(0, $startColumn), 'length' => 0];
        }

        $column = $startColumn;
        $firstVisibleColumn = null;
        $lastVisibleColumn = null;

        foreach ($characters as $character) {
            $characterWidth = max(1, $this->getDisplayWidth($character));

            if (($column + $characterWidth) <= 0) {
                $column += $characterWidth;
                continue;
            }

            if ($column >= $canvasWidth) {
                break;
            }

            $visibleColumn = max(0, $column);
            $remainingWidth = $canvasWidth - $visibleColumn;

            if ($remainingWidth <= 0) {
                break;
            }

            if ($column >= 0 && $characterWidth > $remainingWidth) {
                break;
            }

            $occupyWidth = min($characterWidth, $remainingWidth);
            $targetRow[$visibleColumn] = $character;

            for ($paddingColumn = 1; $paddingColumn < $occupyWidth; $paddingColumn++) {
                if (!array_key_exists($visibleColumn + $paddingColumn, $targetRow)) {
                    break;
                }

                $targetRow[$visibleColumn + $paddingColumn] = '';
            }

            $firstVisibleColumn ??= $visibleColumn;
            $lastVisibleColumn = $visibleColumn + $occupyWidth - 1;
            $column += $characterWidth;
        }

        if ($firstVisibleColumn === null || $lastVisibleColumn === null) {
            return ['start' => max(0, $startColumn), 'length' => 0];
        }

        return [
            'start' => $firstVisibleColumn,
            'length' => max(0, $lastVisibleColumn - $firstVisibleColumn + 1),
        ];
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

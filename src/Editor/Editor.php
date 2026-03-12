<?php

namespace Sendama\Console\Editor;

use Assegai\Collections\ItemList;
use Atatusoft\Termutil\Events\Interfaces\ObservableInterface;
use Atatusoft\Termutil\Events\Traits\ObservableTrait;
use Atatusoft\Termutil\IO\Console\Console;
use Atatusoft\Termutil\UI\Windows\Window;
use Sendama\Console\Debug\Debug;
use Sendama\Console\Editor\Enumerations\ChronoUnit;
use Sendama\Console\Editor\Events\EditorEvent;
use Sendama\Console\Editor\Events\Enumerations\EventType;
use Sendama\Console\Editor\Interfaces\EditorStateInterface;
use Sendama\Console\Editor\IO\Input;
use Sendama\Console\Editor\IO\InputManager;
use Sendama\Console\Editor\States\EditorState;
use Sendama\Console\Editor\States\EditorStateContext;
use Sendama\Console\Editor\States\EditState;
use Sendama\Console\Editor\States\ModalState;
use Sendama\Console\Editor\States\PlayState;
use Sendama\Console\Editor\States\ProjectBrowserState;
use Sendama\Console\Editor\Widgets\AssetsPanel;
use Sendama\Console\Editor\Widgets\ConsolePanel;
use Sendama\Console\Editor\Widgets\HierarchyPanel;
use Sendama\Console\Editor\Widgets\InspectorPanel;
use Sendama\Console\Editor\Widgets\MainPanel;
use Sendama\Console\Editor\Widgets\PanelListModal;
use Sendama\Console\Editor\Widgets\Widget;
use Sendama\Console\Exceptions\IOException;
use Sendama\Console\Exceptions\SendamaConsoleException;
use Sendama\Console\Util\Path;
use Symfony\Component\Console\Output\ConsoleOutput;
use Throwable;

/**
 * The Sendama Editor application.
 *
 * @package Sendama\Console\Editor
 */
final class Editor implements ObservableInterface
{
    use ObservableTrait;

    const int FPS = 60;
    /**
     * @var bool Whether the editor is currently running.
     */
    protected bool $isRunning = false;
    /**
     * @var bool Whether the editor is currently stopped. This is the opposite of isRunning.
     */
    protected bool $isStopped {
        get {
            return !$this->isRunning;
        }
    }
    /**
     * @var EditorState|null The current state of the editor. This is used to determine what the editor should do when it is running.
     */
    protected ?EditorStateInterface $editorState = null;
    /**
     * @var int The number of frames that have been rendered.
     */
    private int $frameCount = 0;
    /**
     * @var int The frame rate of the game.
     */
    protected int $frameRate = 0;
    /**
     * @var bool Specifies whether debug mode is enabled or not.
     */
    protected bool $isDebugMode {
        get {
            return $this->gameSettings?->isDebugMode;
        }
    }
    /**
     * @var bool Determines whether the debug info panel is visible or hidden.
     */
    private bool $showDebugInfo = false;
    /**
     * @var Window
     */
    private Window $debugWindow;
    /**
     * @var EditorSettings The editor settings
     */
    protected EditorSettings $settings;
    /**
     * @var GameSettings|null The game settings.
     */
    protected ?GameSettings $gameSettings = null;
    /**
     * @var ProjectBrowserState
     */
    protected ProjectBrowserState $projectBrowserState;
    /**
     * @var EditState
     */
    protected EditState $editState;
    /**
     * @var PlayState
     */
    protected PlayState $playState;
    /**
     * @var ModalState
     */
    protected ModalState $modalState;
    /**
     * @var SplashScreen
     */
    protected SplashScreen $splashScreen;

    /** Panels */
    /**
     * @var ItemList<Widget>
     */
    protected ItemList $panels;
    protected HierarchyPanel $hierarchyPanel;
    protected AssetsPanel $assetsPanel;
    protected MainPanel $mainPanel;
    protected ConsolePanel $consolePanel;
    protected InspectorPanel $inspectorPanel;
    protected ?Widget $focusedPanel = null;
    protected ?DTOs\SceneDTO $loadedScene = null;
    protected ?string $assetsDirectoryPath = null;
    protected int $terminalWidth = DEFAULT_TERMINAL_WIDTH;
    protected int $terminalHeight = DEFAULT_TERMINAL_HEIGHT;
    protected PanelListModal $panelListModal;
    protected bool $shouldRefreshBackgroundUnderModal = false;
    protected bool $didRenderOverlayLastFrame = false;
    protected SceneWriter $sceneWriter;

    /**
     * @param string $name
     * @param string $workingDirectory
     */
    public function __construct(
        public string $name = 'Sendama Editor',
        protected string $workingDirectory = '.'
    )
    {
        try {
            register_shutdown_function(function () {
                $this->finish();
            });

            $this->initializeObservers();
            $this->configureErrorAndExceptionHandlers();
            $this->initializeSettings();
            $this->initializeLoadedScene();
            $this->refreshTerminalSize(force: true);
            $this->initializeManagers();
            $this->initializeConsole();
            $this->sceneWriter = new SceneWriter();
            $this->initializeWidgets();
            $this->initializeEditorStates();
            $this->splashScreen = new SplashScreen(
                Console::cursor(),
                new ConsoleOutput(),
                $this->gameSettings
            );
        } catch (Throwable $exception) {
            $this->handleException($exception);
        }
    }

    /**
     * Called when this editor construct is destroyed.
     */
    public function __destruct()
    {
        $this->finish();
    }

    /**
     * Sets the working directory for the editor. If the editor is currently running, it will be
     * stopped and restarted after setting the working directory.
     *
     * @param string $directory The working directory to set for the editor.
     * @return $this
     * @throws IOException
     */
    public function setWorkingDirectory(string $directory): self
    {
        $restartAfterSettingWorkingDirectory = $this->isRunning;

        if ($this->isRunning) {
            $this->stop();
        }

        $this->workingDirectory = $directory;

        if ($restartAfterSettingWorkingDirectory) {
            $this->start();
        }

        return $this;
    }

    /**
     * Starts the editor.
     *
     * @return void
     * @throws IOException
     */
    public function start(): void
    {
        Debug::info("Starting editor");

        Console::saveSettings();

        Console::setName($this->gameSettings?->name ?? "Sendama Editor | Unknown Game");

        Console::setSize($this->terminalWidth, $this->terminalHeight);

        Console::cursor()->hide();

        Console::enableMouseReporting();

        InputManager::disableEcho();

        InputManager::enableNonBlockingMode();

        $this->splashScreen->show();

        $this->addObservers(Time::class);

        $this->isRunning = true;

        $this->notify(new EditorEvent(EventType::EDITOR_STARTED->value, $this));

        Debug::info("Editor started");
    }

    /**
     * @return void
     * @throws IOException
     */
    public function stop(): void
    {
        Console::reset();

        Debug::info("Stopping editor");

        InputManager::disableNonBlockingMode();

        InputManager::enableEcho();

        Console::disableMouseReporting();

        Console::cursor()->show();

        $this->isRunning = false;

        $this->notify(new EditorEvent(EventType::EDITOR_STOPPED->value, $this));

        $this->removeObservers(...$this->observers, ...$this->staticObservers);

        Debug::info("Editor stopped");
    }

    /**
     * @return void
     */
    public function finish(): void
    {
        Debug::info("Shutting down editor");

        Console::restoreSettings();

        if ($lastError = error_get_last()) {
            $this->handleError($lastError["type"], $lastError["message"], $lastError["file"], $lastError["line"]);
        }

        $this->notify(new EditorEvent(EventType::EDITOR_FINISHED->value, $this));

        Debug::info("Editor shutdown complete");
    }

    /**
     * @return void
     * @throws IOException
     */
    public function run(): void
    {
        $sleepTime =(int)(1000000 / self::FPS);
        $this->start();
        $nextFrameTime = microtime(true) + 1;
        $lastFrameCountSnapShot = $this->frameCount;

        Debug::info("Running editor");
        while ($this->isRunning) {
            $this->handleInput();
            $this->update();

            if ($this->isStopped) {
                break;
            }

            $this->render();

            usleep($sleepTime);

            if (microtime(true) >= $nextFrameTime) {
                $this->frameRate = $this->frameCount - $lastFrameCountSnapShot;
                $lastFrameCountSnapShot = $this->frameCount;
                $nextFrameTime = microtime(true) + 1;
            }
        }
    }

    public function setState(EditorStateInterface $editorState): void
    {
        $context = new EditorStateContext(
            $this->settings,
            $this->gameSettings,
            [
                'hierarchy' => $this->hierarchyPanel,
                'assets' => $this->assetsPanel,
                'main' => $this->mainPanel,
                'console' => $this->consolePanel,
                'inspector' => $this->inspectorPanel,
            ]
        );

        $this->editorState?->exit($context);
        $this->editorState = $editorState;
        $this->editorState->enter($context);
        $this->syncPlayModeState();

        if ($editorState instanceof PlayState) {
            $this->mainPanel->selectTab('Game');
            $this->setFocusedPanel($this->mainPanel);
        }
    }

    /**
     * Handle editor input
     *
     * @return void
     */
    private function handleInput(): void
    {
        InputManager::handleInput();
        $this->handlePanelFocus();

        $this->notify(new EditorEvent(EventType::EDITOR_INPUT_HANDLED->value, $this));
    }

    /**
     * Update the editor state.
     *
     * @return void
     */
    private function update(): void
    {
        if ($this->frameCount % 10 === 0) {
            $this->refreshTerminalSize();
        }

        $this->editorState->update();
        $this->handlePanelKeyboardWorkflow();

        if ($this->panelListModal->isVisible()) {
            $this->notify(new EditorEvent(EventType::EDITOR_UPDATED->value, $this));
            return;
        }

        $this->syncPlayModeState();

        foreach ($this->panels as $panel) {
            $panel->update();
        }

        $this->synchronizeAssetDeletions();
        $this->synchronizeHierarchyDeletions();
        $this->synchronizeHierarchyAdditions();
        $this->synchronizeMainPanelSceneChanges();
        $this->synchronizeMainPanelAssetChanges();
        $this->synchronizeInspectorSceneChanges();
        $this->synchronizeInspectorAssetChanges();
        $this->synchronizeInspectorPanel();

        $this->notify(new EditorEvent(EventType::EDITOR_UPDATED->value, $this));
    }

    private function render(): void
    {
        $this->frameCount++;
        if ($this->panelListModal->isVisible()) {
            $this->didRenderOverlayLastFrame = true;

            if ($this->shouldRefreshBackgroundUnderModal) {
                $this->renderEditorFrame();
            }

            if ($this->shouldRefreshBackgroundUnderModal || $this->panelListModal->isDirty()) {
                $this->panelListModal->render();
                $this->panelListModal->markClean();
                $this->shouldRefreshBackgroundUnderModal = false;
            }

            $this->notify(new EditorEvent(EventType::EDITOR_RENDERED->value, $this));
            return;
        }

        if ($this->focusedPanel?->hasActiveModal()) {
            $this->didRenderOverlayLastFrame = true;
            $this->focusedPanel->syncModalLayout($this->terminalWidth, $this->terminalHeight);

            if ($this->shouldRefreshBackgroundUnderModal || $this->focusedPanel->isModalDirty()) {
                $this->renderEditorFrame();
                $this->focusedPanel->renderActiveModal();
                $this->focusedPanel->markModalClean();
                $this->shouldRefreshBackgroundUnderModal = false;
            }

            $this->notify(new EditorEvent(EventType::EDITOR_RENDERED->value, $this));
            return;
        }

        if ($this->didRenderOverlayLastFrame) {
            Console::clear();
            $this->didRenderOverlayLastFrame = false;
        }

        $this->shouldRefreshBackgroundUnderModal = false;
        $this->renderEditorFrame();

        $this->notify(new EditorEvent(EventType::EDITOR_RENDERED->value, $this));
    }

    private function renderEditorFrame(): void
    {
        $this->editorState->render();

        foreach ($this->panels as $panel) {
            $panel->render();
        }

        $this->renderDebugInfo();
    }

    private function renderDebugInfo(): void
    {
        if ($this->isDebugMode && $this->showDebugInfo) {
            $content = ["FPS: $this->frameRate, Delta: " . round(Time::getDeltaTime() / 2), "Time: " . Time::getPrettyTime(ChronoUnit::SECONDS)];

            $this->debugWindow->content = $content;
            $this->debugWindow->render();
        }
    }

    /**
     * @return void
     */
    protected function configureErrorAndExceptionHandlers(): void {
        error_reporting(E_ALL);

        set_exception_handler(function (Throwable $throwable) {
            $this->handleException($throwable);
        });

        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            $this->handleError($errno, $errstr, $errfile, $errline);
        });

        $this->debugWindow = new Window();
    }

    /**
     * Handles thrown Editor exceptions.
     *
     * @param Throwable $exception
     * @return never
     */
    private function handleException(Throwable $exception): never
    {
        Debug::error($exception);

        if ($this->gameSettings?->isDebugMode) {
            exit($exception);
        }

        exit("$exception\n");
    }

    /**
     * Handles game errors.
     *
     * @param int $errno
     * @param string $errstr
     * @param string $errfile
     * @param int $errline
     * @return never
     */
    private function handleError(int $errno, string $errstr, string $errfile, int $errline): never
    {
        $errorMessage = "[$errno] $errstr in $errfile on line $errline";
        Debug::error($errorMessage);

        if ($this->gameSettings?->isDebugMode) {
            exit($errorMessage);
        }

        exit($errno);
    }

    protected function initializeConsole(): void
    {
        Console::init([
            "width" => $this->terminalWidth,
            "height" => $this->terminalHeight,
        ]);
    }

    private function initializeEditorStates(): void
    {
        $this->projectBrowserState = new ProjectBrowserState($this);
        $this->editState = new EditState($this);
        $this->playState = new PlayState($this);
        $this->modalState = new ModalState($this);

        $this->setState($this->editState);
    }

    private function togglePlayMode(): void
    {
        if ($this->editorState instanceof PlayState) {
            $this->setState($this->editState);
        } else {
            $this->setState($this->playState);
        }

        $this->shouldRefreshBackgroundUnderModal = true;
    }

    private function syncPlayModeState(): void
    {
        $isPlayModeActive = $this->editorState instanceof PlayState;

        if (isset($this->consolePanel)) {
            $this->consolePanel->setPlayModeActive($isPlayModeActive);
        }

        if (isset($this->mainPanel)) {
            $this->mainPanel->setPlayModeActive($isPlayModeActive);
        }
    }

    /**
     * @return void
     * @throws SendamaConsoleException
     */
    private function initializeSettings(): void
    {
        $this->settings = EditorSettings::loadFromDirectory($this->workingDirectory);
        $this->gameSettings = GameSettings::loadFromDirectory($this->workingDirectory);
    }

    private function initializeLoadedScene(): void
    {
        $sceneLoader = new SceneLoader($this->workingDirectory);
        $this->assetsDirectoryPath = $sceneLoader->resolveAssetsDirectory();
        $this->loadedScene = $sceneLoader->load($this->settings->scenes);
    }

    /**
     * @return void
     */
    private function initializeManagers(): void
    {
        InputManager::init();
    }

    /**
     * @return void
     */
    private function initializeWidgets(): void
    {
        $this->panels = new ItemList(Widget::class);
        $this->panelListModal = new PanelListModal();
        $this->hierarchyPanel = new HierarchyPanel(
            sceneName: $this->loadedScene?->name ?? 'Scene',
            isSceneDirty: $this->loadedScene?->isDirty ?? false,
            hierarchy: $this->loadedScene?->hierarchy ?? [],
            sceneWidth: $this->loadedScene?->width ?? DEFAULT_TERMINAL_WIDTH,
            sceneHeight: $this->loadedScene?->height ?? DEFAULT_TERMINAL_HEIGHT,
            environmentTileMapPath: $this->loadedScene?->environmentTileMapPath ?? 'Maps/example',
        );
        $this->assetsPanel = new AssetsPanel(
            assetsDirectoryPath: $this->assetsDirectoryPath,
        );
        $this->mainPanel = new MainPanel(
            sceneObjects: $this->loadedScene?->hierarchy ?? [],
            workingDirectory: $this->workingDirectory,
            sceneWidth: $this->loadedScene?->width ?? DEFAULT_TERMINAL_WIDTH,
            sceneHeight: $this->loadedScene?->height ?? DEFAULT_TERMINAL_HEIGHT,
            environmentTileMapPath: $this->loadedScene?->environmentTileMapPath ?? 'Maps/example',
        );
        $this->consolePanel = new ConsolePanel(
            logFilePath: Path::join($this->workingDirectory, 'logs', 'debug.log'),
            errorLogFilePath: Path::join($this->workingDirectory, 'logs', 'error.log'),
            refreshIntervalSeconds: $this->settings->consoleRefreshIntervalSeconds,
        );
        $this->inspectorPanel = new InspectorPanel(
            workingDirectory: $this->workingDirectory,
        );

        $this->panels->add($this->hierarchyPanel);
        $this->panels->add($this->assetsPanel);
        $this->panels->add($this->mainPanel);
        $this->panels->add($this->consolePanel);
        $this->panels->add($this->inspectorPanel);

        $this->layoutPanels();
        $this->configurePanelGraph();
        $this->setFocusedPanel($this->mainPanel);
    }

    private function handlePanelFocus(): void
    {
        if (!Input::isLeftMouseButtonDown()) {
            return;
        }

        $mouseEvent = Input::getMouseEvent();

        if (!$mouseEvent) {
            return;
        }

        foreach ($this->panels as $panel) {
            if ($panel->containsPoint($mouseEvent->x, $mouseEvent->y)) {
                $this->setFocusedPanel($panel);
                $panel->handleMouseClick($mouseEvent->x, $mouseEvent->y);
                return;
            }
        }
    }

    private function setFocusedPanel(Widget $panel): void
    {
        if ($this->focusedPanel === $panel) {
            return;
        }

        $context = $this->createFocusTargetContext();

        $this->focusedPanel?->blur($context);
        $this->focusedPanel = $panel;
        $this->focusedPanel->focus($context);
    }

    private function createFocusTargetContext(): FocusTargetContext
    {
        return new FocusTargetContext(
            $this,
            $this->gameSettings ?? new GameSettings(name: 'Untitled Game')
        );
    }

    private function handlePanelKeyboardWorkflow(): void
    {
        if (Input::isKeyDown(IO\Enumerations\KeyCode::CTRL_C)) {
            $this->stop();
            return;
        }

        if (Input::isKeyDown(IO\Enumerations\KeyCode::CTRL_S)) {
            $this->saveLoadedScene();
            return;
        }

        if (Input::isKeyDown(IO\Enumerations\KeyCode::PLAY_TOGGLE, false)) {
            $this->togglePlayMode();
            return;
        }

        if ($this->panelListModal->isVisible()) {
            $this->handlePanelListModalInput();
            return;
        }

        if (Input::getCurrentInput() === '!') {
            $this->showPanelListModal();
            return;
        }

        if ($this->focusedPanel?->hasActiveModal()) {
            return;
        }

        if (Input::getCurrentInput() === 'A' && !($this->editorState instanceof PlayState)) {
            if ($this->focusedPanel === $this->mainPanel && $this->mainPanel->beginSpriteCreateWorkflow()) {
                $this->shouldRefreshBackgroundUnderModal = true;
                return;
            }

            $this->setFocusedPanel($this->hierarchyPanel);
            $this->hierarchyPanel->beginAddWorkflow();
            $this->shouldRefreshBackgroundUnderModal = true;
            return;
        }

        if (Input::isKeyDown(IO\Enumerations\KeyCode::SHIFT_UP)) {
            $this->focusSiblingPanel('top');
            return;
        }

        if (Input::isKeyDown(IO\Enumerations\KeyCode::SHIFT_RIGHT)) {
            $this->focusSiblingPanel('right');
            return;
        }

        if (Input::isKeyDown(IO\Enumerations\KeyCode::SHIFT_DOWN)) {
            $this->focusSiblingPanel('bottom');
            return;
        }

        if (Input::isKeyDown(IO\Enumerations\KeyCode::SHIFT_LEFT)) {
            $this->focusSiblingPanel('left');
            return;
        }

        if (Input::isKeyDown(IO\Enumerations\KeyCode::TAB)) {
            $this->focusedPanel?->cycleFocusForward();
            return;
        }

        if (Input::isKeyDown(IO\Enumerations\KeyCode::SHIFT_TAB)) {
            $this->focusedPanel?->cycleFocusBackward();
        }
    }

    private function refreshTerminalSize(bool $force = false): void
    {
        $terminalSize = get_max_terminal_size();
        $newWidth = $terminalSize['width'] ?? DEFAULT_TERMINAL_WIDTH;
        $newHeight = $terminalSize['height'] ?? DEFAULT_TERMINAL_HEIGHT;

        if (!$force && $newWidth === $this->terminalWidth && $newHeight === $this->terminalHeight) {
            return;
        }

        $this->terminalWidth = $newWidth;
        $this->terminalHeight = $newHeight;

        if (!isset($this->panels)) {
            return;
        }

        Console::init([
            'width' => $this->terminalWidth,
            'height' => $this->terminalHeight,
        ]);

        $this->layoutPanels();

        if ($this->panelListModal->isVisible() || $this->focusedPanel?->hasActiveModal()) {
            $this->shouldRefreshBackgroundUnderModal = true;
        }
    }

    private function layoutPanels(): void
    {
        $leftPanelWidth = min(35, max(12, intdiv(max($this->terminalWidth - 2, 1), 4)));
        $rightPanelWidth = $leftPanelWidth;
        $availableHeight = max(6, $this->terminalHeight - 1);
        $topLeftHeight = max(3, intdiv($availableHeight, 2));
        $bottomLeftHeight = $availableHeight - $topLeftHeight;
        $centralPanelX = $leftPanelWidth + 2;
        $centralPanelWidth = max(12, $this->terminalWidth - ($leftPanelWidth * 2) - 2);
        $consolePanelHeight = min(max(3, intdiv($availableHeight, 4)), $availableHeight - 3);
        $mainPanelHeight = $availableHeight - $consolePanelHeight;
        $inspectorPanelX = $centralPanelX + $centralPanelWidth + 1;

        $this->hierarchyPanel->setPosition(1, 1);
        $this->hierarchyPanel->setDimensions($leftPanelWidth, $topLeftHeight);

        $this->assetsPanel->setPosition(1, $topLeftHeight + 1);
        $this->assetsPanel->setDimensions($leftPanelWidth, $bottomLeftHeight);

        $this->mainPanel->setPosition($centralPanelX, 1);
        $this->mainPanel->setDimensions($centralPanelWidth, $mainPanelHeight);

        $this->consolePanel->setPosition($centralPanelX, $mainPanelHeight + 1);
        $this->consolePanel->setDimensions($centralPanelWidth, $consolePanelHeight);

        $this->inspectorPanel->setPosition($inspectorPanelX, 1);
        $this->inspectorPanel->setDimensions($rightPanelWidth, $availableHeight);

        $this->panelListModal->syncLayout($this->terminalWidth, $this->terminalHeight);
        $this->focusedPanel?->syncModalLayout($this->terminalWidth, $this->terminalHeight);
    }

    private function configurePanelGraph(): void
    {
        $this->hierarchyPanel->setSiblings(
            top: null,
            right: $this->mainPanel,
            bottom: $this->assetsPanel,
            left: null,
        );

        $this->assetsPanel->setSiblings(
            top: $this->hierarchyPanel,
            right: $this->consolePanel,
            bottom: null,
            left: null,
        );

        $this->consolePanel->setSiblings(
            top: $this->mainPanel,
            right: $this->inspectorPanel,
            bottom: null,
            left: $this->assetsPanel,
        );

        $this->mainPanel->setSiblings(
            top: null,
            right: $this->inspectorPanel,
            bottom: $this->consolePanel,
            left: $this->hierarchyPanel,
        );

        $this->inspectorPanel->setSiblings(
            top: null,
            right: null,
            bottom: null,
            left: $this->mainPanel,
        );
    }

    private function focusSiblingPanel(string $direction): void
    {
        $targetPanel = $this->focusedPanel?->getSibling($direction);

        if ($targetPanel instanceof Widget) {
            $this->setFocusedPanel($targetPanel);
        }
    }

    private function focusNextPanel(): void
    {
        $panels = $this->panels->toArray();
        $panelIndex = $this->getFocusedPanelIndex();
        $nextIndex = ($panelIndex + 1) % count($panels);

        /** @var Widget $panel */
        $panel = $panels[$nextIndex];
        $this->setFocusedPanel($panel);
    }

    private function focusPreviousPanel(): void
    {
        $panels = $this->panels->toArray();
        $panelIndex = $this->getFocusedPanelIndex();
        $previousIndex = ($panelIndex - 1 + count($panels)) % count($panels);

        /** @var Widget $panel */
        $panel = $panels[$previousIndex];
        $this->setFocusedPanel($panel);
    }

    private function getFocusedPanelIndex(): int
    {
        foreach ($this->panels as $index => $panel) {
            if ($panel === $this->focusedPanel) {
                return $index;
            }
        }

        return 0;
    }

    private function showPanelListModal(): void
    {
        $this->panelListModal->show(
            $this->getPanelDisplayNames(),
            $this->getFocusedPanelIndex()
        );
        $this->panelListModal->syncLayout($this->terminalWidth, $this->terminalHeight);
        $this->shouldRefreshBackgroundUnderModal = true;
    }

    private function handlePanelListModalInput(): void
    {
        if (Input::isKeyDown(IO\Enumerations\KeyCode::ESCAPE)) {
            $this->panelListModal->hide();
            return;
        }

        if (Input::isKeyDown(IO\Enumerations\KeyCode::UP)) {
            $this->panelListModal->moveSelection(-1);
            return;
        }

        if (Input::isKeyDown(IO\Enumerations\KeyCode::DOWN)) {
            $this->panelListModal->moveSelection(1);
            return;
        }

        if (Input::isKeyDown(IO\Enumerations\KeyCode::ENTER)) {
            $selectedIndex = $this->panelListModal->getSelectedIndex();
            $this->panelListModal->hide();
            $panels = $this->panels->toArray();

            if (!isset($panels[$selectedIndex])) {
                return;
            }

            /** @var Widget $panel */
            $panel = $panels[$selectedIndex];
            $this->setFocusedPanel($panel);
        }
    }

    private function synchronizeInspectorPanel(): void
    {
        $selectedItem = $this->hierarchyPanel->consumeInspectionRequest()
            ?? $this->assetsPanel->consumeInspectionRequest()
            ?? $this->mainPanel->consumeInspectionRequest();

        if ($selectedItem === null) {
            return;
        }

        if (($selectedItem['context'] ?? null) === 'hierarchy' && is_string($selectedItem['path'] ?? null)) {
            $this->hierarchyPanel->selectPath($selectedItem['path']);
            $this->mainPanel->selectSceneObject($selectedItem['path']);
        } elseif (($selectedItem['context'] ?? null) === 'scene') {
            $this->hierarchyPanel->selectPath('scene');
        } elseif (($selectedItem['context'] ?? null) === 'asset') {
            $this->mainPanel->loadSpriteAsset(is_array($selectedItem['value'] ?? null) ? $selectedItem['value'] : null);
        }

        $this->inspectorPanel->inspectTarget($selectedItem);
    }

    private function synchronizeInspectorSceneChanges(): void
    {
        $mutation = $this->inspectorPanel->consumeHierarchyMutation();

        if (
            !is_array($mutation)
            || !isset($mutation['path'], $mutation['value'])
            || !$this->loadedScene instanceof DTOs\SceneDTO
            || !is_string($mutation['path'])
            || !is_array($mutation['value'])
        ) {
            return;
        }

        if ($mutation['path'] === 'scene') {
            if (!$this->applySceneMutation($mutation['value'])) {
                return;
            }

            $this->loadedScene->isDirty = true;
            $this->syncScenePanels(true);
            $this->inspectorPanel->syncSceneTarget($this->buildSceneInspectionValue());
            return;
        }

        if (!$this->applyHierarchyMutation($mutation['path'], $mutation['value'])) {
            return;
        }

        $this->loadedScene->isDirty = true;
        $this->loadedScene->rawData['hierarchy'] = $this->loadedScene->hierarchy;
        $this->hierarchyPanel->syncHierarchy($this->loadedScene->hierarchy);
        $this->hierarchyPanel->selectPath($mutation['path']);
        $this->syncScenePanels(true);
        $this->mainPanel->setSceneObjects($this->loadedScene->hierarchy);
        $this->mainPanel->selectSceneObject($mutation['path']);
        $this->inspectorPanel->syncHierarchyTarget($mutation['path'], $mutation['value']);
    }

    private function synchronizeInspectorAssetChanges(): void
    {
        $mutation = $this->inspectorPanel->consumeAssetMutation();

        if (
            !is_array($mutation)
            || !is_string($mutation['path'] ?? null)
            || $mutation['path'] === ''
            || !is_string($mutation['name'] ?? null)
        ) {
            return;
        }

        $renamedAsset = $this->renameAssetAndCascadeReferences(
            $mutation['path'],
            $mutation['relativePath'] ?? null,
            $mutation['name'],
        );

        if ($renamedAsset === null) {
            if (is_file($mutation['path'])) {
                $this->inspectorPanel->syncAssetTarget([
                    'name' => basename($mutation['path']),
                    'path' => $mutation['path'],
                    'relativePath' => is_string($mutation['relativePath'] ?? null)
                        ? $mutation['relativePath']
                        : basename($mutation['path']),
                    'isDirectory' => false,
                    'children' => [],
                ]);
            }
            return;
        }

        $this->assetsPanel->reloadAssets();
        $this->assetsPanel->selectAssetByAbsolutePath($renamedAsset['path']);
        $assetInspectionTarget = $this->buildAssetInspectionTarget($renamedAsset);
        $this->inspectorPanel->inspectTarget($assetInspectionTarget);
        $this->mainPanel->loadSpriteAsset($renamedAsset);
    }

    private function synchronizeHierarchyAdditions(): void
    {
        $newItem = $this->hierarchyPanel->consumeCreationRequest();

        if (!$this->loadedScene instanceof DTOs\SceneDTO || !is_array($newItem) || $newItem === []) {
            return;
        }

        $this->loadedScene->hierarchy[] = $newItem;
        $this->loadedScene->isDirty = true;
        $this->loadedScene->rawData['hierarchy'] = $this->loadedScene->hierarchy;
        $this->hierarchyPanel->syncHierarchy($this->loadedScene->hierarchy);
        $newPath = 'scene.' . (count($this->loadedScene->hierarchy) - 1);
        $this->hierarchyPanel->selectPath($newPath);
        $this->syncScenePanels(true);
        $this->mainPanel->setSceneObjects($this->loadedScene->hierarchy);
        $this->mainPanel->selectSceneObject($newPath);
    }

    private function synchronizeHierarchyDeletions(): void
    {
        $deletionRequest = $this->hierarchyPanel->consumeDeletionRequest();

        if (
            !$this->loadedScene instanceof DTOs\SceneDTO
            || !is_array($deletionRequest)
            || !is_string($deletionRequest['path'] ?? null)
            || $deletionRequest['path'] === ''
        ) {
            return;
        }

        if (!$this->deleteHierarchyNode($deletionRequest['path'])) {
            return;
        }

        $this->loadedScene->isDirty = true;
        $this->loadedScene->rawData['hierarchy'] = $this->loadedScene->hierarchy;
        $this->hierarchyPanel->syncHierarchy($this->loadedScene->hierarchy);
        $this->syncScenePanels(true);
        $this->mainPanel->setSceneObjects($this->loadedScene->hierarchy);
        $this->inspectorPanel->inspectTarget(null);
    }

    private function synchronizeAssetDeletions(): void
    {
        $deletionRequest = $this->assetsPanel->consumeDeletionRequest();

        if (
            !is_array($deletionRequest)
            || !is_string($deletionRequest['assetPath'] ?? null)
            || $deletionRequest['assetPath'] === ''
        ) {
            return;
        }

        if (!$this->deleteAssetPath($deletionRequest['assetPath'])) {
            return;
        }

        $this->assetsPanel->reloadAssets();
        $this->inspectorPanel->inspectTarget(null);
    }

    private function synchronizeMainPanelSceneChanges(): void
    {
        $mutation = $this->mainPanel->consumeHierarchyMutation();

        if (
            !is_array($mutation)
            || !isset($mutation['path'], $mutation['value'])
            || !$this->loadedScene instanceof DTOs\SceneDTO
            || !is_string($mutation['path'])
            || !is_array($mutation['value'])
        ) {
            return;
        }

        if (!$this->applyHierarchyMutation($mutation['path'], $mutation['value'])) {
            return;
        }

        $this->loadedScene->isDirty = true;
        $this->loadedScene->rawData['hierarchy'] = $this->loadedScene->hierarchy;
        $this->hierarchyPanel->syncHierarchy($this->loadedScene->hierarchy);
        $this->hierarchyPanel->selectPath($mutation['path']);
        $this->syncScenePanels(true);
        $this->mainPanel->setSceneObjects($this->loadedScene->hierarchy);
        $this->mainPanel->selectSceneObject($mutation['path']);
        $this->inspectorPanel->syncHierarchyTarget($mutation['path'], $mutation['value']);
    }

    private function synchronizeMainPanelAssetChanges(): void
    {
        $assetSyncRequest = $this->mainPanel->consumeAssetSyncRequest();

        if (!is_array($assetSyncRequest)) {
            return;
        }

        $this->assetsPanel->reloadAssets();

        if (is_string($assetSyncRequest['path'] ?? null)) {
            $this->assetsPanel->selectAssetByAbsolutePath($assetSyncRequest['path']);
        }

        if (is_array($assetSyncRequest['inspectionTarget'] ?? null)) {
            $this->inspectorPanel->inspectTarget($assetSyncRequest['inspectionTarget']);
            return;
        }

        if (($assetSyncRequest['clearInspection'] ?? false) === true) {
            $this->inspectorPanel->inspectTarget(null);
        }
    }

    private function applyHierarchyMutation(string $path, array $value): bool
    {
        if (!$this->loadedScene instanceof DTOs\SceneDTO) {
            return false;
        }

        $segments = explode('.', $path);

        if (($segments[0] ?? null) !== 'scene') {
            return false;
        }

        array_shift($segments);

        if ($segments === []) {
            return false;
        }

        $hierarchy = $this->loadedScene->hierarchy;
        $nodeArray = &$hierarchy;
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
                $this->loadedScene->hierarchy = array_values($hierarchy);

                return true;
            }

            if (!isset($nodeArray[$numericSegment]['children']) || !is_array($nodeArray[$numericSegment]['children'])) {
                return false;
            }

            $nodeArray = &$nodeArray[$numericSegment]['children'];
        }

        return false;
    }

    private function deleteHierarchyNode(string $path): bool
    {
        if (!$this->loadedScene instanceof DTOs\SceneDTO) {
            return false;
        }

        $segments = explode('.', $path);

        if (($segments[0] ?? null) !== 'scene') {
            return false;
        }

        array_shift($segments);

        if ($segments === []) {
            return false;
        }

        $hierarchy = $this->loadedScene->hierarchy;
        $nodeArray = &$hierarchy;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if (!ctype_digit((string) $segment)) {
                return false;
            }

            $numericSegment = (int) $segment;

            if (!isset($nodeArray[$numericSegment])) {
                return false;
            }

            if ($index === $lastIndex) {
                unset($nodeArray[$numericSegment]);
                $nodeArray = array_values($nodeArray);
                $this->loadedScene->hierarchy = array_values($hierarchy);

                return true;
            }

            if (!isset($nodeArray[$numericSegment]['children']) || !is_array($nodeArray[$numericSegment]['children'])) {
                return false;
            }

            $nodeArray = &$nodeArray[$numericSegment]['children'];
        }

        return false;
    }

    private function deleteAssetPath(string $path): bool
    {
        if (is_file($path) || is_link($path)) {
            return unlink($path);
        }

        if (!is_dir($path)) {
            return false;
        }

        $entries = scandir($path);

        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = Path::join($path, $entry);

            if (!$this->deleteAssetPath($entryPath)) {
                return false;
            }
        }

        return rmdir($path);
    }

    private function renameAssetAndCascadeReferences(
        string $currentAbsolutePath,
        mixed $currentRelativePath,
        string $requestedName,
    ): ?array {
        if (!is_file($currentAbsolutePath)) {
            return null;
        }

        $normalizedName = $this->normalizeAssetFileName($requestedName, $currentAbsolutePath);

        if ($normalizedName === '') {
            return null;
        }

        $targetAbsolutePath = Path::join(dirname($currentAbsolutePath), $normalizedName);

        if ($targetAbsolutePath !== $currentAbsolutePath) {
            if (file_exists($targetAbsolutePath)) {
                $this->consolePanel->append('[ERROR] - Cannot rename asset: target file already exists.');
                return null;
            }

            if (!rename($currentAbsolutePath, $targetAbsolutePath)) {
                $this->consolePanel->append('[ERROR] - Failed to rename asset.');
                return null;
            }
        }

        $oldRelativePath = is_string($currentRelativePath) && $currentRelativePath !== ''
            ? str_replace('\\', '/', $currentRelativePath)
            : $this->buildRelativeAssetPath($currentAbsolutePath);
        $newRelativePath = $this->buildRelativeAssetPath($targetAbsolutePath);

        if ($this->updateSceneAssetReferences($oldRelativePath, $newRelativePath)) {
            if ($this->loadedScene instanceof DTOs\SceneDTO) {
                $this->loadedScene->rawData['hierarchy'] = $this->loadedScene->hierarchy;
                $this->loadedScene->rawData['environmentTileMapPath'] = $this->loadedScene->environmentTileMapPath;
                $this->loadedScene->isDirty = true;
                $this->hierarchyPanel->syncHierarchy($this->loadedScene->hierarchy);
                $this->mainPanel->setSceneObjects($this->loadedScene->hierarchy);
                $this->syncScenePanels(true);
            }
        }

        return [
            'name' => basename($targetAbsolutePath),
            'path' => $targetAbsolutePath,
            'relativePath' => $newRelativePath,
            'isDirectory' => false,
            'children' => [],
        ];
    }

    private function normalizeAssetFileName(string $requestedName, string $currentAbsolutePath): string
    {
        $trimmedName = trim(str_replace('\\', '/', $requestedName));
        $trimmedName = basename($trimmedName);
        $currentExtension = strtolower((string) pathinfo($currentAbsolutePath, PATHINFO_EXTENSION));

        if ($trimmedName === '') {
            return basename($currentAbsolutePath);
        }

        $requestedBaseName = (string) pathinfo($trimmedName, PATHINFO_FILENAME);

        if ($requestedBaseName === '') {
            $requestedBaseName = (string) pathinfo(basename($currentAbsolutePath), PATHINFO_FILENAME);
        }

        return $currentExtension !== ''
            ? $requestedBaseName . '.' . $currentExtension
            : $requestedBaseName;
    }

    private function buildRelativeAssetPath(string $absolutePath): string
    {
        $assetsDirectory = $this->assetsDirectoryPath;

        if (!is_string($assetsDirectory) || $assetsDirectory === '') {
            return basename($absolutePath);
        }

        $relativePath = substr($absolutePath, strlen($assetsDirectory));

        return ltrim(str_replace('\\', '/', (string) $relativePath), '/');
    }

    private function updateSceneAssetReferences(string $oldRelativePath, string $newRelativePath): bool
    {
        if (!$this->loadedScene instanceof DTOs\SceneDTO) {
            return false;
        }

        $hasChanges = false;
        $oldWithExtension = str_replace('\\', '/', $oldRelativePath);
        $newWithExtension = str_replace('\\', '/', $newRelativePath);
        $oldWithoutExtension = preg_replace('/\.[^.]+$/', '', $oldWithExtension) ?? $oldWithExtension;
        $newWithoutExtension = preg_replace('/\.[^.]+$/', '', $newWithExtension) ?? $newWithExtension;

        if ($this->loadedScene->environmentTileMapPath === $oldWithExtension) {
            $this->loadedScene->environmentTileMapPath = $newWithExtension;
            $hasChanges = true;
        } elseif ($this->loadedScene->environmentTileMapPath === $oldWithoutExtension) {
            $this->loadedScene->environmentTileMapPath = $newWithoutExtension;
            $hasChanges = true;
        }

        $this->loadedScene->hierarchy = $this->updateHierarchyAssetReferences(
            $this->loadedScene->hierarchy,
            $oldWithExtension,
            $oldWithoutExtension,
            $newWithExtension,
            $newWithoutExtension,
            $hasChanges,
        );

        return $hasChanges;
    }

    private function updateHierarchyAssetReferences(
        array $items,
        string $oldWithExtension,
        string $oldWithoutExtension,
        string $newWithExtension,
        string $newWithoutExtension,
        bool &$hasChanges,
    ): array {
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            if (is_string($item['sprite']['texture']['path'] ?? null)) {
                if ($item['sprite']['texture']['path'] === $oldWithExtension) {
                    $items[$index]['sprite']['texture']['path'] = $newWithExtension;
                    $hasChanges = true;
                } elseif ($item['sprite']['texture']['path'] === $oldWithoutExtension) {
                    $items[$index]['sprite']['texture']['path'] = $newWithoutExtension;
                    $hasChanges = true;
                }
            }

            if (is_array($item['children'] ?? null)) {
                $items[$index]['children'] = $this->updateHierarchyAssetReferences(
                    $item['children'],
                    $oldWithExtension,
                    $oldWithoutExtension,
                    $newWithExtension,
                    $newWithoutExtension,
                    $hasChanges,
                );
            }
        }

        return array_values($items);
    }

    private function buildAssetInspectionTarget(array $asset): array
    {
        return [
            'context' => 'asset',
            'name' => $asset['name'] ?? basename((string) ($asset['path'] ?? '')),
            'type' => ($asset['isDirectory'] ?? false) ? 'Folder' : 'File',
            'value' => $asset,
        ];
    }

    private function saveLoadedScene(): void
    {
        if (!$this->loadedScene instanceof DTOs\SceneDTO) {
            $this->consolePanel->append('[INFO] - No scene loaded to save.');
            return;
        }

        $sceneWasDirty = $this->loadedScene->isDirty;
        $this->loadedScene->isDirty = false;
        $originalSourcePath = $this->loadedScene->sourcePath;
        $targetSourcePath = $this->resolveTargetSceneSourcePath($this->loadedScene);

        $saveSucceeded = is_string($targetSourcePath)
            && is_string($originalSourcePath)
            && $targetSourcePath !== ''
            && $originalSourcePath !== ''
            && $targetSourcePath !== $originalSourcePath
            ? $this->saveRenamedScene($this->loadedScene, $targetSourcePath)
            : $this->sceneWriter->save($this->loadedScene);

        if ($saveSucceeded) {
            if (
                is_string($targetSourcePath)
                && $targetSourcePath !== ''
                && is_string($originalSourcePath)
                && $originalSourcePath !== ''
                && $targetSourcePath !== $originalSourcePath
            ) {
                $this->loadedScene->sourcePath = $targetSourcePath;
                $this->updateEditorSceneReference($originalSourcePath, $targetSourcePath);
            }

            $snapshot = $this->sceneWriter->snapshot($this->loadedScene);
            $this->loadedScene->rawData = $snapshot;
            $this->loadedScene->sourceData = $snapshot;
            $this->syncScenePanels(false);
            $this->consolePanel->append('[INFO] - Saved scene ' . $this->loadedScene->name . '.scene.php');
            return;
        }

        $this->loadedScene->isDirty = $sceneWasDirty;
        $this->syncScenePanels($sceneWasDirty);
        $this->consolePanel->append('[ERROR] - Failed to save scene.');
    }

    private function applySceneMutation(array $value): bool
    {
        if (!$this->loadedScene instanceof DTOs\SceneDTO) {
            return false;
        }

        if (is_string($value['name'] ?? null)) {
            $nextSceneName = $this->normalizeSceneName($value['name']);

            if ($nextSceneName !== '') {
                $this->loadedScene->name = $nextSceneName;
            }
        }

        if (isset($value['width']) && is_numeric($value['width'])) {
            $this->loadedScene->width = max(1, (int) round((float) $value['width']));
        }

        if (isset($value['height']) && is_numeric($value['height'])) {
            $this->loadedScene->height = max(1, (int) round((float) $value['height']));
        }

        if (is_string($value['environmentTileMapPath'] ?? null)) {
            $this->loadedScene->environmentTileMapPath = trim($value['environmentTileMapPath']) !== ''
                ? trim($value['environmentTileMapPath'])
                : 'Maps/example';
        }

        $this->loadedScene->rawData['width'] = $this->loadedScene->width;
        $this->loadedScene->rawData['height'] = $this->loadedScene->height;
        $this->loadedScene->rawData['environmentTileMapPath'] = $this->loadedScene->environmentTileMapPath;

        return true;
    }

    private function buildSceneInspectionValue(): array
    {
        if (!$this->loadedScene instanceof DTOs\SceneDTO) {
            return [];
        }

        return [
            'name' => $this->loadedScene->name,
            'width' => $this->loadedScene->width,
            'height' => $this->loadedScene->height,
            'environmentTileMapPath' => $this->loadedScene->environmentTileMapPath,
        ];
    }

    private function syncScenePanels(bool $isDirty): void
    {
        if (!$this->loadedScene instanceof DTOs\SceneDTO) {
            return;
        }

        $this->hierarchyPanel->setSceneState(
            $this->loadedScene->name,
            $isDirty,
            $this->loadedScene->width,
            $this->loadedScene->height,
            $this->loadedScene->environmentTileMapPath,
        );
        $this->mainPanel->setSceneDimensions($this->loadedScene->width, $this->loadedScene->height);
        $this->mainPanel->setEnvironmentTileMapPath($this->loadedScene->environmentTileMapPath);
    }

    private function resolveTargetSceneSourcePath(DTOs\SceneDTO $scene): ?string
    {
        if (!is_string($scene->sourcePath) || $scene->sourcePath === '') {
            return null;
        }

        $sceneDirectory = dirname($scene->sourcePath);
        $sceneName = $this->normalizeSceneName($scene->name);

        if ($sceneName === '') {
            return $scene->sourcePath;
        }

        return Path::join($sceneDirectory, $sceneName . '.scene.php');
    }

    private function normalizeSceneName(string $sceneName): string
    {
        $normalizedSceneName = trim($sceneName);
        $normalizedSceneName = preg_replace('/\.scene\.php$/', '', $normalizedSceneName) ?? $normalizedSceneName;
        $normalizedSceneName = preg_replace('#[\\\\/]#', '-', $normalizedSceneName) ?? $normalizedSceneName;

        return trim($normalizedSceneName);
    }

    private function saveRenamedScene(DTOs\SceneDTO $scene, string $targetSourcePath): bool
    {
        $serializedScene = $this->sceneWriter->serialize($scene);

        if (file_put_contents($targetSourcePath, $serializedScene) === false) {
            return false;
        }

        $originalSourcePath = $scene->sourcePath;

        if (
            is_string($originalSourcePath)
            && $originalSourcePath !== ''
            && $originalSourcePath !== $targetSourcePath
            && is_file($originalSourcePath)
            && !unlink($originalSourcePath)
        ) {
            $this->consolePanel->append('[WARN] - Saved renamed scene but could not remove the old scene file.');
        }

        return true;
    }

    private function updateEditorSceneReference(string $originalSourcePath, string $targetSourcePath): void
    {
        $settingsPath = Path::join($this->workingDirectory, 'sendama.json');

        if (!is_file($settingsPath)) {
            return;
        }

        $settingsContents = file_get_contents($settingsPath);

        if ($settingsContents === false) {
            return;
        }

        $settingsData = json_decode($settingsContents, true);

        if (!is_array($settingsData)) {
            return;
        }

        $activeSceneIndex = $this->settings->scenes->active;
        $configuredScenes = $settingsData['scenes']['loaded'] ?? [];
        $configuredSceneReference = $configuredScenes[$activeSceneIndex] ?? $configuredScenes[0] ?? null;
        $updatedSceneReference = $this->buildUpdatedSceneReference(
            is_string($configuredSceneReference) ? $configuredSceneReference : null,
            $originalSourcePath,
            $targetSourcePath,
        );

        $targetSceneIndex = array_key_exists($activeSceneIndex, $configuredScenes) ? $activeSceneIndex : 0;
        $settingsData['scenes']['loaded'][$targetSceneIndex] = $updatedSceneReference;

        if (file_put_contents(
            $settingsPath,
            json_encode($settingsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        ) === false) {
            return;
        }

        $this->settings->scenes->loaded[$targetSceneIndex] = $updatedSceneReference;
    }

    private function buildUpdatedSceneReference(
        ?string $configuredSceneReference,
        string $originalSourcePath,
        string $targetSourcePath,
    ): string {
        if (!is_string($configuredSceneReference) || trim($configuredSceneReference) === '') {
            return basename($targetSourcePath, '.scene.php');
        }

        if ($this->isAbsolutePath($configuredSceneReference)) {
            return $targetSourcePath;
        }

        $configuredSceneReference = str_replace('\\', '/', trim($configuredSceneReference));
        $hasExtension = str_ends_with($configuredSceneReference, '.scene.php');
        $directory = dirname($configuredSceneReference);
        $replacement = $hasExtension
            ? basename($targetSourcePath)
            : basename($targetSourcePath, '.scene.php');

        if ($directory === '.' || $directory === '') {
            return $replacement;
        }

        return rtrim($directory, '/') . '/' . $replacement;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    private function getPanelDisplayNames(): array
    {
        $names = [];

        foreach ($this->panels as $panel) {
            $names[] = $panel->getDisplayName();
        }

        return $names;
    }
}

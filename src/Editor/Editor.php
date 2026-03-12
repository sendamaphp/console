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

        $this->synchronizeInspectorSceneChanges();
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
        );
        $this->assetsPanel = new AssetsPanel(
            assetsDirectoryPath: $this->assetsDirectoryPath,
        );
        $this->mainPanel = new MainPanel();
        $this->consolePanel = new ConsolePanel(
            logFilePath: Path::join($this->workingDirectory, 'logs', 'debug.log'),
        );
        $this->inspectorPanel = new InspectorPanel();

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
            ?? $this->assetsPanel->consumeInspectionRequest();

        if ($selectedItem === null) {
            return;
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

        if (!$this->applyHierarchyMutation($mutation['path'], $mutation['value'])) {
            return;
        }

        $this->loadedScene->isDirty = true;
        $this->loadedScene->rawData['hierarchy'] = $this->loadedScene->hierarchy;
        $this->hierarchyPanel->syncHierarchy($this->loadedScene->hierarchy);
        $this->hierarchyPanel->setSceneState($this->loadedScene->name, true);
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

    private function saveLoadedScene(): void
    {
        if (!$this->loadedScene instanceof DTOs\SceneDTO) {
            $this->consolePanel->append('[INFO] - No scene loaded to save.');
            return;
        }

        $sceneWasDirty = $this->loadedScene->isDirty;
        $this->loadedScene->isDirty = false;

        if ($this->sceneWriter->save($this->loadedScene)) {
            $snapshot = $this->sceneWriter->snapshot($this->loadedScene);
            $this->loadedScene->rawData = $snapshot;
            $this->loadedScene->sourceData = $snapshot;
            $this->hierarchyPanel->setSceneState($this->loadedScene->name, false);
            $this->consolePanel->append('[INFO] - Saved scene ' . $this->loadedScene->name . '.scene.php');
            return;
        }

        $this->loadedScene->isDirty = $sceneWasDirty;
        $this->hierarchyPanel->setSceneState($this->loadedScene->name, $sceneWasDirty);
        $this->consolePanel->append('[ERROR] - Failed to save scene.');
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

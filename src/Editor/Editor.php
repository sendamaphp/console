<?php

namespace Sendama\Console\Editor;

use Assegai\Collections\ItemList;
use Atatusoft\Termutil\Events\MouseEvent;
use Atatusoft\Termutil\Events\Interfaces\ObservableInterface;
use Atatusoft\Termutil\Events\Traits\ObservableTrait;
use Atatusoft\Termutil\IO\Console\Console;
use Atatusoft\Termutil\UI\Windows\Window;
use Sendama\Console\Commands\GenerateEvent;
use Sendama\Console\Commands\GeneratePrefab;
use Sendama\Console\Commands\GenerateScene;
use Sendama\Console\Commands\GenerateScript;
use Sendama\Console\Commands\GenerateTexture;
use Sendama\Console\Commands\GenerateTilemap;
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
use Sendama\Console\Editor\Widgets\OptionListModal;
use Sendama\Console\Editor\Widgets\PanelListModal;
use Sendama\Console\Editor\Widgets\Snackbar;
use Sendama\Console\Editor\Widgets\Widget;
use Sendama\Console\Exceptions\IOException;
use Sendama\Console\Exceptions\SendamaConsoleException;
use Sendama\Console\Util\Path;
use Sendama\Console\Util\ProjectNormalizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
    private const float ASSET_WATCH_INTERVAL_SECONDS = 0.5;
    private const string TMUX_GAME_CHILD_ENV_KEY = 'SENDAMA_TMUX_CHILD';
    private const int TMUX_PLAY_PANE_PERCENT = 40;
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
    protected ?OptionListModal $projectNormalizationModal = null;
    protected bool $shouldRefreshBackgroundUnderModal = false;
    protected bool $didRenderOverlayLastFrame = false;
    protected SceneWriter $sceneWriter;
    protected PrefabWriter $prefabWriter;
    protected ?ProjectNormalizer $projectNormalizer = null;
    protected array $projectDiscrepancies = [];
    protected Snackbar $snackbar;
    protected ?string $tmuxPlayPaneId = null;
    protected ?string $tmuxPreviousStatusValue = null;
    protected array $watchedAssetSnapshot = [];
    protected float $lastAssetWatchPollAt = 0.0;

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
            $this->workingDirectory = $this->resolveAbsoluteDirectory($this->workingDirectory);

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
            $this->prefabWriter = new PrefabWriter();
            $this->initializeWidgets();
            $this->watchedAssetSnapshot = $this->captureWatchedAssetSnapshot();
            $this->lastAssetWatchPollAt = microtime(true);
            $this->initializeEditorStates();
            $this->initializeProjectIntegrityCheck();
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

        $this->workingDirectory = $this->resolveAbsoluteDirectory($directory);

        if ($restartAfterSettingWorkingDirectory) {
            $this->start();
        }

        return $this;
    }

    private function resolveAbsoluteDirectory(string $directory): string
    {
        $normalizedDirectory = Path::normalize(trim($directory));

        if ($normalizedDirectory === '' || $normalizedDirectory === '.') {
            $normalizedDirectory = getcwd() ?: '.';
        }

        if (!str_starts_with($normalizedDirectory, '/')) {
            $normalizedDirectory = Path::join(getcwd() ?: '.', $normalizedDirectory);
        }

        $resolvedDirectory = realpath($normalizedDirectory);

        if (is_string($resolvedDirectory) && $resolvedDirectory !== '') {
            return Path::normalize($resolvedDirectory);
        }

        return Path::normalize($normalizedDirectory);
    }

    private function resolveAbsolutePath(string $path, ?string $baseDirectory = null): string
    {
        $normalizedPath = Path::normalize(trim($path));

        if ($normalizedPath === '') {
            $resolvedBaseDirectory = is_string($baseDirectory) && $baseDirectory !== ''
                ? $this->resolveAbsoluteDirectory($baseDirectory)
                : $this->resolveAbsoluteDirectory('.');

            return $resolvedBaseDirectory;
        }

        if (str_starts_with($normalizedPath, '/')) {
            $resolvedPath = realpath($normalizedPath);

            return is_string($resolvedPath) && $resolvedPath !== ''
                ? Path::normalize($resolvedPath)
                : $normalizedPath;
        }

        $resolvedBaseDirectory = is_string($baseDirectory) && $baseDirectory !== ''
            ? $this->resolveAbsoluteDirectory($baseDirectory)
            : $this->resolveAbsoluteDirectory('.');
        $candidatePath = Path::join($resolvedBaseDirectory, $normalizedPath);
        $resolvedPath = realpath($candidatePath);

        return is_string($resolvedPath) && $resolvedPath !== ''
            ? Path::normalize($resolvedPath)
            : Path::normalize($candidatePath);
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

        $terminalTitle = "Sendama Editor | ";
        Console::setName($terminalTitle . ($this->gameSettings?->name ?? "Unknown Game"));

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
        $this->stopManagedTmuxPlayPane();
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
        $this->stopManagedTmuxPlayPane();
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

        $this->snackbar->update();

        if ($this->projectNormalizationModal?->isVisible()) {
            $this->handleProjectNormalizationModalInput();
            $this->notify(new EditorEvent(EventType::EDITOR_UPDATED->value, $this));
            return;
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
        $this->synchronizeAssetCreations();
        $this->synchronizeHierarchyDeletions();
        $this->synchronizeHierarchyAdditions();
        $this->synchronizeHierarchyMoves();
        $this->synchronizeHierarchyDuplications();
        $this->synchronizeHierarchyPrefabCreations();
        $this->synchronizeMainPanelSceneChanges();
        $this->synchronizeMainPanelAssetChanges();
        $this->synchronizeInspectorSceneChanges();
        $this->synchronizeInspectorPrefabChanges();
        $this->synchronizeInspectorAssetChanges();
        $this->synchronizeInspectorPanel();
        $this->synchronizeWatchedAssetChanges();

        $this->notify(new EditorEvent(EventType::EDITOR_UPDATED->value, $this));
    }

    private function render(): void
    {
        $this->frameCount++;
        $hasActiveSnackbar = $this->snackbar->hasActiveNotice();
        $snackbarIsDirty = $this->snackbar->isDirty();
        $shouldRefreshForSnackbar = $snackbarIsDirty;

        if ($this->projectNormalizationModal?->isVisible()) {
            $this->didRenderOverlayLastFrame = true;

            if ($this->shouldRefreshBackgroundUnderModal || $shouldRefreshForSnackbar) {
                $this->renderEditorFrame();
            }

            if ($this->shouldRefreshBackgroundUnderModal || $this->projectNormalizationModal->isDirty() || $shouldRefreshForSnackbar) {
                $this->projectNormalizationModal->render();

                if ($hasActiveSnackbar) {
                    $this->snackbar->render();
                }

                $this->projectNormalizationModal->markClean();
                $this->snackbar->markClean();
                $this->shouldRefreshBackgroundUnderModal = false;
            }

            $this->notify(new EditorEvent(EventType::EDITOR_RENDERED->value, $this));
            return;
        }

        if ($this->panelListModal->isVisible()) {
            $this->didRenderOverlayLastFrame = true;

            if ($this->shouldRefreshBackgroundUnderModal || $shouldRefreshForSnackbar) {
                $this->renderEditorFrame();
            }

            if ($this->shouldRefreshBackgroundUnderModal || $this->panelListModal->isDirty() || $shouldRefreshForSnackbar) {
                $this->panelListModal->render();

                if ($hasActiveSnackbar) {
                    $this->snackbar->render();
                }

                $this->panelListModal->markClean();
                $this->snackbar->markClean();
                $this->shouldRefreshBackgroundUnderModal = false;
            }

            $this->notify(new EditorEvent(EventType::EDITOR_RENDERED->value, $this));
            return;
        }

        if ($this->focusedPanel?->hasActiveModal()) {
            $this->didRenderOverlayLastFrame = true;
            $this->focusedPanel->syncModalLayout($this->terminalWidth, $this->terminalHeight);

            if ($this->focusedPanel->consumeModalBackgroundRefreshRequest()) {
                $this->shouldRefreshBackgroundUnderModal = true;
            }

            if ($this->shouldRefreshBackgroundUnderModal || $shouldRefreshForSnackbar) {
                $this->renderEditorFrame();
            }

            if ($this->shouldRefreshBackgroundUnderModal || $this->focusedPanel->isModalDirty() || $shouldRefreshForSnackbar) {
                $this->focusedPanel->renderActiveModal();

                if ($hasActiveSnackbar) {
                    $this->snackbar->render();
                }

                $this->focusedPanel->markModalClean();
                $this->snackbar->markClean();
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

        if ($hasActiveSnackbar) {
            $this->snackbar->render();
        }

        if ($snackbarIsDirty || $hasActiveSnackbar) {
            $this->snackbar->markClean();
        }

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

    private function initializeProjectIntegrityCheck(): void
    {
        $this->projectNormalizer = new ProjectNormalizer($this->workingDirectory);
        $this->projectNormalizationModal = new OptionListModal(title: 'Normalize Project');
        $this->projectNormalizationModal->syncLayout($this->terminalWidth, $this->terminalHeight);
        $this->projectDiscrepancies = $this->projectNormalizer->inspect();

        if ($this->projectDiscrepancies === []) {
            return;
        }

        $issueCount = count($this->projectDiscrepancies);
        $issueLabel = $issueCount === 1 ? 'issue' : 'issues';

        $this->projectNormalizationModal->show(
            ['Normalize', 'Cancel'],
            title: sprintf('Normalize Project? (%d %s)', $issueCount, $issueLabel),
        );
        $this->projectNormalizationModal->syncLayout($this->terminalWidth, $this->terminalHeight);
        $this->shouldRefreshBackgroundUnderModal = true;

        foreach ($this->projectDiscrepancies as $discrepancy) {
            $this->consolePanel->append('[WARN] - ' . $discrepancy);
        }
    }

    private function togglePlayMode(): void
    {
        if ($this->editorState instanceof PlayState) {
            $this->setState($this->editState);
            $this->stopManagedTmuxPlayPane();
        } else {
            $this->setState($this->playState);

            if ($this->canUseTmuxIntegration() && !$this->startManagedTmuxPlayPaneIfAvailable()) {
                $this->setState($this->editState);
                $this->pushNotification('Failed to launch the game pane for play mode.', 'error');
            }
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
            environmentCollisionMapPath: $this->loadedScene?->environmentCollisionMapPath ?? '',
        );
        $this->assetsPanel = new AssetsPanel(
            assetsDirectoryPath: $this->assetsDirectoryPath,
            workingDirectory: $this->workingDirectory,
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
        $this->inspectorPanel->setSceneHierarchy($this->loadedScene?->hierarchy ?? []);
        $this->snackbar = new Snackbar($this->settings->notificationDurationSeconds);

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
        $mouseEvent = Input::getMouseEvent();

        if (!$mouseEvent) {
            return;
        }

        if ($this->projectNormalizationModal?->isVisible()) {
            $this->handleProjectNormalizationModalMouseEvent($mouseEvent);
            return;
        }

        if ($this->panelListModal->isVisible()) {
            $this->handlePanelListModalMouseEvent($mouseEvent);
            return;
        }

        if ($this->focusedPanel?->hasActiveModal()) {
            $this->focusedPanel->handleModalMouseEvent($mouseEvent);
            return;
        }

        if (!in_array($mouseEvent->buttonIndex, [0, 2], true)) {
            return;
        }

        if ($mouseEvent->action === 'Dragged') {
            if ($this->focusedPanel?->containsPoint($mouseEvent->x, $mouseEvent->y)) {
                $this->focusedPanel->handleMouseEvent($mouseEvent);
            }

            return;
        }

        if ($mouseEvent->action === 'Released') {
            $this->focusedPanel?->handleMouseEvent($mouseEvent);
            return;
        }

        if ($mouseEvent->buttonIndex === 2) {
            if ($this->focusedPanel?->containsPoint($mouseEvent->x, $mouseEvent->y)) {
                $this->focusedPanel->handleMouseEvent($mouseEvent);
            }

            return;
        }

        if (!Input::isLeftMouseButtonPressed()) {
            return;
        }

        foreach ($this->panels as $panel) {
            if ($panel->containsPoint($mouseEvent->x, $mouseEvent->y)) {
                $this->setFocusedPanel($panel);
                $panel->handleMouseEvent($mouseEvent);
                return;
            }
        }
    }

    private function handleProjectNormalizationModalMouseEvent(MouseEvent $mouseEvent): void
    {
        if (!$this->projectNormalizationModal instanceof OptionListModal) {
            return;
        }

        if ($this->projectNormalizationModal->handleScrollbarMouseEvent($mouseEvent)) {
            return;
        }

        if ($mouseEvent->buttonIndex !== 0 || $mouseEvent->action !== 'Pressed') {
            return;
        }

        $selectedOption = $this->projectNormalizationModal->clickOptionAtPoint($mouseEvent->x, $mouseEvent->y);

        if (!is_string($selectedOption) || $selectedOption === '') {
            return;
        }

        $this->projectNormalizationModal->hide();

        if ($selectedOption === 'Normalize') {
            $this->normalizeLoadedProject();
        }

        $this->shouldRefreshBackgroundUnderModal = true;
    }

    private function handlePanelListModalMouseEvent(MouseEvent $mouseEvent): void
    {
        if ($mouseEvent->buttonIndex !== 0 || $mouseEvent->action !== 'Pressed') {
            return;
        }

        $selectedIndex = $this->panelListModal->clickPanelAtPoint($mouseEvent->x, $mouseEvent->y);

        if (!is_int($selectedIndex)) {
            return;
        }

        $this->panelListModal->hide();
        $panels = $this->panels->toArray();

        if (!isset($panels[$selectedIndex])) {
            return;
        }

        /** @var Widget $panel */
        $panel = $panels[$selectedIndex];
        $this->setFocusedPanel($panel);
        $this->shouldRefreshBackgroundUnderModal = true;
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

        if (
            $this->projectNormalizationModal?->isVisible()
            || $this->panelListModal->isVisible()
            || $this->focusedPanel?->hasActiveModal()
        ) {
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
        $this->projectNormalizationModal?->syncLayout($this->terminalWidth, $this->terminalHeight);
        $this->focusedPanel?->syncModalLayout($this->terminalWidth, $this->terminalHeight);
        $this->snackbar->syncLayout($this->terminalWidth, $this->terminalHeight);
    }

    private function handleProjectNormalizationModalInput(): void
    {
        if (!$this->projectNormalizationModal instanceof OptionListModal) {
            return;
        }

        if (Input::isKeyDown(IO\Enumerations\KeyCode::ESCAPE)) {
            $this->projectNormalizationModal->hide();
            $this->shouldRefreshBackgroundUnderModal = true;
            return;
        }

        if (Input::isKeyDown(IO\Enumerations\KeyCode::UP)) {
            $this->projectNormalizationModal->moveSelection(-1);
            return;
        }

        if (Input::isKeyDown(IO\Enumerations\KeyCode::DOWN)) {
            $this->projectNormalizationModal->moveSelection(1);
            return;
        }

        if (!Input::isKeyDown(IO\Enumerations\KeyCode::ENTER)) {
            return;
        }

        $selectedOption = $this->projectNormalizationModal->getSelectedOption();
        $this->projectNormalizationModal->hide();

        if ($selectedOption === 'Normalize') {
            $this->normalizeLoadedProject();
        }

        $this->shouldRefreshBackgroundUnderModal = true;
    }

    private function normalizeLoadedProject(): void
    {
        if (!$this->projectNormalizer instanceof ProjectNormalizer) {
            return;
        }

        $changes = $this->projectNormalizer->normalize();

        $this->initializeSettings();
        $this->initializeLoadedScene();
        $this->initializeWidgets();

        if ($changes === []) {
            $this->consolePanel->append('[INFO] - Project structure is already normalized.');
            return;
        }

        foreach ($changes as $change) {
            $this->consolePanel->append('[INFO] - ' . $change);
        }

        $this->projectDiscrepancies = $this->projectNormalizer->inspect();
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
            $asset = is_array($selectedItem['value'] ?? null) ? $selectedItem['value'] : null;
            $openInMainPanel = ($selectedItem['openInMainPanel'] ?? false) === true;
            $openInTerminalEditor = ($selectedItem['openInTerminalEditor'] ?? false) === true;
            $selectedItem = is_array($asset)
                ? $this->buildAssetInspectionTarget($asset, $openInMainPanel)
                : $selectedItem;

            if ($openInTerminalEditor && is_array($asset)) {
                $this->openAssetInConfiguredEditor($asset);
            }

            if ($openInMainPanel && $this->isEditableSpriteAsset($asset)) {
                $this->mainPanel->loadSpriteAsset($asset);
                $this->mainPanel->selectTab('Sprite');
                $this->setFocusedPanel($this->mainPanel);
            }
        }

        $this->inspectorPanel->inspectTarget($selectedItem);
    }

    private function synchronizeWatchedAssetChanges(bool $force = false): void
    {
        if (!isset($this->assetsPanel) || !isset($this->inspectorPanel)) {
            return;
        }

        $assetsDirectory = $this->resolveWatchedAssetsDirectory();
        $now = microtime(true);

        if ($assetsDirectory === null) {
            $this->watchedAssetSnapshot = [];
            $this->lastAssetWatchPollAt = $now;
            return;
        }

        if (
            !$force
            && $this->lastAssetWatchPollAt > 0.0
            && ($now - $this->lastAssetWatchPollAt) < self::ASSET_WATCH_INTERVAL_SECONDS
        ) {
            return;
        }

        $currentSnapshot = $this->captureWatchedAssetSnapshot($assetsDirectory);
        $previousSnapshot = $this->watchedAssetSnapshot;
        $this->watchedAssetSnapshot = $currentSnapshot;
        $this->lastAssetWatchPollAt = $now;

        if ($previousSnapshot === []) {
            return;
        }

        $changedAssetPaths = $this->detectChangedWatchedAssetPaths($previousSnapshot, $currentSnapshot);

        if ($changedAssetPaths === []) {
            return;
        }

        $this->assetsPanel->reloadAssets();
        $this->refreshInspectionAfterWatchedAssetChanges($changedAssetPaths);
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
                $fallbackAsset = [
                    'name' => basename($mutation['path']),
                    'path' => $mutation['path'],
                    'relativePath' => is_string($mutation['relativePath'] ?? null)
                        ? $mutation['relativePath']
                        : basename($mutation['path']),
                    'isDirectory' => false,
                    'children' => [],
                ];

                if (($mutation['activatePrefab'] ?? false) === true) {
                    $this->inspectorPanel->inspectTarget($this->buildAssetInspectionTarget($fallbackAsset, true));
                } else {
                    $this->inspectorPanel->syncAssetTarget($fallbackAsset);
                }
            }
            return;
        }

        $this->assetsPanel->reloadAssets();
        $this->assetsPanel->selectAssetByAbsolutePath($renamedAsset['path']);
        $this->assetsPanel->consumeInspectionRequest();
        $assetInspectionTarget = $this->buildAssetInspectionTarget(
            $renamedAsset,
            ($mutation['activatePrefab'] ?? false) === true
        );
        $this->inspectorPanel->inspectTarget($assetInspectionTarget);

        if ($this->isEditableSpriteAsset($renamedAsset)) {
            $this->mainPanel->loadSpriteAsset($renamedAsset);
        }
    }

    private function synchronizeInspectorPrefabChanges(): void
    {
        $mutation = $this->inspectorPanel->consumePrefabMutation();

        if (
            !is_array($mutation)
            || !is_string($mutation['prefabPath'] ?? null)
            || $mutation['prefabPath'] === ''
            || !is_array($mutation['value'] ?? null)
        ) {
            return;
        }

        if (!isset($this->prefabWriter)) {
            $this->prefabWriter = new PrefabWriter();
        }

        if (!$this->prefabWriter->save($mutation['prefabPath'], $mutation['value'])) {
            $this->consolePanel->append('[ERROR] - Failed to save prefab ' . basename($mutation['prefabPath']) . '.');
            $this->pushNotification('Failed to save prefab ' . basename($mutation['prefabPath']) . '.', 'error');
            return;
        }

        $asset = is_array($mutation['asset'] ?? null)
            ? $mutation['asset']
            : [
                'name' => basename($mutation['prefabPath']),
                'path' => $mutation['prefabPath'],
                'relativePath' => basename($mutation['prefabPath']),
                'isDirectory' => false,
                'children' => [],
            ];
        $asset['name'] = basename($mutation['prefabPath']);
        $asset['path'] = $mutation['prefabPath'];
        $asset['relativePath'] = $this->buildRelativeAssetPath($mutation['prefabPath']);

        $this->assetsPanel->reloadAssets();
        $this->assetsPanel->selectAssetByAbsolutePath($mutation['prefabPath']);
        $this->assetsPanel->consumeInspectionRequest();
        $this->inspectorPanel->inspectTarget($this->buildAssetInspectionTarget($asset, true));
    }

    private function synchronizeHierarchyAdditions(): void
    {
        $newItem = $this->hierarchyPanel->consumeCreationRequest();

        if (
            !$this->loadedScene instanceof DTOs\SceneDTO
            || !is_array($newItem)
            || !is_array($newItem['value'] ?? null)
        ) {
            return;
        }

        $parentPath = is_string($newItem['parentPath'] ?? null) ? $newItem['parentPath'] : null;
        $newPath = $parentPath !== null
            ? $this->appendHierarchyChild($parentPath, $newItem['value'])
            : $this->appendHierarchyRoot($newItem['value']);

        if (!is_string($newPath) || $newPath === '') {
            return;
        }

        $this->loadedScene->isDirty = true;
        $this->loadedScene->rawData['hierarchy'] = $this->loadedScene->hierarchy;
        $this->hierarchyPanel->syncHierarchy($this->loadedScene->hierarchy);

        if ($parentPath !== null) {
            $this->hierarchyPanel->expandPath($parentPath);
        }

        $this->hierarchyPanel->selectPath($newPath);
        $this->syncScenePanels(true);
        $this->mainPanel->setSceneObjects($this->loadedScene->hierarchy);
        $this->mainPanel->selectSceneObject($newPath);
    }

    private function synchronizeHierarchyMoves(): void
    {
        $moveRequest = $this->hierarchyPanel->consumeMoveRequest();

        if (
            !$this->loadedScene instanceof DTOs\SceneDTO
            || !is_array($moveRequest)
            || !is_string($moveRequest['path'] ?? null)
            || ($moveRequest['path'] ?? '') === ''
            || !is_string($moveRequest['targetPath'] ?? null)
            || ($moveRequest['targetPath'] ?? '') === ''
            || !in_array($moveRequest['position'] ?? null, ['before', 'after', 'append_child'], true)
        ) {
            return;
        }

        $targetPathForExpansion = $moveRequest['position'] === 'append_child'
            ? $this->adjustHierarchyPathAfterRemoval($moveRequest['path'], $moveRequest['targetPath'])
            : null;

        $newPath = $this->moveHierarchyNodeRelative(
            $moveRequest['path'],
            $moveRequest['targetPath'],
            $moveRequest['position'],
        );

        if (!is_string($newPath) || $newPath === '') {
            return;
        }

        $movedValue = $this->findHierarchyNodeByPath($newPath);

        $this->loadedScene->isDirty = true;
        $this->loadedScene->rawData['hierarchy'] = $this->loadedScene->hierarchy;
        $this->hierarchyPanel->syncHierarchy($this->loadedScene->hierarchy);

        if (is_string($targetPathForExpansion) && $targetPathForExpansion !== '') {
            $this->hierarchyPanel->expandPath($targetPathForExpansion);
        }

        $this->hierarchyPanel->selectPath($newPath);
        $this->syncScenePanels(true);
        $this->mainPanel->setSceneObjects($this->loadedScene->hierarchy);
        $this->mainPanel->selectSceneObject($newPath);

        if (is_array($movedValue)) {
            $this->inspectorPanel->inspectTarget([
                'context' => 'hierarchy',
                'name' => $movedValue['name'] ?? 'Unnamed Object',
                'type' => $this->resolveHierarchyInspectionType($movedValue),
                'path' => $newPath,
                'value' => $movedValue,
            ]);
        }
    }

    private function synchronizeHierarchyPrefabCreations(): void
    {
        $prefabCreationRequest = $this->hierarchyPanel->consumePrefabCreationRequest();

        if (
            !is_array($prefabCreationRequest)
            || !is_array($prefabCreationRequest['value'] ?? null)
        ) {
            return;
        }

        $createdPrefabAsset = $this->createPrefabFromHierarchyObject($prefabCreationRequest['value']);

        if (!is_array($createdPrefabAsset)) {
            return;
        }

        $this->assetsPanel->reloadAssets();
        $this->assetsPanel->selectAssetByAbsolutePath($createdPrefabAsset['path']);
        $this->assetsPanel->consumeInspectionRequest();
        $this->inspectorPanel->inspectTarget($this->buildAssetInspectionTarget($createdPrefabAsset, true));
        $this->setFocusedPanel($this->inspectorPanel);
    }

    private function synchronizeHierarchyDuplications(): void
    {
        $duplicationRequest = $this->hierarchyPanel->consumeDuplicationRequest()
            ?? $this->mainPanel->consumeDuplicationRequest();

        if (
            !$this->loadedScene instanceof DTOs\SceneDTO
            || !is_array($duplicationRequest)
            || !is_array($duplicationRequest['items'] ?? null)
        ) {
            return;
        }

        $duplicationItems = $this->filterRedundantDuplicationItems($duplicationRequest['items']);

        if ($duplicationItems === []) {
            return;
        }

        usort($duplicationItems, [$this, 'compareHierarchyPathsDescending']);
        $newPaths = [];

        foreach ($duplicationItems as $duplicationItem) {
            $path = $duplicationItem['path'] ?? null;
            $value = $duplicationItem['value'] ?? null;

            if (!is_string($path) || $path === '' || !is_array($value)) {
                continue;
            }

            $duplicatedValue = $value;
            $duplicatedValue['name'] = $this->buildUniqueDuplicateHierarchyName($path, $value['name'] ?? 'Object');
            $newPath = $this->insertHierarchyNodeAfter($path, $duplicatedValue);

            if (is_string($newPath) && $newPath !== '') {
                $newPaths[] = $newPath;
            }
        }

        if ($newPaths === []) {
            return;
        }

        usort($newPaths, [$this, 'compareHierarchyPathsAscending']);
        $primaryPath = end($newPaths);

        $this->loadedScene->isDirty = true;
        $this->loadedScene->rawData['hierarchy'] = $this->loadedScene->hierarchy;
        $this->hierarchyPanel->syncHierarchy($this->loadedScene->hierarchy);
        $this->hierarchyPanel->selectPaths($newPaths, is_string($primaryPath) ? $primaryPath : null);
        $this->syncScenePanels(true);
        $this->mainPanel->setSceneObjects($this->loadedScene->hierarchy);
        $this->mainPanel->selectSceneObjects($newPaths, is_string($primaryPath) ? $primaryPath : null);
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

    private function synchronizeAssetCreations(): void
    {
        $creationRequest = $this->assetsPanel->consumeCreationRequest();

        if (
            !is_array($creationRequest)
            || !is_string($creationRequest['kind'] ?? null)
            || $creationRequest['kind'] === ''
        ) {
            return;
        }

        $createdAsset = $this->createAssetUsingCliCommand($creationRequest['kind']);

        if (!is_array($createdAsset)) {
            return;
        }

        $this->assetsPanel->reloadAssets();
        $this->assetsPanel->selectAssetByAbsolutePath($createdAsset['path']);
        $inspectionTarget = $this->buildAssetInspectionTarget($createdAsset);
        $this->inspectorPanel->inspectTarget($inspectionTarget);
        $this->mainPanel->loadSpriteAsset($createdAsset);

        if ($this->isEditableSpriteAsset($createdAsset)) {
            $this->mainPanel->selectTab('Sprite');
            $this->setFocusedPanel($this->mainPanel);
            return;
        }

        $this->setFocusedPanel($this->inspectorPanel);
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

    private function resolveWatchedAssetsDirectory(): ?string
    {
        $assetsDirectory = is_string($this->assetsDirectoryPath) && $this->assetsDirectoryPath !== ''
            ? $this->resolveAbsolutePath($this->assetsDirectoryPath, $this->workingDirectory)
            : Path::resolveAssetsDirectory($this->workingDirectory);

        if (!is_string($assetsDirectory) || $assetsDirectory === '' || !is_dir($assetsDirectory)) {
            return null;
        }

        return Path::normalize($assetsDirectory);
    }

    private function captureWatchedAssetSnapshot(?string $assetsDirectory = null): array
    {
        $resolvedAssetsDirectory = $assetsDirectory ?? $this->resolveWatchedAssetsDirectory();

        if (!is_string($resolvedAssetsDirectory) || $resolvedAssetsDirectory === '' || !is_dir($resolvedAssetsDirectory)) {
            return [];
        }

        clearstatcache();

        $snapshot = [
            $resolvedAssetsDirectory => $this->buildWatchedAssetSignature($resolvedAssetsDirectory, true),
        ];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolvedAssetsDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            $entryPath = Path::normalize($entry->getPathname());
            $snapshot[$entryPath] = $this->buildWatchedAssetSignature($entryPath, $entry->isDir());
        }

        ksort($snapshot);

        return $snapshot;
    }

    private function buildWatchedAssetSignature(string $path, bool $isDirectory): string
    {
        $mtime = @filemtime($path);

        if ($isDirectory) {
            return 'd:' . ($mtime === false ? 'missing' : (string) $mtime);
        }

        $size = @filesize($path);
        $signature = sprintf(
            'f:%s:%s',
            $mtime === false ? 'missing' : (string) $mtime,
            $size === false ? 'missing' : (string) $size,
        );

        if (str_ends_with(strtolower($path), '.php')) {
            $hash = @md5_file($path);

            if (is_string($hash) && $hash !== '') {
                $signature .= ':' . $hash;
            }
        }

        return $signature;
    }

    private function detectChangedWatchedAssetPaths(array $previousSnapshot, array $currentSnapshot): array
    {
        $changedPaths = [];
        $allPaths = array_values(array_unique([
            ...array_keys($previousSnapshot),
            ...array_keys($currentSnapshot),
        ]));

        foreach ($allPaths as $path) {
            if (($previousSnapshot[$path] ?? null) === ($currentSnapshot[$path] ?? null)) {
                continue;
            }

            if (is_string($path) && $path !== '') {
                $changedPaths[] = $path;
            }
        }

        sort($changedPaths);

        return $changedPaths;
    }

    private function refreshInspectionAfterWatchedAssetChanges(array $changedAssetPaths): void
    {
        if (!isset($this->inspectorPanel)) {
            return;
        }

        $inspectionTarget = $this->inspectorPanel->getInspectionTarget();

        if (!is_array($inspectionTarget)) {
            return;
        }

        $hasChangedPhpAsset = $this->hasChangedPhpAsset($changedAssetPaths);

        switch ($inspectionTarget['context'] ?? null) {
            case 'hierarchy':
                if (!$hasChangedPhpAsset || !$this->refreshLoadedSceneComponentMetadata()) {
                    return;
                }

                $path = is_string($inspectionTarget['path'] ?? null) ? $inspectionTarget['path'] : null;

                if (!is_string($path) || $path === '') {
                    return;
                }

                $value = $this->findHierarchyNodeByPath($path);

                if (!is_array($value)) {
                    $this->inspectorPanel->inspectTarget(null);
                    return;
                }

                $this->hierarchyPanel->selectPath($path);
                $this->mainPanel->selectSceneObject($path);
                $this->inspectorPanel->inspectTarget([
                    'context' => 'hierarchy',
                    'name' => $value['name'] ?? 'Unnamed Object',
                    'type' => $this->resolveHierarchyInspectionType($value),
                    'path' => $path,
                    'value' => $value,
                ]);
                return;

            case 'scene':
                if (!$hasChangedPhpAsset || !$this->refreshLoadedSceneComponentMetadata()) {
                    return;
                }

                $this->hierarchyPanel->selectPath('scene');
                $this->inspectorPanel->inspectTarget($this->buildSceneInspectionTarget());
                return;

            case 'prefab':
                $prefabPath = $this->resolveInspectionAssetAbsolutePath($inspectionTarget);

                if (
                    !is_string($prefabPath)
                    || $prefabPath === ''
                    || (!$hasChangedPhpAsset && !$this->didWatchedAssetChange($prefabPath, $changedAssetPaths))
                ) {
                    return;
                }

                $asset = $this->resolveAssetEntryByAbsolutePath($prefabPath);

                if (!is_array($asset)) {
                    $this->inspectorPanel->inspectTarget(null);
                    return;
                }

                $prefabInspectionTarget = $this->buildPrefabInspectionTarget($asset);

                if (!is_array($prefabInspectionTarget)) {
                    $this->inspectorPanel->inspectTarget(null);
                    return;
                }

                $this->inspectorPanel->inspectTarget($prefabInspectionTarget);
                return;

            case 'asset':
                $assetPath = $this->resolveInspectionAssetAbsolutePath($inspectionTarget);

                if (
                    !is_string($assetPath)
                    || $assetPath === ''
                    || !$this->didWatchedAssetChange($assetPath, $changedAssetPaths)
                ) {
                    return;
                }

                $asset = $this->resolveAssetEntryByAbsolutePath($assetPath);

                if (!is_array($asset)) {
                    $this->inspectorPanel->inspectTarget(null);
                    return;
                }

                $this->inspectorPanel->inspectTarget($this->buildAssetInspectionTarget($asset));
                return;
        }
    }

    private function hasChangedPhpAsset(array $changedAssetPaths): bool
    {
        foreach ($changedAssetPaths as $path) {
            if (is_string($path) && str_ends_with(strtolower($path), '.php')) {
                return true;
            }
        }

        return false;
    }

    private function didWatchedAssetChange(string $absolutePath, array $changedAssetPaths): bool
    {
        $normalizedPath = Path::normalize($absolutePath);

        foreach ($changedAssetPaths as $changedPath) {
            if (!is_string($changedPath) || $changedPath === '') {
                continue;
            }

            if (Path::normalize($changedPath) === $normalizedPath) {
                return true;
            }
        }

        return false;
    }

    private function resolveInspectionAssetAbsolutePath(array $inspectionTarget): ?string
    {
        $candidatePath = $inspectionTarget['asset']['path'] ?? $inspectionTarget['value']['path'] ?? null;

        if (!is_string($candidatePath) || $candidatePath === '') {
            return null;
        }

        return Path::normalize($candidatePath);
    }

    private function resolveAssetEntryByAbsolutePath(string $absolutePath): ?array
    {
        if (!isset($this->assetsPanel)) {
            return null;
        }

        $normalizedAbsolutePath = Path::normalize($absolutePath);
        $this->assetsPanel->selectAssetByAbsolutePath($normalizedAbsolutePath);
        $this->assetsPanel->consumeInspectionRequest();
        $selectedAsset = $this->assetsPanel->getSelectedAssetEntry();
        $selectedAssetPath = is_string($selectedAsset['path'] ?? null)
            ? Path::normalize($selectedAsset['path'])
            : null;

        return $selectedAssetPath === $normalizedAbsolutePath
            ? $selectedAsset
            : null;
    }

    private function refreshLoadedSceneComponentMetadata(): bool
    {
        if (
            !$this->loadedScene instanceof DTOs\SceneDTO
            || !isset($this->hierarchyPanel)
            || !isset($this->mainPanel)
        ) {
            return false;
        }

        if (!isset($this->sceneWriter)) {
            $this->sceneWriter = new SceneWriter();
        }

        $temporarySceneSeed = tempnam(sys_get_temp_dir(), 'sendama-editor-watch-');

        if (!is_string($temporarySceneSeed) || $temporarySceneSeed === '') {
            return false;
        }

        @unlink($temporarySceneSeed);
        $temporaryScenePath = $temporarySceneSeed . '.scene.php';

        if (file_put_contents($temporaryScenePath, $this->sceneWriter->serialize($this->loadedScene)) === false) {
            @unlink($temporaryScenePath);
            return false;
        }

        try {
            $refreshedScene = (new SceneLoader($this->workingDirectory))->loadFromPath($temporaryScenePath);
        } finally {
            @unlink($temporaryScenePath);
        }

        if (!$refreshedScene instanceof DTOs\SceneDTO) {
            return false;
        }

        $sceneWasDirty = $this->loadedScene->isDirty;
        $this->loadedScene->hierarchy = $refreshedScene->hierarchy;
        $this->loadedScene->rawData['hierarchy'] = $this->sceneWriter->snapshot($this->loadedScene)['hierarchy']
            ?? $this->loadedScene->hierarchy;
        $this->loadedScene->isDirty = $sceneWasDirty;
        $this->hierarchyPanel->syncHierarchy($this->loadedScene->hierarchy);
        $this->mainPanel->setSceneObjects($this->loadedScene->hierarchy);
        $this->syncScenePanels($sceneWasDirty);

        return true;
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

    private function insertHierarchyNodeAfter(string $path, array $value): ?string
    {
        if (!$this->loadedScene instanceof DTOs\SceneDTO) {
            return null;
        }

        $segments = explode('.', $path);

        if (($segments[0] ?? null) !== 'scene') {
            return null;
        }

        array_shift($segments);

        if ($segments === []) {
            return null;
        }

        $hierarchy = $this->loadedScene->hierarchy;
        $nodeArray = &$hierarchy;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if (!ctype_digit((string) $segment)) {
                return null;
            }

            $numericSegment = (int) $segment;

            if (!isset($nodeArray[$numericSegment])) {
                return null;
            }

            if ($index === $lastIndex) {
                array_splice($nodeArray, $numericSegment + 1, 0, [$value]);
                $this->loadedScene->hierarchy = array_values($hierarchy);

                $parentSegments = $segments;
                array_pop($parentSegments);
                $newPathSegments = ['scene', ...$parentSegments, (string) ($numericSegment + 1)];

                return implode('.', $newPathSegments);
            }

            if (!isset($nodeArray[$numericSegment]['children']) || !is_array($nodeArray[$numericSegment]['children'])) {
                return null;
            }

            $nodeArray = &$nodeArray[$numericSegment]['children'];
        }

        return null;
    }

    private function insertHierarchyNodeBefore(string $path, array $value): ?string
    {
        if (!$this->loadedScene instanceof DTOs\SceneDTO) {
            return null;
        }

        $segments = explode('.', $path);

        if (($segments[0] ?? null) !== 'scene') {
            return null;
        }

        array_shift($segments);

        if ($segments === []) {
            return null;
        }

        $hierarchy = $this->loadedScene->hierarchy;
        $nodeArray = &$hierarchy;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if (!ctype_digit((string) $segment)) {
                return null;
            }

            $numericSegment = (int) $segment;

            if (!isset($nodeArray[$numericSegment])) {
                return null;
            }

            if ($index === $lastIndex) {
                array_splice($nodeArray, $numericSegment, 0, [$value]);
                $this->loadedScene->hierarchy = array_values($hierarchy);

                $parentSegments = $segments;
                array_pop($parentSegments);
                $newPathSegments = ['scene', ...$parentSegments, (string) $numericSegment];

                return implode('.', $newPathSegments);
            }

            if (!isset($nodeArray[$numericSegment]['children']) || !is_array($nodeArray[$numericSegment]['children'])) {
                return null;
            }

            $nodeArray = &$nodeArray[$numericSegment]['children'];
        }

        return null;
    }

    private function appendHierarchyRoot(array $value): string
    {
        $this->loadedScene->hierarchy[] = $value;

        return 'scene.' . (count($this->loadedScene->hierarchy) - 1);
    }

    private function appendHierarchyChild(string $parentPath, array $value): ?string
    {
        if (!$this->loadedScene instanceof DTOs\SceneDTO) {
            return null;
        }

        $segments = explode('.', $parentPath);

        if (($segments[0] ?? null) !== 'scene') {
            return null;
        }

        array_shift($segments);

        if ($segments === []) {
            return $this->appendHierarchyRoot($value);
        }

        $hierarchy = $this->loadedScene->hierarchy;
        $nodeArray = &$hierarchy;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if (!ctype_digit((string) $segment)) {
                return null;
            }

            $numericSegment = (int) $segment;

            if (!isset($nodeArray[$numericSegment]) || !is_array($nodeArray[$numericSegment])) {
                return null;
            }

            if ($index === $lastIndex) {
                $nodeArray[$numericSegment]['children'] ??= [];

                if (!is_array($nodeArray[$numericSegment]['children'])) {
                    return null;
                }

                $nodeArray[$numericSegment]['children'][] = $value;
                $newChildIndex = count($nodeArray[$numericSegment]['children']) - 1;
                $this->loadedScene->hierarchy = array_values($hierarchy);

                return $parentPath . '.' . $newChildIndex;
            }

            if (!isset($nodeArray[$numericSegment]['children']) || !is_array($nodeArray[$numericSegment]['children'])) {
                return null;
            }

            $nodeArray = &$nodeArray[$numericSegment]['children'];
        }

        return null;
    }

    private function moveHierarchyNodeRelative(string $path, string $targetPath, string $position): ?string
    {
        if ($path === $targetPath || str_starts_with($targetPath, $path . '.')) {
            return null;
        }

        $node = $this->extractHierarchyNode($path);

        if (!is_array($node)) {
            return null;
        }

        $adjustedTargetPath = $this->adjustHierarchyPathAfterRemoval($path, $targetPath);

        return match ($position) {
            'before' => $this->insertHierarchyNodeBefore($adjustedTargetPath, $node),
            'after' => $this->insertHierarchyNodeAfter($adjustedTargetPath, $node),
            'append_child' => $this->appendHierarchyChild($adjustedTargetPath, $node),
            default => null,
        };
    }

    private function extractHierarchyNode(string $path): ?array
    {
        if (!$this->loadedScene instanceof DTOs\SceneDTO) {
            return null;
        }

        $segments = explode('.', $path);

        if (($segments[0] ?? null) !== 'scene') {
            return null;
        }

        array_shift($segments);

        if ($segments === []) {
            return null;
        }

        $hierarchy = $this->loadedScene->hierarchy;
        $nodeArray = &$hierarchy;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if (!ctype_digit((string) $segment)) {
                return null;
            }

            $numericSegment = (int) $segment;

            if (!isset($nodeArray[$numericSegment])) {
                return null;
            }

            if ($index === $lastIndex) {
                $node = $nodeArray[$numericSegment];
                unset($nodeArray[$numericSegment]);
                $nodeArray = array_values($nodeArray);
                $this->loadedScene->hierarchy = array_values($hierarchy);

                return is_array($node) ? $node : null;
            }

            if (!isset($nodeArray[$numericSegment]['children']) || !is_array($nodeArray[$numericSegment]['children'])) {
                return null;
            }

            $nodeArray = &$nodeArray[$numericSegment]['children'];
        }

        return null;
    }

    private function adjustHierarchyPathAfterRemoval(string $removedPath, string $targetPath): string
    {
        $removedSegments = array_slice(explode('.', $removedPath), 1);
        $targetSegments = array_slice(explode('.', $targetPath), 1);

        if ($removedSegments === [] || $targetSegments === []) {
            return $targetPath;
        }

        $removedIndex = (int) array_pop($removedSegments);
        $removedParentSegments = $removedSegments;
        $removedDepth = count($removedParentSegments);

        if (count($targetSegments) <= $removedDepth) {
            return $targetPath;
        }

        if (array_slice($targetSegments, 0, $removedDepth) !== $removedParentSegments) {
            return $targetPath;
        }

        $targetIndex = (int) ($targetSegments[$removedDepth] ?? -1);

        if ($targetIndex <= $removedIndex) {
            return $targetPath;
        }

        $targetSegments[$removedDepth] = (string) ($targetIndex - 1);

        return 'scene.' . implode('.', $targetSegments);
    }

    private function findHierarchyNodeByPath(string $path): ?array
    {
        if (!$this->loadedScene instanceof DTOs\SceneDTO) {
            return null;
        }

        $segments = explode('.', $path);

        if (($segments[0] ?? null) !== 'scene') {
            return null;
        }

        array_shift($segments);

        if ($segments === []) {
            return null;
        }

        $nodeArray = $this->loadedScene->hierarchy;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if (!ctype_digit((string) $segment)) {
                return null;
            }

            $numericSegment = (int) $segment;

            if (!isset($nodeArray[$numericSegment]) || !is_array($nodeArray[$numericSegment])) {
                return null;
            }

            if ($index === $lastIndex) {
                return $nodeArray[$numericSegment];
            }

            if (!isset($nodeArray[$numericSegment]['children']) || !is_array($nodeArray[$numericSegment]['children'])) {
                return null;
            }

            $nodeArray = $nodeArray[$numericSegment]['children'];
        }

        return null;
    }

    private function resolveHierarchyInspectionType(array $value): string
    {
        $type = $value['type'] ?? null;

        if (!is_string($type) || $type === '') {
            return 'Unknown';
        }

        $normalizedType = ltrim($type, '\\');
        $normalizedType = preg_replace('/::class$/', '', $normalizedType) ?? $normalizedType;
        $typeSegments = explode('\\', $normalizedType);

        return end($typeSegments) ?: $normalizedType;
    }

    private function filterRedundantDuplicationItems(array $items): array
    {
        $normalizedItems = [];

        foreach ($items as $item) {
            $path = $item['path'] ?? null;
            $value = $item['value'] ?? null;

            if (!is_string($path) || $path === '' || !is_array($value)) {
                continue;
            }

            $normalizedItems[$path] = [
                'path' => $path,
                'value' => $value,
            ];
        }

        $paths = array_keys($normalizedItems);
        usort($paths, static function (string $left, string $right): int {
            return substr_count($left, '.') <=> substr_count($right, '.');
        });

        $filteredItems = [];

        foreach ($paths as $path) {
            $hasSelectedAncestor = false;

            foreach (array_keys($filteredItems) as $keptPath) {
                if (str_starts_with($path, $keptPath . '.')) {
                    $hasSelectedAncestor = true;
                    break;
                }
            }

            if ($hasSelectedAncestor) {
                continue;
            }

            $filteredItems[$path] = $normalizedItems[$path];
        }

        return array_values($filteredItems);
    }

    private function buildUniqueDuplicateHierarchyName(string $path, string $originalName): string
    {
        $siblingNames = $this->collectSiblingHierarchyNames($path);
        $trimmedName = trim($originalName);
        $baseName = $trimmedName !== '' ? $trimmedName : 'Object';

        if (preg_match('/^(.*?)(\d+)$/', $baseName, $matches) === 1) {
            $prefix = $matches[1];
            $numericSuffix = $matches[2];
            $nextNumber = ((int) $numericSuffix) + 1;
            $padding = strlen($numericSuffix);

            do {
                $candidateName = $prefix . str_pad((string) $nextNumber, $padding, '0', STR_PAD_LEFT);
                $nextNumber++;
            } while (in_array($candidateName, $siblingNames, true));

            return $candidateName;
        }

        $prefix = rtrim($baseName) . ' ';
        $nextNumber = 1;

        do {
            $candidateName = $prefix . $nextNumber;
            $nextNumber++;
        } while (in_array($candidateName, $siblingNames, true));

        return $candidateName;
    }

    private function collectSiblingHierarchyNames(string $path): array
    {
        if (!$this->loadedScene instanceof DTOs\SceneDTO) {
            return [];
        }

        $segments = explode('.', $path);

        if (($segments[0] ?? null) !== 'scene') {
            return [];
        }

        array_shift($segments);

        if ($segments === []) {
            return [];
        }

        $nodeArray = $this->loadedScene->hierarchy;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if (!ctype_digit((string) $segment)) {
                return [];
            }

            $numericSegment = (int) $segment;

            if ($index === $lastIndex) {
                return array_values(array_filter(array_map(
                    static fn (mixed $item): ?string => is_array($item) && is_string($item['name'] ?? null) ? $item['name'] : null,
                    $nodeArray
                )));
            }

            if (!isset($nodeArray[$numericSegment]['children']) || !is_array($nodeArray[$numericSegment]['children'])) {
                return [];
            }

            $nodeArray = $nodeArray[$numericSegment]['children'];
        }

        return [];
    }

    private function compareHierarchyPathsDescending(array $left, array $right): int
    {
        return $this->compareHierarchyPaths($right['path'] ?? '', $left['path'] ?? '');
    }

    private function compareHierarchyPathsAscending(string $left, string $right): int
    {
        return $this->compareHierarchyPaths($left, $right);
    }

    private function compareHierarchyPaths(string $left, string $right): int
    {
        $leftSegments = array_map('intval', array_slice(explode('.', $left), 1));
        $rightSegments = array_map('intval', array_slice(explode('.', $right), 1));
        $maxLength = max(count($leftSegments), count($rightSegments));

        for ($index = 0; $index < $maxLength; $index++) {
            $leftSegment = $leftSegments[$index] ?? -1;
            $rightSegment = $rightSegments[$index] ?? -1;

            if ($leftSegment !== $rightSegment) {
                return $leftSegment <=> $rightSegment;
            }
        }

        return count($leftSegments) <=> count($rightSegments);
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

    private function createAssetUsingCliCommand(string $kind): ?array
    {
        $definition = $this->resolveAssetCreationDefinition($kind);

        if ($definition === null) {
            $this->consolePanel->append('[ERROR] - Unsupported asset type selected.');
            return null;
        }

        $originalWorkingDirectory = getcwd();

        if ($originalWorkingDirectory === false) {
            $this->consolePanel->append('[ERROR] - Failed to resolve the current working directory.');
            return null;
        }

        $projectDirectory = $this->resolveAbsoluteDirectory($this->workingDirectory);

        try {
            if (!@chdir($projectDirectory)) {
                $this->consolePanel->append('[ERROR] - Failed to switch to the project directory.');
                return null;
            }

            for ($index = 1; $index <= 200; $index++) {
                $candidateName = $definition['baseName'] . '-' . $index;
                $result = $this->runAssetGenerationCommand($definition, $candidateName);

                if (($result['status'] ?? null) === 'success' && is_array($result['asset'] ?? null)) {
                    return $result['asset'];
                }

                if (($result['status'] ?? null) === 'fatal') {
                    return null;
                }
            }
        } finally {
            @chdir($originalWorkingDirectory);
        }

        $this->consolePanel->append('[ERROR] - Failed to create asset after multiple attempts.');

        return null;
    }

    private function createPrefabFromHierarchyObject(array $item): ?array
    {
        $assetsDirectory = $this->assetsDirectoryPath;

        if (!is_string($assetsDirectory) || $assetsDirectory === '') {
            $assetsDirectory = Path::resolveAssetsDirectory($this->workingDirectory);
        }

        if (!is_string($assetsDirectory) || $assetsDirectory === '') {
            $this->consolePanel->append('[ERROR] - Failed to resolve the assets directory for prefab export.');
            $this->pushNotification('Failed to resolve the assets directory for prefab export.', 'error');
            return null;
        }

        $prefabsDirectory = Path::join($assetsDirectory, 'Prefabs');

        if (!is_dir($prefabsDirectory) && !@mkdir($prefabsDirectory, 0777, true) && !is_dir($prefabsDirectory)) {
            $this->consolePanel->append('[ERROR] - Failed to create the Prefabs directory.');
            $this->pushNotification('Failed to create the Prefabs directory.', 'error');
            return null;
        }

        if (!isset($this->prefabWriter)) {
            $this->prefabWriter = new PrefabWriter();
        }

        $prefabPath = $this->buildUniquePrefabPath($prefabsDirectory, $item['name'] ?? null);

        if (!$this->prefabWriter->save($prefabPath, $item)) {
            $this->consolePanel->append('[ERROR] - Failed to create prefab ' . basename($prefabPath) . '.');
            $this->pushNotification('Failed to create prefab ' . basename($prefabPath) . '.', 'error');
            return null;
        }

        $relativePath = $this->buildRelativeAssetPath($prefabPath);
        $this->consolePanel->append('[INFO] - Created prefab ' . $relativePath . '.');
        $this->pushNotification('Created prefab ' . basename($prefabPath) . '.', 'success');

        return [
            'name' => basename($prefabPath),
            'path' => $prefabPath,
            'relativePath' => $relativePath,
            'isDirectory' => false,
            'children' => [],
        ];
    }

    private function resolveAssetCreationDefinition(string $kind): ?array
    {
        return match ($kind) {
            'script' => [
                'command' => GenerateScript::class,
                'baseName' => 'new-script',
            ],
            'scene' => [
                'command' => GenerateScene::class,
                'baseName' => 'new-scene',
            ],
            'prefab' => [
                'command' => GeneratePrefab::class,
                'baseName' => 'new-prefab',
            ],
            'texture' => [
                'command' => GenerateTexture::class,
                'baseName' => 'new-texture',
            ],
            'tilemap' => [
                'command' => GenerateTilemap::class,
                'baseName' => 'new-map',
            ],
            'event' => [
                'command' => GenerateEvent::class,
                'baseName' => 'new-event',
            ],
            default => null,
        };
    }

    private function runAssetGenerationCommand(array $definition, string $candidateName): array
    {
        $commandClass = $definition['command'] ?? null;

        if (!is_string($commandClass) || !is_a($commandClass, Command::class, true)) {
            return ['status' => 'fatal'];
        }

        /** @var Command $command */
        $command = new $commandClass();
        $input = new ArrayInput([
            'name' => $candidateName,
        ]);
        $input->setInteractive(false);
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
        $exitCode = $command->run($input, $output);
        $commandOutput = trim($output->fetch());

        if ($exitCode !== Command::SUCCESS) {
            if (str_contains($commandOutput, 'already exists')) {
                return ['status' => 'retry'];
            }

            $message = $commandOutput !== ''
                ? preg_replace('/\s+/', ' ', strip_tags($commandOutput))
                : 'Asset generation failed.';
            $this->consolePanel->append('[ERROR] - ' . $message);
            $this->pushNotification($message, 'error');

            return ['status' => 'fatal'];
        }

        $relativeFilename = $this->extractCreatedRelativeFilename($commandOutput);

        if (!is_string($relativeFilename) || $relativeFilename === '') {
            $this->consolePanel->append('[ERROR] - Asset generation succeeded but the created file could not be resolved.');
            $this->pushNotification('Created asset could not be resolved.', 'error');
            return ['status' => 'fatal'];
        }

        $absolutePath = $this->resolveGeneratedAssetAbsolutePath($relativeFilename);

        if (!is_string($absolutePath) || !is_file($absolutePath)) {
            $this->consolePanel->append('[ERROR] - Generated asset file could not be found.');
            $this->pushNotification('Generated asset file could not be found.', 'error');
            return ['status' => 'fatal'];
        }

        $finalPath = $this->relocateGeneratedAssetToActiveRoot($absolutePath, $relativeFilename);

        if (!is_string($finalPath) || !is_file($finalPath)) {
            $this->consolePanel->append('[ERROR] - Generated asset file could not be activated in the current assets directory.');
            $this->pushNotification('Generated asset file could not be activated.', 'error');
            return ['status' => 'fatal'];
        }

        $this->consolePanel->append('[INFO] - Created asset ' . $this->buildRelativeAssetPath($finalPath) . '.');
        $this->pushNotification('Created asset ' . basename($finalPath) . '.', 'success');

        return [
            'status' => 'success',
            'asset' => [
                'name' => basename($finalPath),
                'path' => $finalPath,
                'relativePath' => $this->buildRelativeAssetPath($finalPath),
                'isDirectory' => false,
                'children' => [],
            ],
        ];
    }

    private function extractCreatedRelativeFilename(string $output): ?string
    {
        if (preg_match('/CREATE\s+([^\s]+)\s+\(\d+\s+bytes\)/i', $output, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/\b([Aa]ssets\/[^\s]+)\b/', $output, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function resolveGeneratedAssetAbsolutePath(string $relativeFilename): ?string
    {
        $normalizedRelativeFilename = str_replace('\\', '/', $relativeFilename);
        $projectDirectory = $this->resolveAbsoluteDirectory($this->workingDirectory);
        $candidatePaths = [
            $this->resolveAbsolutePath($normalizedRelativeFilename, $projectDirectory),
        ];

        if (is_string($this->assetsDirectoryPath) && $this->assetsDirectoryPath !== '') {
            $assetsDirectory = $this->resolveAbsolutePath($this->assetsDirectoryPath, $projectDirectory);
            $segments = explode('/', $normalizedRelativeFilename);

            if (count($segments) > 1 && strcasecmp($segments[0], 'assets') === 0) {
                array_shift($segments);
                $candidatePaths[] = Path::join($assetsDirectory, ...$segments);
            }
        }

        foreach ($candidatePaths as $candidatePath) {
            if (is_file($candidatePath)) {
                return $candidatePath;
            }
        }

        $relativeTail = ltrim(preg_replace('/^assets\//i', '', $normalizedRelativeFilename) ?? $normalizedRelativeFilename, '/');
        $relativeTailSegments = $relativeTail !== '' ? explode('/', $relativeTail) : [];
        $relativeTailBasename = $relativeTailSegments !== [] ? end($relativeTailSegments) : null;

        $searchRoots = [];

        if (is_string($this->assetsDirectoryPath) && $this->assetsDirectoryPath !== '') {
            $searchRoots[] = $this->resolveAbsolutePath($this->assetsDirectoryPath, $projectDirectory);
        }

        $searchRoots[] = Path::resolveAssetsDirectory($projectDirectory);
        $searchRoots = array_values(array_unique(array_filter($searchRoots, static fn (mixed $root): bool => is_string($root) && $root !== '' && is_dir($root))));

        foreach ($searchRoots as $searchRoot) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($searchRoot, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }

                $entryPath = Path::normalize($entry->getPathname());

                if ($relativeTailBasename !== null && basename($entryPath) !== $relativeTailBasename) {
                    continue;
                }

                $relativeToSearchRoot = ltrim(substr($entryPath, strlen($searchRoot)), '/');

                if (
                    $relativeTail !== ''
                    && str_ends_with(str_replace('\\', '/', $relativeToSearchRoot), $relativeTail)
                ) {
                    return $entryPath;
                }
            }
        }

        return null;
    }

    private function relocateGeneratedAssetToActiveRoot(string $absolutePath, string $relativeFilename): string
    {
        if (!is_string($this->assetsDirectoryPath) || $this->assetsDirectoryPath === '') {
            return $absolutePath;
        }

        $projectDirectory = $this->resolveAbsoluteDirectory($this->workingDirectory);
        $activeAssetsDirectory = $this->resolveAbsolutePath($this->assetsDirectoryPath, $projectDirectory);
        $normalizedRelativeFilename = str_replace('\\', '/', $relativeFilename);
        $segments = explode('/', $normalizedRelativeFilename);
        $generatedRootSegment = $segments[0] ?? 'Assets';

        if (count($segments) <= 1 || strcasecmp($segments[0], 'assets') !== 0) {
            return $absolutePath;
        }

        array_shift($segments);
        $targetPath = Path::join($activeAssetsDirectory, ...$segments);

        if ($targetPath === $absolutePath) {
            return $absolutePath;
        }

        if (file_exists($targetPath)) {
            return $targetPath;
        }

        if (!is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0777, true);
        }

        if (!rename($absolutePath, $targetPath)) {
            return $absolutePath;
        }

        $generatedAssetsRoot = Path::join($projectDirectory, $generatedRootSegment);

        if (str_starts_with($absolutePath, $generatedAssetsRoot)) {
            $this->cleanupEmptyDirectories(dirname($absolutePath), $generatedAssetsRoot);
        }

        return $targetPath;
    }

    private function cleanupEmptyDirectories(string $directory, string $stopAt): void
    {
        $currentDirectory = $directory;

        while ($currentDirectory !== $stopAt && str_starts_with($currentDirectory, $stopAt)) {
            if (!is_dir($currentDirectory)) {
                break;
            }

            $entries = scandir($currentDirectory);

            if ($entries === false || array_diff($entries, ['.', '..']) !== []) {
                break;
            }

            rmdir($currentDirectory);
            $currentDirectory = dirname($currentDirectory);
        }

        if (
            $currentDirectory === $stopAt
            && $currentDirectory !== $this->assetsDirectoryPath
            && is_dir($currentDirectory)
        ) {
            $entries = scandir($currentDirectory);

            if ($entries !== false && array_diff($entries, ['.', '..']) === []) {
                rmdir($currentDirectory);
            }
        }
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

        if (!$this->synchronizePhpAssetClassNameWithFileRename($targetAbsolutePath, $oldRelativePath, $newRelativePath)) {
            if (
                $targetAbsolutePath !== $currentAbsolutePath
                && is_file($targetAbsolutePath)
                && !file_exists($currentAbsolutePath)
            ) {
                @rename($targetAbsolutePath, $currentAbsolutePath);
            }

            return null;
        }

        if ($this->updateSceneAssetReferences($oldRelativePath, $newRelativePath)) {
            if ($this->loadedScene instanceof DTOs\SceneDTO) {
                $this->loadedScene->rawData['hierarchy'] = $this->loadedScene->hierarchy;
                $this->loadedScene->rawData['environmentTileMapPath'] = $this->loadedScene->environmentTileMapPath;
                $this->loadedScene->rawData['environmentCollisionMapPath'] = $this->loadedScene->environmentCollisionMapPath;
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

    private function synchronizePhpAssetClassNameWithFileRename(
        string $targetAbsolutePath,
        string $oldRelativePath,
        string $newRelativePath,
    ): bool {
        if (
            !$this->isPhpClassBackedAssetPath($oldRelativePath)
            && !$this->isPhpClassBackedAssetPath($newRelativePath)
        ) {
            return true;
        }

        if (strtolower((string) pathinfo($targetAbsolutePath, PATHINFO_EXTENSION)) !== 'php') {
            return true;
        }

        $source = file_get_contents($targetAbsolutePath);

        if (!is_string($source) || $source === '') {
            $this->consolePanel->append('[ERROR] - Failed to update the renamed asset source.');
            return false;
        }

        $oldClassName = $this->derivePhpAssetClassNameFromRelativePath($oldRelativePath);
        $newClassName = $this->derivePhpAssetClassNameFromRelativePath($newRelativePath);

        if ($newClassName === '') {
            $this->consolePanel->append('[ERROR] - Failed to derive the renamed asset class name.');
            return false;
        }

        $updatedSource = $source;

        if ($oldClassName !== '') {
            $updatedSource = preg_replace(
                '/\b(class\s+)' . preg_quote($oldClassName, '/') . '(\b)/',
                '${1}' . $newClassName . '$2',
                $updatedSource,
                1,
                $replacementCount,
            );

            if (!is_string($updatedSource)) {
                $this->consolePanel->append('[ERROR] - Failed to update the renamed asset class.');
                return false;
            }

            if (($replacementCount ?? 0) === 0) {
                $updatedSource = $source;
            }
        }

        if ($updatedSource === $source) {
            $updatedSource = preg_replace(
                '/\bclass\s+[A-Za-z_][A-Za-z0-9_]*\b/',
                'class ' . $newClassName,
                $source,
                1,
            );

            if (!is_string($updatedSource) || $updatedSource === $source) {
                $this->consolePanel->append('[ERROR] - Failed to locate the asset class declaration after rename.');
                return false;
            }
        }

        if (file_put_contents($targetAbsolutePath, $updatedSource) === false) {
            $this->consolePanel->append('[ERROR] - Failed to write the renamed asset source.');
            return false;
        }

        return true;
    }

    private function isPhpClassBackedAssetPath(string $relativePath): bool
    {
        $normalizedPath = str_replace('\\', '/', $relativePath);

        return str_starts_with($normalizedPath, 'Scripts/')
            || str_starts_with($normalizedPath, 'Events/');
    }

    private function derivePhpAssetClassNameFromRelativePath(string $relativePath): string
    {
        $baseName = (string) pathinfo(basename(str_replace('\\', '/', $relativePath)), PATHINFO_FILENAME);

        if ($baseName === '') {
            return '';
        }

        $tokens = preg_split('/[^A-Za-z0-9]+/', $baseName) ?: [];
        $tokens = array_values(array_filter($tokens, static fn(string $token): bool => $token !== ''));

        if (count($tokens) <= 1) {
            return preg_match('/[A-Z]/', $baseName) === 1
                ? ucfirst($baseName)
                : to_pascal_case($baseName);
        }

        return implode('', array_map(
            static fn(string $token): string => ucfirst($token),
            array_map('strtolower', $tokens),
        ));
    }

    private function normalizeAssetFileName(string $requestedName, string $currentAbsolutePath): string
    {
        $trimmedName = trim(str_replace('\\', '/', $requestedName));
        $trimmedName = basename($trimmedName);
        $fileNameSuffix = $this->resolveAssetFileNameSuffix($currentAbsolutePath);

        if ($trimmedName === '') {
            return basename($currentAbsolutePath);
        }

        $requestedBaseName = str_ends_with(strtolower($trimmedName), strtolower($fileNameSuffix))
            ? substr($trimmedName, 0, -strlen($fileNameSuffix))
            : (string) pathinfo($trimmedName, PATHINFO_FILENAME);

        if ($requestedBaseName === '') {
            $requestedBaseName = basename(basename($currentAbsolutePath), $fileNameSuffix);
        }

        return $requestedBaseName . $fileNameSuffix;
    }

    private function resolveAssetFileNameSuffix(string $absolutePath): string
    {
        $normalizedBaseName = strtolower(basename(str_replace('\\', '/', $absolutePath)));

        foreach (['.prefab.php', '.scene.php'] as $compoundSuffix) {
            if (str_ends_with($normalizedBaseName, $compoundSuffix)) {
                return substr(basename($absolutePath), -strlen($compoundSuffix));
            }
        }

        $extension = pathinfo($absolutePath, PATHINFO_EXTENSION);

        return $extension !== ''
            ? '.' . $extension
            : '';
    }

    private function buildRelativeAssetPath(string $absolutePath): string
    {
        $assetsDirectory = $this->assetsDirectoryPath;

        if (!is_string($assetsDirectory) || $assetsDirectory === '') {
            return basename($absolutePath);
        }

        $assetsDirectory = $this->resolveAbsolutePath($assetsDirectory, $this->workingDirectory);
        $relativePath = substr($absolutePath, strlen($assetsDirectory));

        return ltrim(str_replace('\\', '/', (string) $relativePath), '/');
    }

    private function buildUniquePrefabPath(string $prefabsDirectory, mixed $displayName): string
    {
        $baseName = is_string($displayName) ? trim($displayName) : '';
        $baseName = $baseName !== '' ? to_kebab_case($baseName) : 'new-prefab';
        $baseName = preg_replace('/[^A-Za-z0-9]+/', '-', $baseName) ?? $baseName;
        $baseName = trim($baseName, '-');
        $baseName = strtolower($baseName);
        $baseName = $baseName !== '' ? $baseName : 'new-prefab';
        $candidatePath = Path::join($prefabsDirectory, $baseName . '.prefab.php');

        if (!file_exists($candidatePath)) {
            return $candidatePath;
        }

        for ($index = 2; $index <= 200; $index++) {
            $candidatePath = Path::join($prefabsDirectory, $baseName . '-' . $index . '.prefab.php');

            if (!file_exists($candidatePath)) {
                return $candidatePath;
            }
        }

        return Path::join($prefabsDirectory, $baseName . '-' . uniqid() . '.prefab.php');
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

        if ($this->loadedScene->environmentCollisionMapPath === $oldWithExtension) {
            $this->loadedScene->environmentCollisionMapPath = $newWithExtension;
            $hasChanges = true;
        } elseif ($this->loadedScene->environmentCollisionMapPath === $oldWithoutExtension) {
            $this->loadedScene->environmentCollisionMapPath = $newWithoutExtension;
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

    private function buildAssetInspectionTarget(array $asset, bool $activatePrefab = false): array
    {
        if ($activatePrefab && $this->isPrefabAsset($asset)) {
            $prefabInspectionTarget = $this->buildPrefabInspectionTarget($asset);

            if (is_array($prefabInspectionTarget)) {
                return $prefabInspectionTarget;
            }
        }

        return [
            'context' => 'asset',
            'name' => $asset['name'] ?? basename((string) ($asset['path'] ?? '')),
            'type' => ($asset['isDirectory'] ?? false) ? 'Folder' : 'File',
            'value' => $asset,
        ];
    }

    private function buildPrefabInspectionTarget(array $asset): ?array
    {
        $prefabPath = is_string($asset['path'] ?? null) ? $asset['path'] : null;

        if (!is_string($prefabPath) || $prefabPath === '') {
            return null;
        }

        $prefabData = (new PrefabLoader($this->resolveProjectDirectoryForAsset($asset)))->load($prefabPath);

        if (!is_array($prefabData)) {
            return null;
        }

        return [
            'context' => 'prefab',
            'path' => $asset['relativePath'] ?? basename($prefabPath),
            'name' => $prefabData['name'] ?? ($asset['name'] ?? basename($prefabPath)),
            'type' => $this->resolveClassReferenceDisplayName($prefabData['type'] ?? null, 'Prefab'),
            'value' => $prefabData,
            'asset' => $asset,
        ];
    }

    private function isPrefabAsset(?array $asset): bool
    {
        if (!is_array($asset) || ($asset['isDirectory'] ?? false)) {
            return false;
        }

        $assetPath = is_string($asset['relativePath'] ?? null)
            ? $asset['relativePath']
            : (is_string($asset['path'] ?? null) ? $asset['path'] : null);

        return is_string($assetPath) && str_ends_with(strtolower($assetPath), '.prefab.php');
    }

    private function resolveProjectDirectoryForAsset(array $asset): string
    {
        if (isset($this->workingDirectory) && is_string($this->workingDirectory) && $this->workingDirectory !== '') {
            return $this->workingDirectory;
        }

        $assetPath = is_string($asset['path'] ?? null) ? Path::normalize($asset['path']) : null;
        $relativePath = is_string($asset['relativePath'] ?? null)
            ? Path::normalize($asset['relativePath'])
            : null;

        if (!is_string($assetPath) || $assetPath === '' || !is_string($relativePath) || $relativePath === '') {
            return '.';
        }

        if (!str_ends_with($assetPath, $relativePath)) {
            return dirname($assetPath);
        }

        $normalizedAssetRoot = rtrim(substr($assetPath, 0, -strlen($relativePath)), '/');

        if (str_ends_with(strtolower($normalizedAssetRoot), '/assets')) {
            return dirname($normalizedAssetRoot);
        }

        return dirname($assetPath);
    }

    private function resolveClassReferenceDisplayName(mixed $classReference, string $default = 'Unknown'): string
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

    private function isEditableSpriteAsset(?array $asset): bool
    {
        if (!is_array($asset) || ($asset['isDirectory'] ?? false)) {
            return false;
        }

        $assetPath = is_string($asset['path'] ?? null)
            ? $asset['path']
            : (is_string($asset['relativePath'] ?? null) ? $asset['relativePath'] : null);

        if (!is_string($assetPath) || $assetPath === '') {
            return false;
        }

        $extension = strtolower((string) pathinfo($assetPath, PATHINFO_EXTENSION));

        return in_array($extension, ['texture', 'tmap'], true);
    }

    private function openAssetInConfiguredEditor(array $asset): bool
    {
        $assetPath = is_string($asset['path'] ?? null) ? $asset['path'] : null;

        if (!is_string($assetPath) || $assetPath === '') {
            $this->consolePanel->append('[ERROR] - Selected script path could not be resolved.');
            $this->pushNotification('Selected script path could not be resolved.', 'error');
            return false;
        }

        $command = $this->buildExternalEditorCommand($assetPath);

        if ($command === null) {
            $this->consolePanel->append('[ERROR] - No editor command found. Configure editor.externalEditor or set $VISUAL/$EDITOR.');
            $this->pushNotification('No editor command found. Configure editor.externalEditor or set $VISUAL/$EDITOR.', 'error');
            return false;
        }

        $workingDirectory = dirname($assetPath);
        $editorMode = $this->resolveExternalEditorMode($command);
        $shouldBlock = $this->shouldBlockOnExternalEditor($command, $editorMode);
        $opened = $editorMode === 'terminal'
            ? (
                $this->canUseTmuxIntegration()
                    ? $this->launchCommandInTmuxWindow(
                        $command,
                        $workingDirectory,
                        self::buildTmuxLabel((string) pathinfo($assetPath, PATHINFO_FILENAME), 'sendama-script')
                    )
                    : $this->launchForegroundExternalCommand($command, $workingDirectory)
            )
            : (
                $shouldBlock
                    ? $this->launchForegroundExternalCommand($command, $workingDirectory)
                    : $this->launchDetachedExternalCommand($command, $workingDirectory)
            );

        if ($opened) {
            $this->consolePanel->append('[INFO] - Opened script in editor: ' . ($asset['relativePath'] ?? basename($assetPath)) . '.');
            return true;
        }

        $this->consolePanel->append('[ERROR] - Failed to open script in editor.');
        $this->pushNotification('Failed to open script in editor.', 'error');

        return false;
    }

    private function buildExternalEditorCommand(string $assetPath): ?string
    {
        $configuredEditor = '';

        if (
            isset($this->settings)
            && is_string($this->settings->externalEditorCommand)
            && trim($this->settings->externalEditorCommand) !== ''
        ) {
            $configuredEditor = trim($this->settings->externalEditorCommand);
        } else {
            $configuredEditor = trim(
                (string) (
                    $_ENV['VISUAL']
                    ?? getenv('VISUAL')
                    ?? $_ENV['EDITOR']
                    ?? getenv('EDITOR')
                    ?? ''
                )
            );
        }

        if ($configuredEditor !== '') {
            return self::buildEditorCommandFromTemplate($configuredEditor, $assetPath);
        }

        $fallbackEditor = self::findFirstAvailableCommand(['vim', 'vi', 'nano', 'nvim']);

        if ($fallbackEditor === null) {
            return null;
        }

        return $fallbackEditor . ' ' . escapeshellarg($assetPath);
    }

    private function resolveExternalEditorMode(string $command): string
    {
        $configuredMode = isset($this->settings)
            ? $this->settings->externalEditorMode
            : EditorSettings::DEFAULT_EXTERNAL_EDITOR_MODE;

        if (in_array($configuredMode, ['terminal', 'gui'], true)) {
            return $configuredMode;
        }

        return self::isLikelyGuiEditorCommand($command) ? 'gui' : 'terminal';
    }

    private function shouldBlockOnExternalEditor(string $command, string $editorMode): bool
    {
        if (isset($this->settings) && is_bool($this->settings->externalEditorBlocking)) {
            return $this->settings->externalEditorBlocking;
        }

        if ($editorMode === 'terminal') {
            return true;
        }

        return preg_match('/(^|\s)(--wait|-w)(\s|$)/', $command) === 1;
    }

    private function launchForegroundExternalCommand(string $command, string $workingDirectory): bool
    {
        $this->suspendTerminalForExternalCommand();

        try {
            passthru(
                sprintf(
                    'cd %s && %s',
                    escapeshellarg($workingDirectory),
                    $command,
                ),
                $exitCode,
            );
        } finally {
            $this->resumeTerminalAfterExternalCommand();
        }

        return $exitCode === 0;
    }

    private function launchDetachedExternalCommand(string $command, string $workingDirectory): bool
    {
        exec(
            sprintf(
                'sh -lc %s >/dev/null 2>&1 &',
                escapeshellarg(sprintf(
                    'cd %s && %s',
                    escapeshellarg($workingDirectory),
                    $command,
                )),
            ),
            result_code: $exitCode,
        );

        return $exitCode === 0;
    }

    private function suspendTerminalForExternalCommand(): void
    {
        Console::disableMouseReporting();
        Console::cursor()->show();
        InputManager::disableNonBlockingMode();
        InputManager::enableEcho();
    }

    private function resumeTerminalAfterExternalCommand(): void
    {
        Console::restoreSettings();
        $this->refreshTerminalSize(force: true);
        Console::setName('Sendama Editor | ' . ($this->gameSettings?->name ?? 'Unknown Game'));
        Console::setSize($this->terminalWidth, $this->terminalHeight);
        Console::cursor()->hide();
        Console::enableMouseReporting();
        InputManager::disableEcho();
        InputManager::enableNonBlockingMode();
        $this->shouldRefreshBackgroundUnderModal = true;
    }

    private function startManagedTmuxPlayPaneIfAvailable(): bool
    {
        if (!$this->canUseTmuxIntegration()) {
            return false;
        }

        $command = $this->buildTmuxPlayCommand();

        if ($command === null) {
            $this->consolePanel->append('[WARN] - Unable to resolve the Sendama CLI entrypoint for tmux play mode.');
            return false;
        }

        $this->stopManagedTmuxPlayPane();
        $paneCommand = self::buildTmuxSplitPaneCommand($this->workingDirectory, $command);
        $output = [];
        $exitCode = 0;

        exec($paneCommand, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->consolePanel->append('[WARN] - Failed to open a tmux pane for play mode.');
            return false;
        }

        $paneId = trim(implode("\n", $output));

        if ($paneId === '') {
            $this->consolePanel->append('[WARN] - Tmux play pane started without returning a pane id.');
            return false;
        }

        $this->tmuxPlayPaneId = $paneId;
        $this->disableTmuxStatusBarForPlayPaneIfNeeded();
        $this->consolePanel->append('[INFO] - Play mode launched in tmux pane ' . $paneId . '.');

        return true;
    }

    private function stopManagedTmuxPlayPane(): void
    {
        if (is_string($this->tmuxPlayPaneId) && $this->tmuxPlayPaneId !== '' && self::isTmuxInstalled()) {
            exec(sprintf('tmux kill-pane -t %s 2>/dev/null', escapeshellarg($this->tmuxPlayPaneId)));
        }

        $this->tmuxPlayPaneId = null;
        $this->restoreTmuxStatusBarAfterPlayPane();
    }

    private function disableTmuxStatusBarForPlayPaneIfNeeded(): void
    {
        if (
            !$this->canUseTmuxIntegration()
            || ($this->gameSettings?->isDebugMode ?? false)
            || $this->tmuxPreviousStatusValue !== null
        ) {
            return;
        }

        $statusValue = trim((string) shell_exec('tmux show-options -v status 2>/dev/null'));

        if ($statusValue === '') {
            return;
        }

        $this->tmuxPreviousStatusValue = $statusValue;
        exec('tmux set-option status off 2>/dev/null');
    }

    private function restoreTmuxStatusBarAfterPlayPane(): void
    {
        if (
            !$this->canUseTmuxIntegration()
            || !is_string($this->tmuxPreviousStatusValue)
            || $this->tmuxPreviousStatusValue === ''
        ) {
            $this->tmuxPreviousStatusValue = null;
            return;
        }

        exec(sprintf(
            'tmux set-option status %s 2>/dev/null',
            escapeshellarg($this->tmuxPreviousStatusValue),
        ));
        $this->tmuxPreviousStatusValue = null;
    }

    private function buildTmuxPlayCommand(): ?string
    {
        $cliEntrypoint = $this->resolveSendamaCliEntrypoint();

        if ($cliEntrypoint === null) {
            return null;
        }

        return sprintf(
            '%s=1 %s %s play --directory %s',
            self::TMUX_GAME_CHILD_ENV_KEY,
            escapeshellarg(PHP_BINARY),
            escapeshellarg($cliEntrypoint),
            escapeshellarg($this->workingDirectory),
        );
    }

    private function resolveSendamaCliEntrypoint(): ?string
    {
        $candidates = [
            Path::join(dirname(__DIR__, 2), 'bin', 'sendama'),
        ];
        $argvEntrypoint = $_SERVER['argv'][0] ?? null;

        if (is_string($argvEntrypoint) && $argvEntrypoint !== '') {
            $candidates[] = $this->resolveAbsolutePath($argvEntrypoint, getcwd() ?: $this->workingDirectory);
        }

        foreach (array_unique($candidates) as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return Path::normalize($candidate);
            }
        }

        return null;
    }

    private function launchCommandInTmuxWindow(string $command, string $workingDirectory, string $windowName): bool
    {
        if (!$this->canUseTmuxIntegration()) {
            return false;
        }

        exec(
            self::buildTmuxNewWindowCommand($windowName, $workingDirectory, $command),
            result_code: $exitCode,
        );

        return $exitCode === 0;
    }

    private function canUseTmuxIntegration(): bool
    {
        return self::isTmuxInstalled() && $this->isInsideTmuxSession();
    }

    private function isInsideTmuxSession(): bool
    {
        $tmuxValue = getenv('TMUX');

        return is_string($tmuxValue) && trim($tmuxValue) !== '';
    }

    private static function isTmuxInstalled(): bool
    {
        $tmuxPath = shell_exec('command -v tmux 2>/dev/null');

        return is_string($tmuxPath) && trim($tmuxPath) !== '';
    }

    private static function findFirstAvailableCommand(array $commands): ?string
    {
        foreach ($commands as $command) {
            if (!is_string($command) || $command === '') {
                continue;
            }

            $resolvedCommand = shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');

            if (is_string($resolvedCommand) && trim($resolvedCommand) !== '') {
                return $command;
            }
        }

        return null;
    }

    private static function buildEditorCommandFromTemplate(string $commandTemplate, string $assetPath): string
    {
        $replacements = [
            '{path}' => escapeshellarg($assetPath),
            '{file}' => escapeshellarg($assetPath),
            '{dir}' => escapeshellarg(dirname($assetPath)),
            '{name}' => escapeshellarg(basename($assetPath)),
        ];

        foreach ($replacements as $placeholder => $replacement) {
            if (str_contains($commandTemplate, $placeholder)) {
                return strtr($commandTemplate, $replacements);
            }
        }

        return $commandTemplate . ' ' . escapeshellarg($assetPath);
    }

    private static function isLikelyGuiEditorCommand(string $command): bool
    {
        $binary = self::extractCommandBinary($command);

        if ($binary === null) {
            return false;
        }

        return in_array($binary, [
            'code',
            'code-insiders',
            'codium',
            'cursor',
            'fleet',
            'idea',
            'phpstorm',
            'pycharm',
            'webstorm',
            'goland',
            'clion',
            'rubymine',
            'zed',
            'subl',
            'sublime_text',
            'mate',
            'open',
        ], true);
    }

    private static function extractCommandBinary(string $command): ?string
    {
        $trimmedCommand = ltrim($command);

        if ($trimmedCommand === '') {
            return null;
        }

        if (!preg_match('/^([^\s]+)/', $trimmedCommand, $matches)) {
            return null;
        }

        return strtolower(basename(trim($matches[1], "'\"")));
    }

    private static function buildTmuxNewWindowCommand(string $windowName, string $workingDirectory, string $command): string
    {
        return sprintf(
            'tmux new-window -n %s -c %s %s',
            escapeshellarg($windowName),
            escapeshellarg($workingDirectory),
            escapeshellarg($command),
        );
    }

    private static function buildTmuxSplitPaneCommand(string $workingDirectory, string $command): string
    {
        return sprintf(
            'tmux split-window -v -d -P -F %s -p %d -c %s %s',
            escapeshellarg('#{pane_id}'),
            self::TMUX_PLAY_PANE_PERCENT,
            escapeshellarg($workingDirectory),
            escapeshellarg($command),
        );
    }

    private static function buildTmuxLabel(string $label, string $fallback): string
    {
        $sanitizedLabel = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($label)) ?? '';
        $sanitizedLabel = trim($sanitizedLabel, '-_');

        if ($sanitizedLabel === '') {
            return $fallback;
        }

        return substr($sanitizedLabel, 0, 30);
    }

    private function saveLoadedScene(): void
    {
        if (!$this->loadedScene instanceof DTOs\SceneDTO) {
            $this->consolePanel->append('[INFO] - No scene loaded to save.');
            $this->pushNotification('No scene loaded to save.', 'info');
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
            $this->pushNotification('Saved scene ' . $this->loadedScene->name . '.scene.php', 'success');
            return;
        }

        $this->loadedScene->isDirty = $sceneWasDirty;
        $this->syncScenePanels($sceneWasDirty);
        $this->consolePanel->append('[ERROR] - Failed to save scene.');
        $this->pushNotification('Failed to save scene.', 'error');
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
            $this->loadedScene->environmentTileMapPath = $this->normalizeEnvironmentTileMapPath(
                $value['environmentTileMapPath']
            );
        }

        if (is_string($value['environmentCollisionMapPath'] ?? null)) {
            $this->loadedScene->environmentCollisionMapPath = $this->normalizeEnvironmentCollisionMapPath(
                $value['environmentCollisionMapPath']
            );
        }

        $this->loadedScene->rawData['width'] = $this->loadedScene->width;
        $this->loadedScene->rawData['height'] = $this->loadedScene->height;
        $this->loadedScene->rawData['environmentTileMapPath'] = $this->loadedScene->environmentTileMapPath;
        $this->loadedScene->rawData['environmentCollisionMapPath'] = $this->loadedScene->environmentCollisionMapPath;

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
            'environmentCollisionMapPath' => $this->loadedScene->environmentCollisionMapPath,
        ];
    }

    private function buildSceneInspectionTarget(): array
    {
        return [
            'context' => 'scene',
            'name' => $this->loadedScene?->name ?? 'Scene',
            'type' => 'Scene',
            'path' => 'scene',
            'value' => $this->buildSceneInspectionValue(),
        ];
    }

    private function normalizeEnvironmentTileMapPath(mixed $value): string
    {
        if (!is_string($value)) {
            return 'Maps/example';
        }

        $normalizedValue = trim(str_replace('\\', '/', $value));

        if ($normalizedValue === '') {
            return 'Maps/example';
        }

        return preg_replace('/\.tmap$/i', '', $normalizedValue) ?? $normalizedValue;
    }

    private function normalizeEnvironmentCollisionMapPath(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $normalizedValue = trim(str_replace('\\', '/', $value));

        if ($normalizedValue === '') {
            return '';
        }

        return preg_replace('/\.tmap$/i', '', $normalizedValue) ?? $normalizedValue;
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
            $this->loadedScene->environmentCollisionMapPath,
        );
        $this->inspectorPanel->setSceneHierarchy($this->loadedScene->hierarchy);
        $this->mainPanel->setSceneDimensions($this->loadedScene->width, $this->loadedScene->height);
        $this->mainPanel->setEnvironmentTileMapPath($this->loadedScene->environmentTileMapPath);
    }

    private function pushNotification(string $message, string $status = 'info'): void
    {
        $this->snackbar->enqueue($message, $status);
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

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
use Sendama\Console\Editor\IO\InputManager;
use Sendama\Console\Editor\States\EditorState;
use Sendama\Console\Editor\States\EditorStateContext;
use Sendama\Console\Editor\States\EditState;
use Sendama\Console\Editor\States\ModalState;
use Sendama\Console\Editor\States\PlayState;
use Sendama\Console\Editor\States\ProjectBrowserState;
use Sendama\Console\Editor\Widgets\AssetsPanel;
use Sendama\Console\Editor\Widgets\HierarchyPanel;
use Sendama\Console\Editor\Widgets\InspectorPanel;
use Sendama\Console\Editor\Widgets\Widget;
use Sendama\Console\Exceptions\IOException;
use Sendama\Console\Exceptions\SendamaConsoleException;
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
    protected InspectorPanel $inspectorPanel;

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
            $this->initializeManagers();
            $this->initializeConsole();
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

        Console::setSize($this->gameSettings?->width, $this->gameSettings?->height);

        Console::cursor()->hide();

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

        Console::cursor()->show();

        $this->removeObservers(...$this->observers, ...$this->staticObservers);

        $this->isRunning = false;

        $this->notify(new EditorEvent(EventType::EDITOR_STOPPED->value, $this));

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
                'assets' => $this->assetsPanel
            ]
        );

        $this->editorState?->exit($context);
        $this->editorState = $editorState;
        $this->editorState->enter($context);
    }

    /**
     * Handle editor input
     *
     * @return void
     */
    private function handleInput(): void
    {
        InputManager::handleInput();

        $this->notify(new EditorEvent(EventType::EDITOR_INPUT_HANDLED->value, $this));
    }

    /**
     * Update the editor state.
     *
     * @return void
     */
    private function update(): void
    {
        $this->editorState->update();

        foreach ($this->panels as $panel) {
            $panel->update();
        }

        $this->notify(new EditorEvent(EventType::EDITOR_UPDATED->value, $this));
    }

    private function render(): void
    {
        $this->frameCount++;
        $this->editorState->render();
        foreach ($this->panels as $panel) {
            $panel->render();
        }
        $this->renderDebugInfo();

        $this->notify(new EditorEvent(EventType::EDITOR_RENDERED->value, $this));
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
            "width" => $this->gameSettings?->width ?? DEFAULT_TERMINAL_WIDTH,
            "height" => $this->gameSettings?->height ?? DEFAULT_TERMINAL_HEIGHT,
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

    /**
     * @return void
     * @throws SendamaConsoleException
     */
    private function initializeSettings(): void
    {
        $this->settings = EditorSettings::loadFromDirectory($this->workingDirectory);
        $this->gameSettings = GameSettings::loadFromDirectory($this->workingDirectory);
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
        $halfHeight = (int)(($this->settings->height - 1) / 2);
        $this->hierarchyPanel = new HierarchyPanel(height: $halfHeight);
        $this->assetsPanel = new AssetsPanel(position: ['x' => 1, 'y' => $halfHeight + 1], height: $halfHeight);
        $centralPanelWidth = $this->settings->width - 2 - (35 * 2);

        $this->inspectorPanel = new InspectorPanel(position: ['x' => ($centralPanelWidth + 35), 'y' => 1], height: $this->settings->height - 1);

        $this->panels->add($this->hierarchyPanel);
        $this->panels->add($this->assetsPanel);
        $this->panels->add($this->inspectorPanel);
    }
}
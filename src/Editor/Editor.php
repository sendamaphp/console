<?php

namespace Sendama\Console\Editor;

use Atatusoft\Termutil\Events\Event;
use Atatusoft\Termutil\Events\Interfaces\ObservableInterface;
use Atatusoft\Termutil\Events\Interfaces\StaticObserverInterface;
use Atatusoft\Termutil\Events\Traits\ObservableTrait;
use Atatusoft\Termutil\IO\Console\Console;
use Atatusoft\Termutil\UI\Windows\Window;
use Sendama\Console\Debug\Debug;
use Sendama\Console\Editor\Interfaces\EditorStateInterface;
use Sendama\Console\Editor\States\EditorState;
use Sendama\Console\Editor\States\EditorStateContext;
use Sendama\Console\Editor\States\EditState;
use Sendama\Console\Editor\States\ModalState;
use Sendama\Console\Editor\States\PlayState;
use Sendama\Console\Editor\States\ProjectBrowserState;
use Throwable;

class Editor implements ObservableInterface
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

    protected bool $isDebugMode {
        get {
            return $this->settings?->isDebugMode;
        }
    }
    protected ?GameSettings $settings = null;
    private bool $showDebugInfo = false;
    private Window $debugWindow;
    protected ProjectBrowserState $projectBrowserState;
    protected EditState $editState;
    protected PlayState $playState;
    protected ModalState $modalState;

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
            $this->initializeObservers();
            $this->configureErrorAndExceptionHandlers();
            $this->initializeConsole();
            $this->initializeEditorStates();
        } catch (Throwable $exception) {
            $this->handleException($exception);
        }
    }

    /**
     * Sets the working directory for the editor. If the editor is currently running, it will be
     * stopped and restarted after setting the working directory.
     *
     * @param string $directory The working directory to set for the editor.
     * @return $this
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

    public function start(): void
    {
        Debug::info("Starting editor");

        Console::saveSettings();

        Console::setName($this->settings?->name ?? "Sendama Editor | Unknown Game");

        $this->isRunning = true;
    }

    public function stop(): void
    {
        $this->isRunning = false;
    }

    public function finish(): void
    {
        // TODO: Implement finish() method.
    }

    public function run(): void
    {
        $sleepTime =(int)(1000000 / self::FPS);
        $this->start();
        $nextFrameTime = microtime(true) + 1;
        $lastFrameCountSnapShot = $this->frameCount;

        Debug::info("Running editor");
        while (!$this->isRunning) {
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

        $this->finish();
    }

    public function setState(EditorStateInterface $editorState): void
    {
        $context = new EditorStateContext($this->settings);

        $this->editorState?->exit($context);
        $this->editorState = $editorState;
        $this->editorState->enter($context);
    }

    private function handleInput(): void
    {
    }

    private function update(): void
    {
        $this->editorState->update();
    }

    private function render(): void
    {
        $this->frameCount++;
        $this->editorState->render();
        $this->renderDebugInfo();
    }

    private function renderDebugInfo(): void
    {
        if ($this->isDebugMode && $this->showDebugInfo) {

        }
    }

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
        $this->stop();

        if ($this->settings->isDebugMode) {
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
        $this->stop();

        if ($this->settings->isDebugMode) {
            exit($errorMessage);
        }

        exit($errno);
    }

    protected function initializeConsole(): void
    {
        Console::init([
            "width" => $this->settings->width ?? DEFAULT_TERMINAL_WIDTH,
            "height" => $this->settings->height ?? DEFAULT_TERMINAL_HEIGHT,
        ]);
    }

    private function initializeEditorStates(): void
    {
        $this->projectBrowserState = new ProjectBrowserState($this);
        $this->editState = new EditState($this);
        $this->playState = new PlayState($this);
        $this->modalState = new ModalState($this);

        $this->setState($this->projectBrowserState);
    }
}
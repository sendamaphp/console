<?php

use Atatusoft\Termutil\Events\MouseEvent;
use Atatusoft\Termutil\IO\Enumerations\Color;
use Sendama\Console\Editor\IO\InputManager;
use Sendama\Console\Editor\Widgets\MainPanel;
use Sendama\Console\Editor\Widgets\Widget;

function pressMainPanelKey(string $keyPress): void
{
    $currentKeyPress = new ReflectionProperty(InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(InputManager::class, 'previousKeyPress');
    $currentKeyPress->setAccessible(true);
    $previousKeyPress->setAccessible(true);
    $previousKeyPress->setValue('');
    $currentKeyPress->setValue($keyPress);
}

function getMainPanelContentAreaPosition(MainPanel $panel): array
{
    $getContentAreaLeft = new ReflectionMethod($panel, 'getContentAreaLeft');
    $getContentAreaTop = new ReflectionMethod($panel, 'getContentAreaTop');

    return [
        'x' => $getContentAreaLeft->invoke($panel),
        'y' => $getContentAreaTop->invoke($panel),
    ];
}

function createMainPanelWorkspace(): string
{
    $workspace = sys_get_temp_dir() . '/sendama-main-panel-' . uniqid();
    mkdir($workspace . '/Assets/Textures', 0777, true);
    mkdir($workspace . '/Assets/Maps', 0777, true);
    file_put_contents($workspace . '/Assets/Textures/player.texture', "abcd\nefgh\nijkl\n");
    file_put_contents($workspace . '/Assets/Textures/enemy.texture', "QRST\nUVWX\nYZ12\n");
    file_put_contents($workspace . '/Assets/Maps/level.tmap', "xxxxx\nx   x\nxxxxx\n");

    return $workspace;
}

function setMainPanelMouseEvent(?MouseEvent $event): void
{
    $mouseEvent = new ReflectionProperty(InputManager::class, 'mouseEvent');
    $mouseEvent->setAccessible(true);
    $mouseEvent->setValue($event);
}

test('main panel cycles forward through tabs', function () {
    $panel = new MainPanel(width: 60, height: 12);

    expect($panel->getActiveTab())->toBe('Scene');

    $panel->activateNextTab();
    expect($panel->getActiveTab())->toBe('Game');

    $panel->activateNextTab();
    expect($panel->getActiveTab())->toBe('Sprite');

    $panel->activateNextTab();
    expect($panel->getActiveTab())->toBe('Scene');
});

test('main panel cycles backward through tabs', function () {
    $panel = new MainPanel(width: 60, height: 12);

    $panel->activatePreviousTab();

    expect($panel->getActiveTab())->toBe('Sprite');
});

test('main panel uses focus cycling to move between tabs', function () {
    $panel = new MainPanel(width: 60, height: 12);

    expect($panel->cycleFocusForward())->toBeTrue();
    expect($panel->getActiveTab())->toBe('Game');

    expect($panel->cycleFocusBackward())->toBeTrue();
    expect($panel->getActiveTab())->toBe('Scene');
});

test('main panel shows a play prompt on the game tab while not in play mode', function () {
    $panel = new MainPanel(width: 60, height: 12);

    $panel->selectTab('Game');

    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'Shift+5 to Play')))->toBeTrue();
});

test('main panel hides the play prompt while play mode is active', function () {
    $panel = new MainPanel(width: 60, height: 12);

    $panel->selectTab('Game');
    $panel->setPlayModeActive(true);

    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'Shift+5 to Play')))->toBeFalse();
    expect($panel->content)->toHaveCount(2);
});

test('main panel uses a warm focus color while in play mode', function () {
    $panel = new MainPanel(width: 60, height: 12);
    $focusBorderColor = new ReflectionProperty(Widget::class, 'focusBorderColor');
    $focusBorderColor->setAccessible(true);

    expect($focusBorderColor->getValue($panel))->toBe(Color::LIGHT_CYAN);

    $panel->setPlayModeActive(true);

    expect($focusBorderColor->getValue($panel))->toBe(Color::BROWN);
});

test('main panel highlights the active tab in the divider', function () {
    $panel = new MainPanel(width: 60, height: 12);

    $panel->selectTab('Sprite');

    expect($panel->content[0])->toContain('Scene  Game  Sprite');
    expect($panel->content[1])->toContain('■■■■■■');
    expect(mb_strlen($panel->content[1]))->toBe($panel->innerWidth - 2);
});

test('main panel renders scene objects at their positions on the scene tab', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(
        width: 40,
        height: 12,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'position' => ['x' => 2, 'y' => 0],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/player',
                        'position' => ['x' => 1, 'y' => 1],
                        'size' => ['x' => 2, 'y' => 2],
                    ],
                ],
            ],
            [
                'type' => 'Sendama\\Engine\\UI\\Label\\Label',
                'name' => 'Score',
                'position' => ['x' => 4, 'y' => 3],
                'text' => 'Score: 000',
            ],
        ],
        workingDirectory: $workspace,
    );

    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'fg')))->toBeTrue();
    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'jk')))->toBeTrue();
    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'Score: 000')))->toBeTrue();
});

test('main panel renders the environment tile map behind scene objects', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(
        width: 24,
        height: 10,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'position' => ['x' => 2, 'y' => 1],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/player',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
        ],
        workingDirectory: $workspace,
        environmentTileMapPath: 'Maps/level',
    );

    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'xxxxx')))->toBeTrue();
    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'x a x')))->toBeTrue();
});

test('main panel preserves scene row width when a sprite uses a wide multibyte glyph', function () {
    $workspace = createMainPanelWorkspace();
    file_put_contents($workspace . '/Assets/Textures/enemy.texture', "👾\n");

    $panel = new MainPanel(
        width: 24,
        height: 10,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Enemy',
                'position' => ['x' => 2, 'y' => 1],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/enemy',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
        ],
        workingDirectory: $workspace,
        environmentTileMapPath: 'Maps/level',
    );

    $buildSceneCanvasContent = new ReflectionMethod(MainPanel::class, 'buildSceneCanvasContent');
    $buildSceneCanvasContent->setAccessible(true);
    $sceneRows = $buildSceneCanvasContent->invoke($panel);

    expect($sceneRows[1])->toContain('👾');
    expect(mb_strwidth($sceneRows[1], 'UTF-8'))->toBe(mb_strlen($sceneRows[1]) + 1);
    expect(rtrim($sceneRows[1]))->toEndWith('x');

    $buildRenderedContentLines = new ReflectionMethod($panel, 'buildRenderedContentLines');
    $buildRenderedContentLines->setAccessible(true);
    $renderedLines = $buildRenderedContentLines->invoke($panel);

    expect(mb_strwidth($renderedLines[3], 'UTF-8'))->toBe(24);
    expect(mb_substr($renderedLines[3], -1))->toBe('│');
});

test('main panel scene selection highlight stays aligned for wide multibyte glyphs', function () {
    $workspace = createMainPanelWorkspace();
    file_put_contents($workspace . '/Assets/Textures/enemy.texture', "👾\n");

    $panel = new MainPanel(
        width: 24,
        height: 10,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Enemy',
                'position' => ['x' => 2, 'y' => 1],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/enemy',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
        ],
        workingDirectory: $workspace,
    );

    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $selectedScenePath = new ReflectionProperty(MainPanel::class, 'selectedScenePath');
    $decorateSceneLine = new ReflectionMethod(MainPanel::class, 'decorateSceneLine');
    $buildRenderedContentLines = new ReflectionMethod($panel, 'buildRenderedContentLines');
    $highlightSequence = (new ReflectionClass(MainPanel::class))
        ->getReflectionConstant('SCENE_SELECTION_FOCUSED_SEQUENCE')
        ?->getValue();
    $hasFocus->setAccessible(true);
    $selectedScenePath->setAccessible(true);
    $decorateSceneLine->setAccessible(true);
    $buildRenderedContentLines->setAccessible(true);

    $hasFocus->setValue($panel, true);
    $selectedScenePath->setValue($panel, 'scene.0');
    $panel->selectTab('Scene');
    $renderedLines = $buildRenderedContentLines->invoke($panel);
    $decoratedLine = $decorateSceneLine->invoke($panel, $renderedLines[3], null, 2);

    expect(substr_count($decoratedLine, '👾'))->toBe(1);
    expect(is_string($highlightSequence))->toBeTrue();
    expect(substr_count($decoratedLine, $highlightSequence))->toBe(1);
    expect(substr_count($decoratedLine, $highlightSequence . ' '))->toBe(0);
});

test('main panel selects a scene object when it is clicked in scene view', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(
        width: 40,
        height: 12,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'position' => ['x' => 2, 'y' => 1],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/player',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
        ],
        workingDirectory: $workspace,
    );

    $contentArea = getMainPanelContentAreaPosition($panel);
    $panel->handleMouseClick($contentArea['x'] + 2, $contentArea['y'] + 3);

    expect($panel->consumeInspectionRequest())->toBe([
        'context' => 'hierarchy',
        'name' => 'Player',
        'type' => 'GameObject',
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Player',
            'position' => ['x' => 2, 'y' => 1],
            'sprite' => [
                'texture' => [
                    'path' => 'Textures/player',
                    'position' => ['x' => 0, 'y' => 0],
                    'size' => ['x' => 1, 'y' => 1],
                ],
            ],
        ],
    ]);
});

test('main panel can select a different scene object when it is clicked in scene view', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(
        width: 40,
        height: 12,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'position' => ['x' => 2, 'y' => 1],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/player',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Enemy',
                'position' => ['x' => 8, 'y' => 1],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/enemy',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
        ],
        workingDirectory: $workspace,
    );

    $contentArea = getMainPanelContentAreaPosition($panel);
    $panel->handleMouseClick($contentArea['x'] + 8, $contentArea['y'] + 3);

    expect($panel->consumeInspectionRequest())->toBe([
        'context' => 'hierarchy',
        'name' => 'Enemy',
        'type' => 'GameObject',
        'path' => 'scene.1',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Enemy',
            'position' => ['x' => 8, 'y' => 1],
            'sprite' => [
                'texture' => [
                    'path' => 'Textures/enemy',
                    'position' => ['x' => 0, 'y' => 0],
                    'size' => ['x' => 1, 'y' => 1],
                ],
            ],
        ],
    ]);
});

test('main panel resolves scene textures from the configured project directory', function () {
    $workspace = createMainPanelWorkspace();
    $originalWorkingDirectory = getcwd();
    $panel = new MainPanel(
        width: 40,
        height: 12,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'position' => ['x' => 1, 'y' => 0],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/player',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 2, 'y' => 2],
                    ],
                ],
            ],
        ],
        workingDirectory: $workspace,
    );

    chdir(sys_get_temp_dir());

    try {
        expect(array_any($panel->content, fn(string $line) => str_contains($line, 'ab')))->toBeTrue();
        expect(array_any($panel->content, fn(string $line) => str_contains($line, 'ef')))->toBeTrue();
    } finally {
        if ($originalWorkingDirectory !== false) {
            chdir($originalWorkingDirectory);
        }
    }
});

test('main panel select mode loads the selected scene object into the inspector payload', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(
        width: 40,
        height: 12,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'position' => ['x' => 2, 'y' => 0],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/player',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Enemy',
                'position' => ['x' => 10, 'y' => 4],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/enemy',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
        ],
        workingDirectory: $workspace,
    );
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    pressMainPanelKey('Q');
    $panel->update();

    pressMainPanelKey("\033[B");
    $panel->update();

    pressMainPanelKey("\n");
    $panel->update();

    expect($panel->consumeInspectionRequest())->toBe([
        'context' => 'hierarchy',
        'name' => 'Enemy',
        'type' => 'GameObject',
        'path' => 'scene.1',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Enemy',
            'position' => ['x' => 10, 'y' => 4],
            'sprite' => [
                'texture' => [
                    'path' => 'Textures/enemy',
                    'position' => ['x' => 0, 'y' => 0],
                    'size' => ['x' => 1, 'y' => 1],
                ],
            ],
        ],
    ]);
});

test('main panel select mode emits an inspection payload as scene selection changes', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(
        width: 40,
        height: 12,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'position' => ['x' => 2, 'y' => 0],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/player',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Enemy',
                'position' => ['x' => 10, 'y' => 4],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/enemy',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
        ],
        workingDirectory: $workspace,
    );
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    pressMainPanelKey('Q');
    $panel->update();

    pressMainPanelKey("\033[B");
    $panel->update();

    expect($panel->consumeInspectionRequest())->toBe([
        'context' => 'hierarchy',
        'name' => 'Enemy',
        'type' => 'GameObject',
        'path' => 'scene.1',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Enemy',
            'position' => ['x' => 10, 'y' => 4],
            'sprite' => [
                'texture' => [
                    'path' => 'Textures/enemy',
                    'position' => ['x' => 0, 'y' => 0],
                    'size' => ['x' => 1, 'y' => 1],
                ],
            ],
        ],
    ]);
});

test('main panel shows a muted marker for selected non-renderable scene objects', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(
        width: 40,
        height: 12,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Game Manager',
                'position' => ['x' => 3, 'y' => 2],
            ],
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'position' => ['x' => 10, 'y' => 4],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/player',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
        ],
        workingDirectory: $workspace,
    );
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    pressMainPanelKey('Q');
    $panel->update();

    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'x')))->toBeTrue();
});

test('main panel restores selected renderable scene objects to their normal visibility on blur', function () {
    $workspace = createMainPanelWorkspace();
    $panelWidth = 40;
    $panel = new MainPanel(
        width: $panelWidth,
        height: 12,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'position' => ['x' => 2, 'y' => 1],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/player',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
        ],
        workingDirectory: $workspace,
    );
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $decorateSceneLine = new ReflectionMethod(MainPanel::class, 'decorateSceneLine');
    $decorateSceneLine->setAccessible(true);
    $refreshContent = new ReflectionMethod(MainPanel::class, 'refreshContent');
    $refreshContent->setAccessible(true);

    $hasFocus->setValue($panel, true);
    $refreshContent->invoke($panel);

    $focusedLine = '|' . str_pad($panel->content[3], $panelWidth - 2) . '|';
    $focusedRenderedLine = $decorateSceneLine->invoke($panel, $focusedLine, null, 3);

    expect($focusedRenderedLine)->toContain("\033[5;30;46m");

    $hasFocus->setValue($panel, false);
    $refreshContent->invoke($panel);

    $blurredLine = '|' . str_pad($panel->content[3], $panelWidth - 2) . '|';
    $blurredRenderedLine = $decorateSceneLine->invoke($panel, $blurredLine, null, 3);

    expect($blurredRenderedLine)->not->toContain("\033[5;30;46m");
    expect($blurredRenderedLine)->not->toContain("\033[30;46m");
});

test('main panel hides the selected placeholder marker on blur for non-renderable scene objects', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(
        width: 40,
        height: 12,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Game Manager',
                'position' => ['x' => 3, 'y' => 2],
            ],
        ],
        workingDirectory: $workspace,
    );
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $refreshContent = new ReflectionMethod(MainPanel::class, 'refreshContent');
    $refreshContent->setAccessible(true);

    $hasFocus->setValue($panel, true);
    pressMainPanelKey('Q');
    $panel->update();

    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'x')))->toBeTrue();

    $hasFocus->setValue($panel, false);
    $refreshContent->invoke($panel);

    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'x')))->toBeFalse();
});

test('main panel hides the selected placeholder marker on blur when the renderer is disabled', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(
        width: 40,
        height: 12,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'position' => ['x' => 3, 'y' => 2],
                'renderer' => ['enabled' => false],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/player',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
        ],
        workingDirectory: $workspace,
    );
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $refreshContent = new ReflectionMethod(MainPanel::class, 'refreshContent');
    $refreshContent->setAccessible(true);

    $hasFocus->setValue($panel, true);
    pressMainPanelKey('Q');
    $panel->update();

    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'x')))->toBeTrue();

    $hasFocus->setValue($panel, false);
    $refreshContent->invoke($panel);

    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'x')))->toBeFalse();
});

test('main panel move mode updates the selected scene object position', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(
        width: 40,
        height: 12,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'position' => ['x' => 2, 'y' => 1],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/player',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
        ],
        workingDirectory: $workspace,
    );
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    pressMainPanelKey('W');
    $panel->update();

    pressMainPanelKey("\033[C");
    $panel->update();

    expect($panel->consumeHierarchyMutation())->toBe([
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Player',
            'position' => ['x' => 3, 'y' => 1],
            'sprite' => [
                'texture' => [
                    'path' => 'Textures/player',
                    'position' => ['x' => 0, 'y' => 0],
                    'size' => ['x' => 1, 'y' => 1],
                ],
            ],
        ],
    ]);
    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'a')))->toBeTrue();
});

test('main panel move mode emits an updated inspection payload for the selected object', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(
        width: 40,
        height: 12,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'position' => ['x' => 2, 'y' => 1],
                'rotation' => ['x' => 0, 'y' => 0],
                'scale' => ['x' => 1, 'y' => 1],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/player',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
        ],
        workingDirectory: $workspace,
    );
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    pressMainPanelKey('W');
    $panel->update();

    expect($panel->consumeInspectionRequest())->toBe([
        'context' => 'hierarchy',
        'name' => 'Player',
        'type' => 'GameObject',
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Player',
            'position' => ['x' => 2, 'y' => 1],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'sprite' => [
                'texture' => [
                    'path' => 'Textures/player',
                    'position' => ['x' => 0, 'y' => 0],
                    'size' => ['x' => 1, 'y' => 1],
                ],
            ],
        ],
    ]);

    pressMainPanelKey("\033[C");
    $panel->update();

    expect($panel->consumeInspectionRequest())->toBe([
        'context' => 'hierarchy',
        'name' => 'Player',
        'type' => 'GameObject',
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Player',
            'position' => ['x' => 3, 'y' => 1],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'sprite' => [
                'texture' => [
                    'path' => 'Textures/player',
                    'position' => ['x' => 0, 'y' => 0],
                    'size' => ['x' => 1, 'y' => 1],
                ],
            ],
        ],
    ]);
});

test('main panel move mode continues moving when repeated direction input is held', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(
        width: 40,
        height: 12,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'position' => ['x' => 2, 'y' => 1],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/player',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
        ],
        workingDirectory: $workspace,
    );
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    pressMainPanelKey('W');
    $panel->update();

    $keyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'previousKeyPress');
    $keyPress->setAccessible(true);
    $previousKeyPress->setAccessible(true);

    $previousKeyPress->setValue("\033[C");
    $keyPress->setValue("\033[C");
    $panel->update();

    expect($panel->consumeHierarchyMutation())->toBe([
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Player',
            'position' => ['x' => 3, 'y' => 1],
            'sprite' => [
                'texture' => [
                    'path' => 'Textures/player',
                    'position' => ['x' => 0, 'y' => 0],
                    'size' => ['x' => 1, 'y' => 1],
                ],
            ],
        ],
    ]);
});

test('main panel pan mode scrolls the scene viewport', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(
        width: 16,
        height: 8,
        sceneObjects: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Enemy',
                'position' => ['x' => 16, 'y' => 1],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/enemy',
                        'position' => ['x' => 0, 'y' => 0],
                        'size' => ['x' => 1, 'y' => 1],
                    ],
                ],
            ],
        ],
        workingDirectory: $workspace,
        sceneWidth: 30,
        sceneHeight: 10,
    );
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'Q')))->toBeFalse();

    pressMainPanelKey('E');
    $panel->update();

    for ($index = 0; $index < 8; $index++) {
        pressMainPanelKey("\033[C");
        $panel->update();
    }

    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'Q')))->toBeTrue();
});

test('main panel help line shows controls on the left and the active mode on the right', function () {
    $panel = new MainPanel(width: 72, height: 10);
    $buildBorderLine = new ReflectionMethod(MainPanel::class, 'buildBorderLine');
    $buildBorderLine->setAccessible(true);

    $selectHelpLine = $buildBorderLine->invoke($panel, '', false);

    expect($selectHelpLine)->toContain('Arrows cycle');
    expect($selectHelpLine)->toContain('Mode: Scene Select');

    pressMainPanelKey('E');
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);
    $panel->update();

    $panHelpLine = $buildBorderLine->invoke($panel, '', false);

    expect($panHelpLine)->toContain('Arrows pan');
    expect($panHelpLine)->toContain('Mode: Scene Pan');
});

test('main panel sprite tab edits the selected asset grid and persists it', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(width: 30, height: 12, workingDirectory: $workspace);
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    $panel->selectTab('Sprite');
    $panel->loadSpriteAsset([
        'name' => 'player.texture',
        'path' => $workspace . '/Assets/Textures/player.texture',
        'relativePath' => 'Textures/player.texture',
        'isDirectory' => false,
    ]);

    pressMainPanelKey('Z');
    $panel->update();

    expect(file_get_contents($workspace . '/Assets/Textures/player.texture'))->toStartWith("Zbcd\n");
    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'Zbcd')))->toBeTrue();
});

test('main panel sprite tab paints with mouse click and drag', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(width: 30, height: 12, workingDirectory: $workspace);
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $lastPrintedSpriteCharacter = new ReflectionProperty(MainPanel::class, 'lastPrintedSpriteCharacter');
    $hasFocus->setAccessible(true);
    $lastPrintedSpriteCharacter->setAccessible(true);
    $hasFocus->setValue($panel, true);

    $panel->selectTab('Sprite');
    $panel->loadSpriteAsset([
        'name' => 'player.texture',
        'path' => $workspace . '/Assets/Textures/player.texture',
        'relativePath' => 'Textures/player.texture',
        'isDirectory' => false,
    ]);
    $lastPrintedSpriteCharacter->setValue($panel, 'Z');

    $contentArea = getMainPanelContentAreaPosition($panel);
    $panel->handleMouseClick($contentArea['x'] + 1, $contentArea['y'] + 3);
    $panel->handleMouseDrag($contentArea['x'] + 2, $contentArea['y'] + 3);
    $panel->handleMouseRelease($contentArea['x'] + 2, $contentArea['y'] + 3);

    expect(file_get_contents($workspace . '/Assets/Textures/player.texture'))->toContain('eZZh');
});

test('main panel sprite tab erases with right click without changing the active brush', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(width: 30, height: 12, workingDirectory: $workspace);
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $lastPrintedSpriteCharacter = new ReflectionProperty(MainPanel::class, 'lastPrintedSpriteCharacter');
    $hasFocus->setAccessible(true);
    $lastPrintedSpriteCharacter->setAccessible(true);
    $hasFocus->setValue($panel, true);

    $panel->selectTab('Sprite');
    $panel->loadSpriteAsset([
        'name' => 'player.texture',
        'path' => $workspace . '/Assets/Textures/player.texture',
        'relativePath' => 'Textures/player.texture',
        'isDirectory' => false,
    ]);
    $lastPrintedSpriteCharacter->setValue($panel, 'Z');

    $contentArea = getMainPanelContentAreaPosition($panel);
    setMainPanelMouseEvent(new MouseEvent("\033[<2;" . ($contentArea['x'] + 1) . ';' . ($contentArea['y'] + 3) . 'M'));
    $panel->handleMouseClick($contentArea['x'] + 1, $contentArea['y'] + 3);
    $panel->handleMouseDrag($contentArea['x'] + 2, $contentArea['y'] + 3);
    $panel->handleMouseRelease($contentArea['x'] + 2, $contentArea['y'] + 3);

    setMainPanelMouseEvent(new MouseEvent("\033[<0;" . ($contentArea['x'] + 3) . ';' . ($contentArea['y'] + 3) . 'M'));
    $panel->handleMouseClick($contentArea['x'] + 3, $contentArea['y'] + 3);
    setMainPanelMouseEvent(null);

    expect(file_get_contents($workspace . '/Assets/Textures/player.texture'))->toContain('e  Z');
});

test('main panel sprite tab expands loaded textures to a 16x16 editing grid', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(width: 30, height: 12, workingDirectory: $workspace);
    $spriteGridWidth = new ReflectionProperty(MainPanel::class, 'spriteGridWidth');
    $spriteGridHeight = new ReflectionProperty(MainPanel::class, 'spriteGridHeight');
    $spriteGridWidth->setAccessible(true);
    $spriteGridHeight->setAccessible(true);

    $panel->selectTab('Sprite');
    $panel->loadSpriteAsset([
        'name' => 'player.texture',
        'path' => $workspace . '/Assets/Textures/player.texture',
        'relativePath' => 'Textures/player.texture',
        'isDirectory' => false,
    ]);

    expect($spriteGridWidth->getValue($panel))->toBe(16);
    expect($spriteGridHeight->getValue($panel))->toBe(16);
});

test('main panel sprite tab expands loaded tile maps to the current terminal-size bounds', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(width: 30, height: 12, workingDirectory: $workspace);
    $spriteGridWidth = new ReflectionProperty(MainPanel::class, 'spriteGridWidth');
    $spriteGridHeight = new ReflectionProperty(MainPanel::class, 'spriteGridHeight');
    $spriteGridWidth->setAccessible(true);
    $spriteGridHeight->setAccessible(true);

    $panel->selectTab('Sprite');
    $panel->loadSpriteAsset([
        'name' => 'level.tmap',
        'path' => $workspace . '/Assets/Maps/level.tmap',
        'relativePath' => 'Maps/level.tmap',
        'isDirectory' => false,
    ]);

    $terminalSize = get_max_terminal_size();
    $expectedWidth = max(1, (int) ($terminalSize['width'] ?? DEFAULT_TERMINAL_WIDTH));
    $expectedHeight = max(1, (int) ($terminalSize['height'] ?? DEFAULT_TERMINAL_HEIGHT));

    expect($spriteGridWidth->getValue($panel))->toBe($expectedWidth);
    expect($spriteGridHeight->getValue($panel))->toBe($expectedHeight);
});

test('main panel sprite create workflow can create a new texture asset', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(width: 30, height: 12, workingDirectory: $workspace);
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    $panel->selectTab('Sprite');
    expect($panel->beginSpriteCreateWorkflow())->toBeTrue();

    pressMainPanelKey("\n");
    $panel->update();

    $assetSyncRequest = $panel->consumeAssetSyncRequest();

    expect($assetSyncRequest)->toBeArray();
    expect($assetSyncRequest['path'] ?? null)->toBeString();
    expect($assetSyncRequest['path'])->toEndWith('.texture');
    expect(file_exists($assetSyncRequest['path']))->toBeTrue();
    expect($assetSyncRequest['inspectionTarget']['value']['relativePath'] ?? null)->toBe('Textures/new-texture-1.texture');
});

test('main panel sprite create workflow creates tile maps at the current terminal-size bounds', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(width: 30, height: 12, workingDirectory: $workspace);
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    $panel->selectTab('Sprite');
    expect($panel->beginSpriteCreateWorkflow())->toBeTrue();

    pressMainPanelKey("\033[B");
    $panel->update();

    pressMainPanelKey("\n");
    $panel->update();

    $assetSyncRequest = $panel->consumeAssetSyncRequest();
    $terminalSize = get_max_terminal_size();
    $expectedWidth = max(1, (int) ($terminalSize['width'] ?? DEFAULT_TERMINAL_WIDTH));
    $expectedHeight = max(1, (int) ($terminalSize['height'] ?? DEFAULT_TERMINAL_HEIGHT));
    $lines = explode("\n", rtrim((string) file_get_contents($assetSyncRequest['path']), "\n"));

    expect($assetSyncRequest['path'])->toEndWith('.tmap');
    expect(count($lines))->toBe($expectedHeight);
    expect(mb_strlen($lines[0] ?? ''))->toBe($expectedWidth);
});

test('main panel sprite tab ignores shift+a during normal focused input', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(width: 30, height: 12, workingDirectory: $workspace);
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    $panel->selectTab('Sprite');

    pressMainPanelKey('A');
    $panel->update();

    expect($panel->hasActiveModal())->toBeFalse();
    expect($panel->consumeAssetSyncRequest())->toBeNull();
});

test('main panel sprite tab can delete the active asset after confirmation', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(width: 30, height: 12, workingDirectory: $workspace);
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    $panel->selectTab('Sprite');
    $panel->loadSpriteAsset([
        'name' => 'player.texture',
        'path' => $workspace . '/Assets/Textures/player.texture',
        'relativePath' => 'Textures/player.texture',
        'isDirectory' => false,
    ]);

    pressMainPanelKey("\033[3~");
    $panel->update();

    pressMainPanelKey("\033[A");
    $panel->update();

    pressMainPanelKey("\n");
    $panel->update();

    $assetSyncRequest = $panel->consumeAssetSyncRequest();

    expect(file_exists($workspace . '/Assets/Textures/player.texture'))->toBeFalse();
    expect($assetSyncRequest)->toBe([
        'path' => $workspace . '/Assets/Textures/player.texture',
        'clearInspection' => true,
    ]);
    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'Select a .texture or .tmap asset')))->toBeTrue();
});

test('main panel sprite tab supports undo redo and reset', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(width: 30, height: 12, workingDirectory: $workspace);
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    $panel->selectTab('Sprite');
    $panel->loadSpriteAsset([
        'name' => 'player.texture',
        'path' => $workspace . '/Assets/Textures/player.texture',
        'relativePath' => 'Textures/player.texture',
        'isDirectory' => false,
    ]);

    pressMainPanelKey('Z');
    $panel->update();

    expect(file_get_contents($workspace . '/Assets/Textures/player.texture'))->toStartWith("Zbcd\n");

    pressMainPanelKey("\x1A");
    $panel->update();

    expect(file_get_contents($workspace . '/Assets/Textures/player.texture'))->toStartWith("abcd\n");

    pressMainPanelKey("\x19");
    $panel->update();

    expect(file_get_contents($workspace . '/Assets/Textures/player.texture'))->toStartWith("Zbcd\n");

    pressMainPanelKey('R');
    $panel->update();

    expect(file_get_contents($workspace . '/Assets/Textures/player.texture'))->toStartWith("abcd\n");
});

test('main panel sprite tab can insert a special character from the character picker modal', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(width: 30, height: 12, workingDirectory: $workspace);
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    $panel->selectTab('Sprite');
    $panel->loadSpriteAsset([
        'name' => 'player.texture',
        'path' => $workspace . '/Assets/Textures/player.texture',
        'relativePath' => 'Textures/player.texture',
        'isDirectory' => false,
    ]);

    pressMainPanelKey('@');
    $panel->update();

    expect($panel->hasActiveModal())->toBeTrue();

    pressMainPanelKey("\n");
    $panel->update();

    expect($panel->hasActiveModal())->toBeFalse();
    expect(file_get_contents($workspace . '/Assets/Textures/player.texture'))->toStartWith("█bcd\n");
});

test('main panel sprite tab repeats the last printed special character when enter is pressed', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(width: 30, height: 12, workingDirectory: $workspace);
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    $panel->selectTab('Sprite');
    $panel->loadSpriteAsset([
        'name' => 'player.texture',
        'path' => $workspace . '/Assets/Textures/player.texture',
        'relativePath' => 'Textures/player.texture',
        'isDirectory' => false,
    ]);

    pressMainPanelKey('@');
    $panel->update();

    pressMainPanelKey("\n");
    $panel->update();

    pressMainPanelKey("\n");
    $panel->update();

    expect(file_get_contents($workspace . '/Assets/Textures/player.texture'))->toStartWith("██cd\n");
});

test('main panel sprite tab shows the cursor column x row position in the help line', function () {
    $workspace = createMainPanelWorkspace();
    $panel = new MainPanel(width: 84, height: 12, workingDirectory: $workspace);
    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);
    $buildBorderLine = new ReflectionMethod(MainPanel::class, 'buildBorderLine');
    $buildBorderLine->setAccessible(true);

    $panel->selectTab('Sprite');
    $panel->loadSpriteAsset([
        'name' => 'player.texture',
        'path' => $workspace . '/Assets/Textures/player.texture',
        'relativePath' => 'Textures/player.texture',
        'isDirectory' => false,
    ]);

    $initialHelpLine = $buildBorderLine->invoke($panel, '', false);

    expect($initialHelpLine)->toContain('Col x Row: 1 x 1');

    pressMainPanelKey("\033[C");
    $panel->update();

    pressMainPanelKey("\033[B");
    $panel->update();

    $updatedHelpLine = $buildBorderLine->invoke($panel, '', false);

    expect($updatedHelpLine)->toContain('Col x Row: 2 x 2');
});

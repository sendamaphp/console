<?php

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

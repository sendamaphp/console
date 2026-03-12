<?php

use Sendama\Console\Editor\Widgets\InspectorPanel;

test('inspector panel renders hierarchy object controls and renderer preview', function () {
    $workspace = sys_get_temp_dir() . '/sendama-inspector-panel-' . uniqid();
    mkdir($workspace . '/Assets/Textures', 0777, true);
    file_put_contents($workspace . '/Assets/Textures/player.texture', "abcd\nefgh\nijkl\n");
    $originalWorkingDirectory = getcwd();
    $panel = new InspectorPanel(width: 48, height: 24);

    chdir($workspace);

    try {
        $panel->inspectTarget([
            'context' => 'hierarchy',
            'name' => 'Player',
            'type' => 'GameObject',
            'value' => [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'tag' => 'Player',
                'position' => ['x' => 4, 'y' => 12],
                'rotation' => ['x' => 0, 'y' => 0],
                'scale' => ['x' => 1, 'y' => 1],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/player',
                        'position' => ['x' => 1, 'y' => 1],
                        'size' => ['x' => 2, 'y' => 2],
                    ],
                ],
                'components' => [
                    ['class' => 'Sendama\\Blasters\\Scripts\\Player\\PlayerController'],
                    [
                        'class' => 'Sendama\\Blasters\\Scripts\\Weapon\\Gun',
                        'ammo' => 30,
                    ],
                ],
            ],
        ]);

        expect($panel->content)->toBe([
            'Type: GameObject',
            'Name: Player',
            'Tag: Player',
            '▼ Transform',
            '  Position:',
            '    X: 4',
            '    Y: 12',
            '  Rotation:',
            '    X: 0',
            '    Y: 0',
            '  Scale:',
            '    X: 1',
            '    Y: 1',
            '▼ Renderer',
            '  Texture: Textures/player',
            '  Offset:',
            '    X: 1',
            '    Y: 1',
            '  Size:',
            '    X: 2',
            '    Y: 2',
            '  Preview:',
            '    fg',
            '    jk',
            '▼ PlayerController',
            '▼ Gun',
            '  Ammo: 30',
        ]);
    } finally {
        if ($originalWorkingDirectory !== false) {
            chdir($originalWorkingDirectory);
        }
    }
});

test('inspector panel styles component headers with a white background', function () {
    $panelWidth = 32;
    $panel = new InspectorPanel(width: $panelWidth, height: 12);

    $panel->inspectTarget([
        'context' => 'hierarchy',
        'name' => 'Player',
        'type' => 'GameObject',
        'value' => [
            'type' => 'GameObject::class',
            'name' => 'Player',
            'tag' => 'Player',
            'components' => [],
        ],
    ]);

    $decorateContentLine = new ReflectionMethod($panel, 'decorateContentLine');
    $decorateContentLine->setAccessible(true);
    $line = '|' . str_pad($panel->content[3], $panelWidth - 2) . '|';
    $renderedLine = $decorateContentLine->invoke($panel, $line, null, 3);

    expect($renderedLine)->toContain("\033[30;47m");
});

test('inspector panel resolves texture previews from the configured project directory', function () {
    $workspace = sys_get_temp_dir() . '/sendama-inspector-project-root-' . uniqid();
    mkdir($workspace . '/Assets/Textures', 0777, true);
    file_put_contents($workspace . '/Assets/Textures/player.texture', "abcd\nefgh\nijkl\n");
    $panel = new InspectorPanel(width: 48, height: 24, workingDirectory: $workspace);

    $originalWorkingDirectory = getcwd();
    chdir(sys_get_temp_dir());

    try {
        $panel->inspectTarget([
            'context' => 'hierarchy',
            'name' => 'Player',
            'type' => 'GameObject',
            'value' => [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'tag' => 'Player',
                'position' => ['x' => 4, 'y' => 12],
                'rotation' => ['x' => 0, 'y' => 0],
                'scale' => ['x' => 1, 'y' => 1],
                'sprite' => [
                    'texture' => [
                        'path' => 'Textures/player',
                        'position' => ['x' => 1, 'y' => 1],
                        'size' => ['x' => 2, 'y' => 2],
                    ],
                ],
            ],
        ]);

        expect($panel->content)->toContain('    fg');
        expect($panel->content)->toContain('    jk');
    } finally {
        if ($originalWorkingDirectory !== false) {
            chdir($originalWorkingDirectory);
        }
    }
});

test('inspector panel cycles focus through controls within the panel', function () {
    $panelWidth = 32;
    $panel = new InspectorPanel(width: $panelWidth, height: 12);

    $panel->inspectTarget([
        'context' => 'asset',
        'name' => 'Textures',
        'type' => 'Folder',
        'value' => ['name' => 'Textures'],
    ]);

    $decorateContentLine = new ReflectionMethod($panel, 'decorateContentLine');
    $decorateContentLine->setAccessible(true);

    $typeLine = '|' . str_pad($panel->content[0], $panelWidth - 2) . '|';
    $renderedTypeLine = $decorateContentLine->invoke($panel, $typeLine, null, 0);

    expect($renderedTypeLine)->toContain("\033[30;46m");

    $panel->cycleFocusForward();

    $nameLine = '|' . str_pad($panel->content[1], $panelWidth - 2) . '|';
    $renderedNameLine = $decorateContentLine->invoke($panel, $nameLine, null, 1);

    expect($renderedNameLine)->toContain("\033[30;46m");
});

test('inspector panel cycles focus backward through controls within the panel', function () {
    $panelWidth = 32;
    $panel = new InspectorPanel(width: $panelWidth, height: 12);

    $panel->inspectTarget([
        'context' => 'asset',
        'name' => 'Textures',
        'type' => 'Folder',
        'value' => ['name' => 'Textures'],
    ]);

    $decorateContentLine = new ReflectionMethod($panel, 'decorateContentLine');
    $decorateContentLine->setAccessible(true);

    $panel->cycleFocusBackward();

    $nameLine = '|' . str_pad($panel->content[1], $panelWidth - 2) . '|';
    $renderedNameLine = $decorateContentLine->invoke($panel, $nameLine, null, 1);

    expect($renderedNameLine)->toContain("\033[30;46m");
});

test('inspector panel keeps generic asset inspection simple', function () {
    $panel = new InspectorPanel(width: 32, height: 12);

    $panel->inspectTarget([
        'context' => 'asset',
        'name' => 'Textures',
        'type' => 'Folder',
        'value' => [
            'name' => 'Textures',
            'path' => '/tmp/project/Assets/Textures',
            'isDirectory' => true,
        ],
    ]);

    expect($panel->content)->toBe([
        'Type: Folder',
        'Name: Textures',
        'Path: /tmp/project/Assets/Textures',
    ]);
});

test('inspector panel allows file assets to rename through the name control', function () {
    $panel = new InspectorPanel(width: 48, height: 16);

    $panel->inspectTarget([
        'context' => 'asset',
        'name' => 'player.texture',
        'type' => 'File',
        'value' => [
            'name' => 'player.texture',
            'path' => '/tmp/project/Assets/Textures/player.texture',
            'relativePath' => 'Textures/player.texture',
            'isDirectory' => false,
        ],
    ]);

    $hasFocus = new ReflectionProperty(\Sendama\Console\Editor\Widgets\Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    $keyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'previousKeyPress');
    $keyPress->setAccessible(true);
    $previousKeyPress->setAccessible(true);

    $panel->cycleFocusForward();

    $previousKeyPress->setValue('');
    $keyPress->setValue("\n");
    $panel->update();

    foreach (str_split('2') as $character) {
        $previousKeyPress->setValue('');
        $keyPress->setValue($character);
        $panel->update();
    }

    $previousKeyPress->setValue('');
    $keyPress->setValue("\n");
    $panel->update();

    expect($panel->consumeAssetMutation())->toBe([
        'path' => '/tmp/project/Assets/Textures/player.texture',
        'relativePath' => 'Textures/player.texture',
        'name' => 'player.texture2',
    ]);
});

test('inspector panel renders editable scene controls', function () {
    $panel = new InspectorPanel(width: 48, height: 16);

    $panel->inspectTarget([
        'context' => 'scene',
        'name' => 'level01',
        'type' => 'Scene',
        'path' => 'scene',
        'value' => [
            'name' => 'level01',
            'width' => 80,
            'height' => 25,
            'environmentTileMapPath' => 'Maps/level',
        ],
    ]);

    expect($panel->content)->toBe([
        'Type: Scene',
        'Name: level01',
        'Width: 80',
        'Height: 25',
        'Environment Tile Map: Maps/level',
    ]);
});

test('inspector panel opens the path action modal for path controls', function () {
    $workspace = sys_get_temp_dir() . '/sendama-inspector-path-modal-' . uniqid();
    mkdir($workspace . '/Assets/Textures', 0777, true);
    file_put_contents($workspace . '/Assets/Textures/player.texture', "x\n");
    $originalWorkingDirectory = getcwd();
    $panel = new InspectorPanel(width: 48, height: 24);

    chdir($workspace);

    try {
        $panel->inspectTarget([
            'context' => 'hierarchy',
            'name' => 'Player',
            'type' => 'GameObject',
            'value' => [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'tag' => 'Player',
                'position' => ['x' => 4, 'y' => 12],
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

        $hasFocus = new ReflectionProperty(\Sendama\Console\Editor\Widgets\Widget::class, 'hasFocus');
        $hasFocus->setAccessible(true);
        $hasFocus->setValue($panel, true);

        for ($index = 0; $index < 6; $index++) {
            $panel->cycleFocusForward();
        }

        $keyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'keyPress');
        $previousKeyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'previousKeyPress');
        $keyPress->setAccessible(true);
        $previousKeyPress->setAccessible(true);
        $previousKeyPress->setValue('');
        $keyPress->setValue("\n");

        $panel->update();

        expect($panel->hasActiveModal())->toBeTrue();
        expect($panel->isModalDirty())->toBeTrue();

        $panel->markModalClean();

        expect($panel->isModalDirty())->toBeFalse();
    } finally {
        if ($originalWorkingDirectory !== false) {
            chdir($originalWorkingDirectory);
        }
    }
});

test('inspector panel emits hierarchy mutations when edits are committed', function () {
    $panel = new InspectorPanel(width: 48, height: 24);

    $panel->inspectTarget([
        'context' => 'hierarchy',
        'name' => 'Player',
        'type' => 'GameObject',
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Player',
            'tag' => 'Player',
            'position' => ['x' => 4, 'y' => 12],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
        ],
    ]);

    $hasFocus = new ReflectionProperty(\Sendama\Console\Editor\Widgets\Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    $keyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'previousKeyPress');
    $keyPress->setAccessible(true);
    $previousKeyPress->setAccessible(true);

    $panel->cycleFocusForward();

    $previousKeyPress->setValue('');
    $keyPress->setValue("\n");
    $panel->update();

    foreach (str_split(' 2') as $character) {
        $previousKeyPress->setValue('');
        $keyPress->setValue($character);
        $panel->update();
    }

    $previousKeyPress->setValue('');
    $keyPress->setValue("\n");
    $panel->update();

    expect($panel->consumeHierarchyMutation())->toBe([
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Player 2',
            'tag' => 'Player',
            'position' => ['x' => 4, 'y' => 12],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
        ],
    ]);
});

test('inspector panel emits scene mutations when scene details are committed', function () {
    $panel = new InspectorPanel(width: 48, height: 24);

    $panel->inspectTarget([
        'context' => 'scene',
        'name' => 'level01',
        'type' => 'Scene',
        'path' => 'scene',
        'value' => [
            'name' => 'level01',
            'width' => 80,
            'height' => 25,
            'environmentTileMapPath' => 'Maps/level',
        ],
    ]);

    $hasFocus = new ReflectionProperty(\Sendama\Console\Editor\Widgets\Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    $keyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'previousKeyPress');
    $keyPress->setAccessible(true);
    $previousKeyPress->setAccessible(true);

    $panel->cycleFocusForward();
    $panel->cycleFocusForward();

    $previousKeyPress->setValue('');
    $keyPress->setValue("\n");
    $panel->update();

    foreach (str_split('2') as $character) {
        $previousKeyPress->setValue('');
        $keyPress->setValue($character);
        $panel->update();
    }

    $previousKeyPress->setValue('');
    $keyPress->setValue("\n");
    $panel->update();

    expect($panel->consumeHierarchyMutation())->toBe([
        'path' => 'scene',
        'value' => [
            'name' => 'level01',
            'width' => 802,
            'height' => 25,
            'environmentTileMapPath' => 'Maps/level',
        ],
    ]);
});

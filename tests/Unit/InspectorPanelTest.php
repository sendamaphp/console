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
        'value' => ['name' => 'Textures'],
    ]);

    expect($panel->content)->toBe([
        'Type: Folder',
        'Name: Textures',
    ]);
});

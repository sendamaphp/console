<?php

use Sendama\Console\Editor\DTOs\SceneDTO;
use Sendama\Console\Editor\SceneWriter;

test('scene writer serializes the loaded scene hierarchy to php', function () {
    $scene = new SceneDTO(
        name: 'level01',
        width: 120,
        height: 40,
        environmentTileMapPath: 'Maps/level',
        isDirty: true,
        hierarchy: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player 2',
                'tag' => 'Player',
                'position' => ['x' => 4, 'y' => 12],
            ],
        ],
        rawData: [
            'customFlag' => true,
        ],
    );

    $writer = new SceneWriter();
    $serializedScene = $writer->serialize($scene);

    expect($serializedScene)->toContain("<?php");
    expect($serializedScene)->toContain("'width' => 120");
    expect($serializedScene)->toContain("'environmentTileMapPath' => 'Maps/level'");
    expect($serializedScene)->toContain("'name' => 'Player 2'");
    expect($serializedScene)->toContain("'customFlag' => true");
    expect($serializedScene)->not->toContain("'isDirty'");
});

test('scene writer saves scenes to the source path', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-writer-' . uniqid();
    mkdir($workspace . '/Assets/Scenes', 0777, true);
    $scenePath = $workspace . '/Assets/Scenes/level01.scene.php';

    $scene = new SceneDTO(
        name: 'level01',
        hierarchy: [
            ['name' => 'Player', 'type' => 'Sendama\\Engine\\Core\\GameObject'],
        ],
        sourcePath: $scenePath,
    );

    $writer = new SceneWriter();

    expect($writer->save($scene))->toBeTrue();
    expect(file_get_contents($scenePath))->toContain("'name' => 'Player'");
});

test('scene writer preserves unchanged source expressions when saving edited scenes', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-writer-preserve-' . uniqid();
    mkdir($workspace . '/Assets/Scenes', 0777, true);
    $scenePath = $workspace . '/Assets/Scenes/level01.scene.php';

    $source = <<<'PHP'
<?php

use Sendama\Engine\Core\GameObject;

return [
    'width' => DEFAULT_SCREEN_WIDTH,
    'height' => DEFAULT_SCREEN_HEIGHT,
    'environmentTileMapPath' => 'Maps/level',
    'hierarchy' => [
        [
            'type' => GameObject::class,
            'name' => 'Player',
            'tag' => 'Player',
            'position' => ['x' => 4, 'y' => DEFAULT_SCREEN_HEIGHT / 2],
        ],
    ],
];
PHP;

    file_put_contents($scenePath, $source);

    $scene = new SceneDTO(
        name: 'level01',
        width: 120,
        height: 40,
        environmentTileMapPath: 'Maps/level',
        hierarchy: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player 2',
                'tag' => 'Player',
                'position' => ['x' => 4, 'y' => 20],
            ],
        ],
        sourcePath: $scenePath,
        rawData: [
            'width' => 120,
            'height' => 40,
            'environmentTileMapPath' => 'Maps/level',
            'hierarchy' => [
                [
                    'type' => 'Sendama\\Engine\\Core\\GameObject',
                    'name' => 'Player 2',
                    'tag' => 'Player',
                    'position' => ['x' => 4, 'y' => 20],
                ],
            ],
        ],
        sourceData: [
            'width' => 120,
            'height' => 40,
            'environmentTileMapPath' => 'Maps/level',
            'hierarchy' => [
                [
                    'type' => 'Sendama\\Engine\\Core\\GameObject',
                    'name' => 'Player',
                    'tag' => 'Player',
                    'position' => ['x' => 4, 'y' => 20],
                ],
            ],
        ],
    );

    $writer = new SceneWriter();
    $serializedScene = $writer->serialize($scene);

    expect($serializedScene)->toContain("GameObject::class");
    expect($serializedScene)->toContain("'y' => DEFAULT_SCREEN_HEIGHT / 2");
    expect($serializedScene)->toContain("'name' => 'Player 2'");
});

test('scene writer preserves unknown source fields when the loaded snapshot is partial', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-writer-partial-' . uniqid();
    mkdir($workspace . '/Assets/Scenes', 0777, true);
    $scenePath = $workspace . '/Assets/Scenes/level01.scene.php';

    file_put_contents(
        $scenePath,
        <<<'PHP'
<?php

use Sendama\Engine\Core\GameObject;

return [
    'hierarchy' => [
        [
            'type' => GameObject::class,
            'name' => 'Player',
            'tag' => 'Player',
            'position' => ['x' => 4, 'y' => DEFAULT_SCREEN_HEIGHT / 2],
        ],
    ],
];
PHP
    );

    $scene = new SceneDTO(
        name: 'level01',
        hierarchy: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player 2',
            ],
        ],
        sourcePath: $scenePath,
        rawData: [
            'hierarchy' => [
                [
                    'type' => 'Sendama\\Engine\\Core\\GameObject',
                    'name' => 'Player 2',
                ],
            ],
        ],
        sourceData: [
            'hierarchy' => [
                [
                    'type' => 'Sendama\\Engine\\Core\\GameObject',
                    'name' => 'Player',
                ],
            ],
        ],
    );

    $writer = new SceneWriter();
    $serializedScene = $writer->serialize($scene);

    expect($serializedScene)->toContain("'name' => 'Player 2'");
    expect($serializedScene)->toContain("'tag' => 'Player'");
    expect($serializedScene)->toContain("'y' => DEFAULT_SCREEN_HEIGHT / 2");
});

test('scene writer preserves existing list entries when appending to a partial hierarchy', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-writer-append-' . uniqid();
    mkdir($workspace . '/Assets/Scenes', 0777, true);
    $scenePath = $workspace . '/Assets/Scenes/level01.scene.php';

    file_put_contents(
        $scenePath,
        <<<'PHP'
<?php

use Sendama\Engine\Core\GameObject;
use Sendama\Engine\UI\Label\Label;

return [
    'hierarchy' => [
        [
            'type' => GameObject::class,
            'name' => 'Player',
            'tag' => 'Player',
            'position' => ['x' => 4, 'y' => DEFAULT_SCREEN_HEIGHT / 2],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
        ],
        [
            'type' => Label::class,
            'name' => 'Score',
            'position' => ['x' => 4, 'y' => 1],
            'size' => ['x' => 10, 'y' => 1],
            'text' => 'Score: 000',
        ],
    ],
];
PHP
    );

    $scene = new SceneDTO(
        name: 'level01',
        hierarchy: [
            [
                'type' => 'GameObject::class',
                'name' => 'Player',
            ],
            [
                'type' => 'Label::class',
                'name' => 'Score',
            ],
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Power Up',
                'tag' => 'None',
                'position' => ['x' => 0, 'y' => 0],
                'rotation' => ['x' => 0, 'y' => 0],
                'scale' => ['x' => 1, 'y' => 1],
                'components' => [],
            ],
        ],
        sourcePath: $scenePath,
        rawData: [
            'hierarchy' => [
                [
                    'type' => 'GameObject::class',
                    'name' => 'Player',
                ],
                [
                    'type' => 'Label::class',
                    'name' => 'Score',
                ],
                [
                    'type' => 'Sendama\\Engine\\Core\\GameObject',
                    'name' => 'Power Up',
                    'tag' => 'None',
                    'position' => ['x' => 0, 'y' => 0],
                    'rotation' => ['x' => 0, 'y' => 0],
                    'scale' => ['x' => 1, 'y' => 1],
                    'components' => [],
                ],
            ],
        ],
        sourceData: [
            'hierarchy' => [
                [
                    'type' => 'GameObject::class',
                    'name' => 'Player',
                ],
                [
                    'type' => 'Label::class',
                    'name' => 'Score',
                ],
            ],
        ],
    );

    $writer = new SceneWriter();
    $serializedScene = $writer->serialize($scene);

    expect($serializedScene)->toContain("'tag' => 'Player'");
    expect($serializedScene)->toContain("'rotation' => ['x' => 0, 'y' => 0]");
    expect($serializedScene)->toContain("'size' => ['x' => 10, 'y' => 1]");
    expect($serializedScene)->toContain("'type' => \\Sendama\\Engine\\Core\\GameObject::class");
    expect($serializedScene)->not->toContain("'type' => 'GameObject::class'");
});

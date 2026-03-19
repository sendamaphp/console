<?php

use Sendama\Console\Editor\DTOs\SceneDTO;
use Sendama\Console\Editor\SceneWriter;

test('scene writer serializes the loaded scene hierarchy to php', function () {
    $scene = new SceneDTO(
        name: 'level01',
        width: 120,
        height: 40,
        environmentTileMapPath: 'Maps/level',
        environmentCollisionMapPath: 'Maps/level.collider',
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
    expect($serializedScene)->toContain("'environmentCollisionMapPath' => 'Maps/level.collider'");
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

test('scene writer strips editor-only component metadata while preserving prefab references', function () {
    $scene = new SceneDTO(
        name: 'level01',
        hierarchy: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'components' => [
                    [
                        'class' => 'Sendama\\Game\\Scripts\\Gun',
                        'data' => [
                            'bulletPrefab' => 'Prefabs/enemy.prefab.php',
                        ],
                        '__editorFieldTypes' => [
                            'bulletPrefab' => 'Sendama\\Engine\\Core\\GameObject|null',
                        ],
                    ],
                ],
            ],
        ],
        rawData: [
            'hierarchy' => [
                [
                    'type' => 'Sendama\\Engine\\Core\\GameObject',
                    'name' => 'Player',
                    'components' => [
                        [
                            'class' => 'Sendama\\Game\\Scripts\\Gun',
                            'data' => [
                                'bulletPrefab' => 'Prefabs/enemy.prefab.php',
                            ],
                            '__editorFieldTypes' => [
                                'bulletPrefab' => 'Sendama\\Engine\\Core\\GameObject|null',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    );

    $serializedScene = (new SceneWriter())->serialize($scene);

    expect($serializedScene)->toContain("'bulletPrefab' => 'Prefabs/enemy.prefab.php'")
        ->and($serializedScene)->not->toContain('__editorFieldTypes');
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

test('scene writer targets the final top-level scene return when helper returns exist earlier in the file', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-writer-helper-return-' . uniqid();
    mkdir($workspace . '/Assets/Scenes', 0777, true);
    $scenePath = $workspace . '/Assets/Scenes/level01.scene.php';

    file_put_contents(
        $scenePath,
        <<<'PHP'
<?php

use Sendama\Engine\Core\GameObject;

$normalize = static function (string $value): string {
    return trim($value);
};

return [
    'hierarchy' => [
        [
            'type' => GameObject::class,
            'name' => 'Player',
            'tag' => 'Player',
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

    expect($serializedScene)->toContain('return trim($value);');
    expect($serializedScene)->toContain("'name' => 'Player 2'");
    expect($serializedScene)->toContain("'tag' => 'Player'");
    expect(substr_count($serializedScene, 'return ['))->toBe(1);
});

test('scene writer matches list items by identity instead of shifted indexes when entries are deleted', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-writer-delete-shift-' . uniqid();
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
            'position' => ['x' => 1, 'y' => 2],
        ],
        [
            'type' => GameObject::class,
            'name' => 'Enemy',
            'tag' => 'Enemy',
            'position' => ['x' => 9, 'y' => 8],
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
                'name' => 'Enemy',
            ],
        ],
        sourcePath: $scenePath,
        rawData: [
            'hierarchy' => [
                [
                    'type' => 'GameObject::class',
                    'name' => 'Enemy',
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
                    'type' => 'GameObject::class',
                    'name' => 'Enemy',
                ],
            ],
        ],
    );

    $writer = new SceneWriter();
    $serializedScene = $writer->serialize($scene);

    expect($serializedScene)->not->toContain("'name' => 'Player'");
    expect($serializedScene)->toContain("'name' => 'Enemy'");
    expect($serializedScene)->toContain("'tag' => 'Enemy'");
    expect($serializedScene)->toContain("'position' => ['x' => 9, 'y' => 8]");
    expect($serializedScene)->not->toContain("'tag' => 'Player'");
    expect($serializedScene)->not->toContain("'position' => ['x' => 1, 'y' => 2]");
});

test('scene writer persists serialized component data added by the editor snapshot', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-writer-component-data-' . uniqid();
    mkdir($workspace . '/Assets/Scenes', 0777, true);
    $scenePath = $workspace . '/Assets/Scenes/level01.scene.php';

    file_put_contents(
        $scenePath,
        <<<'PHP'
<?php

use Sendama\Engine\Core\GameObject;
use Sendama\Game\PlayerController;

return [
    'hierarchy' => [
        [
            'type' => GameObject::class,
            'name' => 'Player',
            'components' => [
                [
                    'class' => PlayerController::class,
                ],
            ],
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
                'name' => 'Player',
                'components' => [
                    [
                        'class' => 'Sendama\\Game\\PlayerController',
                        'data' => [
                            'enabledInEditor' => true,
                            'speed' => 3,
                            'spawnOffset' => ['x' => 2, 'y' => 1],
                        ],
                    ],
                ],
            ],
        ],
        sourcePath: $scenePath,
        rawData: [
            'hierarchy' => [
                [
                    'type' => 'Sendama\\Engine\\Core\\GameObject',
                    'name' => 'Player',
                    'components' => [
                        [
                            'class' => 'Sendama\\Game\\PlayerController',
                            'data' => [
                                'enabledInEditor' => true,
                                'speed' => 3,
                                'spawnOffset' => ['x' => 2, 'y' => 1],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        sourceData: [
            'hierarchy' => [
                [
                    'type' => 'Sendama\\Engine\\Core\\GameObject',
                    'name' => 'Player',
                    'components' => [
                        [
                            'class' => 'Sendama\\Game\\PlayerController',
                        ],
                    ],
                ],
            ],
        ],
    );

    $writer = new SceneWriter();
    $serializedScene = $writer->serialize($scene);

    expect(preg_match('/[\'"]class[\'"]\s*=>\s*\\\\Sendama\\\\Game\\\\PlayerController::class/', $serializedScene))->toBe(1);
    expect(preg_match('/[\'"]data[\'"]\s*=>\s*\[/', $serializedScene))->toBe(1);
    expect($serializedScene)->toContain("'enabledInEditor' => true");
    expect($serializedScene)->toContain("'speed' => 3");
    expect($serializedScene)->toContain("'spawnOffset' => [");
});

test('scene writer removes orphaned component data keys when serialized properties are renamed', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-writer-component-rename-' . uniqid();
    mkdir($workspace . '/Assets/Scenes', 0777, true);
    $scenePath = $workspace . '/Assets/Scenes/level01.scene.php';

    file_put_contents(
        $scenePath,
        <<<'PHP'
<?php

use Sendama\Engine\Core\GameObject;
use Sendama\Game\EnemyController;

return [
    'hierarchy' => [
        [
            'type' => GameObject::class,
            'name' => 'Enemy',
            'components' => [
                [
                    'class' => EnemyController::class,
                    'data' => [
                        'moveSpeed' => 1,
                    ],
                ],
            ],
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
                'name' => 'Enemy',
                'components' => [
                    [
                        'class' => 'Sendama\\Game\\EnemyController',
                        'data' => [
                            'speed' => 1,
                        ],
                    ],
                ],
            ],
        ],
        sourcePath: $scenePath,
        rawData: [
            'hierarchy' => [
                [
                    'type' => 'Sendama\\Engine\\Core\\GameObject',
                    'name' => 'Enemy',
                    'components' => [
                        [
                            'class' => 'Sendama\\Game\\EnemyController',
                            'data' => [
                                'speed' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        sourceData: [
            'hierarchy' => [
                [
                    'type' => 'Sendama\\Engine\\Core\\GameObject',
                    'name' => 'Enemy',
                    'components' => [
                        [
                            'class' => 'Sendama\\Game\\EnemyController',
                            'data' => [
                                'moveSpeed' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    );

    $writer = new SceneWriter();
    $serializedScene = $writer->serialize($scene);

    expect($serializedScene)->toContain("'speed' => 1");
    expect($serializedScene)->not->toContain("'moveSpeed' => 1");
});

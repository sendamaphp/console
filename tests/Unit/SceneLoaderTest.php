<?php

use Sendama\Console\Editor\EditorSceneSettings;
use Sendama\Console\Editor\SceneLoader;

test('scene loader resolves the active configured scene', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-loader-' . uniqid();
    mkdir($workspace . '/Assets/Scenes', 0777, true);
    mkdir($workspace . '/vendor', 0777, true);

    file_put_contents($workspace . '/vendor/autoload.php', "<?php\n");
    file_put_contents(
        $workspace . '/Assets/Scenes/level01.scene.php',
        <<<'PHP'
<?php

return [
    'width' => 120,
    'height' => 40,
    'hierarchy' => [
        ['name' => 'Game Manager'],
        ['name' => 'Player'],
    ],
];
PHP
    );

    $loader = new SceneLoader($workspace);
    $scene = $loader->load(new EditorSceneSettings(active: 0, loaded: ['level01']));

    expect($scene)->not->toBeNull();
    expect($scene->name)->toBe('level01');
    expect($scene->hierarchy)->toHaveCount(2);
    expect($scene->hierarchy[1]['name'])->toBe('Player');
});

test('scene loader falls back to the first available scene when none is configured', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-loader-' . uniqid();
    mkdir($workspace . '/assets/Scenes', 0777, true);

    file_put_contents($workspace . '/assets/Scenes/alpha.scene.php', "<?php return ['hierarchy' => [['name' => 'Alpha']]];");
    file_put_contents($workspace . '/assets/Scenes/beta.scene.php', "<?php return ['hierarchy' => [['name' => 'Beta']]];");

    $loader = new SceneLoader($workspace);
    $scene = $loader->load(new EditorSceneSettings());

    expect($scene)->not->toBeNull();
    expect($scene->name)->toBe('alpha');
    expect($scene->hierarchy[0]['name'])->toBe('Alpha');
});

test('scene loader extracts hierarchy types from source when evaluation fails', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-loader-' . uniqid();
    mkdir($workspace . '/Assets/Scenes', 0777, true);

    file_put_contents(
        $workspace . '/Assets/Scenes/level01.scene.php',
        <<<'PHP'
<?php

use Sendama\Engine\Core\GameObject;
use Sendama\Engine\UI\Label\Label;

return [
    'hierarchy' => [
        [
            'type' => GameObject::class,
            'name' => 'Player',
            'position' => ['x' => DEFAULT_SCREEN_WIDTH / 2, 'y' => 0],
        ],
        [
            'type' => Label::class,
            'name' => 'Score',
        ],
    ],
];
PHP
    );

    $loader = new SceneLoader($workspace);
    $scene = $loader->load(new EditorSceneSettings(active: 0, loaded: ['level01']));

    expect($scene)->not->toBeNull();
    expect($scene->hierarchy[0])->toBe([
        'name' => 'Player',
        'type' => 'GameObject::class',
    ]);
    expect($scene->hierarchy[1])->toBe([
        'name' => 'Score',
        'type' => 'Label::class',
    ]);
});

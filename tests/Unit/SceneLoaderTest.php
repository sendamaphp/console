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

test('scene loader evaluates scene metadata in an isolated project context', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-loader-' . uniqid();
    mkdir($workspace . '/assets/Scenes', 0777, true);
    mkdir($workspace . '/vendor', 0777, true);

    file_put_contents(
        $workspace . '/vendor/autoload.php',
        <<<'PHP'
<?php

namespace {
    const DEFAULT_SCREEN_WIDTH = 120;
    const DEFAULT_SCREEN_HEIGHT = 40;
    const LEVEL_HEIGHT = 25;
}

namespace Sendama\Blasters\Scripts {
    enum Tag: string
    {
        case Manager = 'Manager';
        case Player = 'Player';
        case UI = 'UI';
    }
}

namespace Sendama\Engine\Core {
    class GameObject
    {
    }
}

namespace Sendama\Engine\UI\Label {
    class Label
    {
    }
}
PHP
    );

    file_put_contents(
        $workspace . '/assets/Scenes/level01.scene.php',
        <<<'PHP'
<?php

use Sendama\Blasters\Scripts\Tag;
use Sendama\Engine\Core\GameObject;
use Sendama\Engine\UI\Label\Label;

return [
    'width' => DEFAULT_SCREEN_WIDTH,
    'height' => DEFAULT_SCREEN_HEIGHT,
    'hierarchy' => [
        [
            'type' => GameObject::class,
            'name' => 'Player',
            'tag' => Tag::Player->value,
            'position' => ['x' => 4, 'y' => DEFAULT_SCREEN_HEIGHT / 2],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'sprite' => [
                'texture' => [
                    'path' => 'Textures/player',
                    'position' => ['x' => 0, 'y' => 0],
                    'size' => ['x' => 1, 'y' => 5],
                ],
            ],
            'components' => [
                ['class' => 'Sendama\\Game\\PlayerController'],
            ],
        ],
        [
            'type' => Label::class,
            'name' => 'Score',
            'tag' => Tag::UI->value,
            'position' => ['x' => 4, 'y' => LEVEL_HEIGHT - 2],
            'size' => ['x' => 10, 'y' => 1],
            'text' => 'Score: 000',
        ],
    ],
];
PHP
    );

    $loader = new SceneLoader($workspace);
    $scene = $loader->load(new EditorSceneSettings(active: 0, loaded: ['level01']));

    expect($scene)->not->toBeNull();
    expect($scene->width)->toBe(120);
    expect($scene->height)->toBe(40);
    expect($scene->hierarchy[0])->toBe([
        'type' => 'Sendama\\Engine\\Core\\GameObject',
        'name' => 'Player',
        'tag' => 'Player',
        'position' => ['x' => 4, 'y' => 20],
        'rotation' => ['x' => 0, 'y' => 0],
        'scale' => ['x' => 1, 'y' => 1],
        'sprite' => [
            'texture' => [
                'path' => 'Textures/player',
                'position' => ['x' => 0, 'y' => 0],
                'size' => ['x' => 1, 'y' => 5],
            ],
        ],
        'components' => [
            ['class' => 'Sendama\\Game\\PlayerController'],
        ],
    ]);
    expect($scene->hierarchy[1])->toBe([
        'type' => 'Sendama\\Engine\\UI\\Label\\Label',
        'name' => 'Score',
        'tag' => 'UI',
        'position' => ['x' => 4, 'y' => 23],
        'size' => ['x' => 10, 'y' => 1],
        'text' => 'Score: 000',
    ]);
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

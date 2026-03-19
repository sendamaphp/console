<?php

use Sendama\Console\Editor\PrefabLoader;

test('prefab loader normalizes stringified vector component data for vector typed fields', function () {
    $workspace = sys_get_temp_dir() . '/sendama-prefab-loader-' . uniqid();
    mkdir($workspace . '/assets/Prefabs', 0777, true);
    mkdir($workspace . '/vendor', 0777, true);

    file_put_contents(
        $workspace . '/vendor/autoload.php',
        <<<'PHP'
<?php

namespace Sendama\Engine\Core {
    class Vector2
    {
        public function __construct(private int $x = 0, private int $y = 0)
        {
        }

        public function getX(): int
        {
            return $this->x;
        }

        public function getY(): int
        {
            return $this->y;
        }
    }

    class GameObject
    {
        public function __construct(
            private string $name,
            private ?string $tag = null,
            private Vector2 $position = new Vector2(),
            private Vector2 $rotation = new Vector2(),
            private Vector2 $scale = new Vector2(1, 1),
            private ?object $sprite = null,
        ) {
        }

        public function getName(): string
        {
            return $this->name;
        }
    }

    abstract class Component
    {
        public function __construct(private readonly GameObject $gameObject)
        {
        }

        public function getGameObject(): GameObject
        {
            return $this->gameObject;
        }
    }
}

namespace Sendama\Blasters\Scripts\Weapon {
    class Bullet extends \Sendama\Engine\Core\Component
    {
        public ?\Sendama\Engine\Core\Vector2 $minBound = null;
        public ?\Sendama\Engine\Core\Vector2 $maxBound = null;
    }
}
PHP
    );

    $prefabPath = $workspace . '/assets/Prefabs/bullet.prefab.php';

    file_put_contents(
        $prefabPath,
        <<<'PHP'
<?php

return [
    'type' => \Sendama\Engine\Core\GameObject::class,
    'name' => 'Bullet',
    'tag' => 'Bullet',
    'position' => ['x' => 0, 'y' => 0],
    'rotation' => ['x' => 0, 'y' => 0],
    'scale' => ['x' => 1, 'y' => 1],
    'components' => [
        [
            'class' => \Sendama\Blasters\Scripts\Weapon\Bullet::class,
            'data' => [
                'minBound' => '[1,1]',
                'maxBound' => '[120,25]',
            ],
        ],
    ],
];
PHP
    );

    $loader = new PrefabLoader($workspace);
    $prefab = $loader->load($prefabPath);

    expect($prefab)->not->toBeNull()
        ->and($prefab['components'][0]['data'])->toBe([
            'minBound' => ['x' => 1, 'y' => 1],
            'maxBound' => ['x' => 120, 'y' => 25],
        ])
        ->and($prefab['components'][0]['__editorFieldTypes'] ?? null)->toBe([
            'minBound' => 'Sendama\\Engine\\Core\\Vector2|null',
            'maxBound' => 'Sendama\\Engine\\Core\\Vector2|null',
        ]);
});

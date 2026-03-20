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

test('prefab loader preserves native engine object defaults for typed component fields', function () {
    $workspace = sys_get_temp_dir() . '/sendama-prefab-loader-native-fields-' . uniqid();
    mkdir($workspace . '/assets/Prefabs', 0777, true);
    mkdir($workspace . '/vendor', 0777, true);

    file_put_contents(
        $workspace . '/vendor/autoload.php',
        <<<'PHP'
<?php

namespace Sendama\Engine\Core\Behaviours\Attributes {
    #[\Attribute(\Attribute::TARGET_PROPERTY)]
    class SerializeField
    {
    }
}

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

    class Texture
    {
        public function __construct(public string $path)
        {
        }
    }

    class Sprite
    {
        public function __construct(
            public Texture $texture,
            public array $rect,
            public array $pivot = ['x' => 0, 'y' => 0],
        ) {
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
    }

    abstract class Component
    {
        public function __construct(private readonly GameObject $gameObject)
        {
        }
    }
}

namespace Sendama\Game\Scripts {
    use Sendama\Engine\Core\Behaviours\Attributes\SerializeField;
    use Sendama\Engine\Core\Component;
    use Sendama\Engine\Core\GameObject;
    use Sendama\Engine\Core\Sprite;
    use Sendama\Engine\Core\Texture;

    class WeaponConfig extends Component
    {
        #[SerializeField]
        protected ?Texture $bulletTexture = null;

        #[SerializeField]
        protected ?Sprite $aimSprite = null;

        public function __construct(GameObject $gameObject)
        {
            parent::__construct($gameObject);
            $this->bulletTexture = new Texture('Textures/bullet.texture');
            $this->aimSprite = new Sprite(
                new Texture('Textures/bullet.texture'),
                ['x' => 1, 'y' => 2, 'width' => 3, 'height' => 4],
                ['x' => 0, 'y' => 1],
            );
        }
    }
}
PHP
    );

    $prefabPath = $workspace . '/assets/Prefabs/weapon.prefab.php';

    file_put_contents(
        $prefabPath,
        <<<'PHP'
<?php

return [
    'type' => \Sendama\Engine\Core\GameObject::class,
    'name' => 'Weapon',
    'tag' => 'Weapon',
    'position' => ['x' => 0, 'y' => 0],
    'rotation' => ['x' => 0, 'y' => 0],
    'scale' => ['x' => 1, 'y' => 1],
    'components' => [
        [
            'class' => \Sendama\Game\Scripts\WeaponConfig::class,
            'data' => [],
        ],
    ],
];
PHP
    );

    $loader = new PrefabLoader($workspace);
    $prefab = $loader->load($prefabPath);

    expect($prefab)->not->toBeNull()
        ->and($prefab['components'][0]['data'])->toBe([
            'bulletTexture' => 'Textures/bullet.texture',
            'aimSprite' => [
                'texture' => 'Textures/bullet.texture',
                'rect' => [
                    'x' => 1,
                    'y' => 2,
                    'width' => 3,
                    'height' => 4,
                ],
                'pivot' => [
                    'x' => 0,
                    'y' => 1,
                ],
            ],
        ])
        ->and($prefab['components'][0]['__editorFieldTypes'] ?? null)->toBe([
            'bulletTexture' => 'Sendama\\Engine\\Core\\Texture|null',
            'aimSprite' => 'Sendama\\Engine\\Core\\Sprite|null',
        ]);
});

test('prefab loader preserves compound structure defaults for typed component fields', function () {
    $workspace = sys_get_temp_dir() . '/sendama-prefab-loader-compound-fields-' . uniqid();
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
    }

    abstract class Component
    {
        public function __construct(private readonly GameObject $gameObject)
        {
        }
    }
}

namespace Sendama\Game\Scripts {
    use Sendama\Engine\Core\Component;
    use Sendama\Engine\Core\GameObject;
    use Sendama\Engine\Core\Vector2;

    class CompoundSettings
    {
        public int $waves = 3;
        public Vector2 $origin;

        public function __construct()
        {
            $this->origin = new Vector2(6, 7);
        }
    }

    class SchemaProbe extends Component
    {
        public int $speed = 4;

        /** @var Vector2[] */
        public array $waypoints = [];

        public CompoundSettings $settings;

        public function __construct(GameObject $gameObject)
        {
            parent::__construct($gameObject);
            $this->waypoints = [
                new Vector2(1, 2),
                new Vector2(3, 4),
            ];
            $this->settings = new CompoundSettings();
        }
    }
}
PHP
    );

    $prefabPath = $workspace . '/assets/Prefabs/schema.prefab.php';

    file_put_contents(
        $prefabPath,
        <<<'PHP'
<?php

return [
    'type' => \Sendama\Engine\Core\GameObject::class,
    'name' => 'Schema Owner',
    'tag' => 'Manager',
    'position' => ['x' => 0, 'y' => 0],
    'rotation' => ['x' => 0, 'y' => 0],
    'scale' => ['x' => 1, 'y' => 1],
    'components' => [
        [
            'class' => \Sendama\Game\Scripts\SchemaProbe::class,
        ],
    ],
];
PHP
    );

    $loader = new PrefabLoader($workspace);
    $prefab = $loader->load($prefabPath);

    expect($prefab)->not->toBeNull()
        ->and($prefab['components'][0]['data'] ?? null)->toBe([
            'speed' => 4,
            'waypoints' => [
                ['x' => 1, 'y' => 2],
                ['x' => 3, 'y' => 4],
            ],
            'settings' => [
                'waves' => 3,
                'origin' => ['x' => 6, 'y' => 7],
            ],
        ])
        ->and($prefab['components'][0]['__editorFieldTypes'] ?? null)->toBe([
            'speed' => 'int',
            'waypoints' => 'array',
            'settings' => 'Sendama\\Game\\Scripts\\CompoundSettings',
        ]);
});

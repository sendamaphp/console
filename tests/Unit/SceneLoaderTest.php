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

test('scene loader normalizes environment tile map paths to extensionless asset paths', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-loader-map-path-' . uniqid();
    mkdir($workspace . '/Assets/Scenes', 0777, true);
    mkdir($workspace . '/vendor', 0777, true);

    file_put_contents($workspace . '/vendor/autoload.php', "<?php\n");
    file_put_contents(
        $workspace . '/Assets/Scenes/level01.scene.php',
        <<<'PHP'
<?php

return [
    'environmentTileMapPath' => 'Maps/level.tmap',
    'hierarchy' => [],
];
PHP
    );

    $loader = new SceneLoader($workspace);
    $scene = $loader->load(new EditorSceneSettings(active: 0, loaded: ['level01']));

    expect($scene)->not->toBeNull();
    expect($scene->environmentTileMapPath)->toBe('Maps/level');
    expect($scene->rawData['environmentTileMapPath'])->toBe('Maps/level');
    expect($scene->sourceData['environmentTileMapPath'])->toBe('Maps/level');
});

test('scene loader normalizes environment collision map paths and supports empty values', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-loader-collision-map-path-' . uniqid();
    mkdir($workspace . '/Assets/Scenes', 0777, true);
    mkdir($workspace . '/vendor', 0777, true);

    file_put_contents($workspace . '/vendor/autoload.php', "<?php\n");
    file_put_contents(
        $workspace . '/Assets/Scenes/level01.scene.php',
        <<<'PHP'
<?php

return [
    'environmentTileMapPath' => 'Maps/level.tmap',
    'environmentCollisionMapPath' => 'Maps/level.collider.tmap',
    'hierarchy' => [],
];
PHP
    );

    $loader = new SceneLoader($workspace);
    $scene = $loader->load(new EditorSceneSettings(active: 0, loaded: ['level01']));

    expect($scene)->not->toBeNull();
    expect($scene->environmentCollisionMapPath)->toBe('Maps/level.collider');
    expect($scene->rawData['environmentCollisionMapPath'])->toBe('Maps/level.collider');
    expect($scene->sourceData['environmentCollisionMapPath'])->toBe('Maps/level.collider');

    file_put_contents(
        $workspace . '/Assets/Scenes/level02.scene.php',
        <<<'PHP'
<?php

return [
    'environmentTileMapPath' => 'Maps/level.tmap',
    'environmentCollisionMapPath' => '',
    'hierarchy' => [],
];
PHP
    );

    $emptyScene = $loader->load(new EditorSceneSettings(active: 0, loaded: ['level02']));

    expect($emptyScene)->not->toBeNull();
    expect($emptyScene->environmentCollisionMapPath)->toBe('');
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

test('scene loader enriches component entries with serialized component data for editor use', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-loader-components-' . uniqid();
    mkdir($workspace . '/assets/Scenes', 0777, true);
    mkdir($workspace . '/vendor', 0777, true);

    file_put_contents(
        $workspace . '/vendor/autoload.php',
        <<<'PHP'
<?php

namespace Sendama\Engine\Core\Behaviours\Attributes {
    #[\Attribute(\Attribute::TARGET_PROPERTY)]
    class SerializeField
    {
        public function __construct(public ?string $name = null)
        {
        }
    }
}

namespace Sendama\Engine\Core {
    use ReflectionObject;
    use Sendama\Engine\Core\Behaviours\Attributes\SerializeField;

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
        public function __construct(public Texture $texture, public array $rect)
        {
        }

        public function __serialize(): array
        {
            return [
                'texture' => $this->texture->path,
                'rect' => $this->rect,
            ];
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
            private ?Sprite $sprite = null,
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

        public function __serialize(): array
        {
            $data = [];
            $properties = (new ReflectionObject($this))->getProperties();

            foreach ($properties as $property) {
                if ($property->isPublic() || $property->getAttributes(SerializeField::class)) {
                    $data[$property->getName()] = $property->getValue($this);
                }
            }

            return $data;
        }
    }
}

namespace Sendama\Game {
    use Sendama\Engine\Core\Behaviours\Attributes\SerializeField;
    use Sendama\Engine\Core\Component;
    use Sendama\Engine\Core\GameObject;
    use Sendama\Engine\Core\Vector2;

    class PlayerController extends Component
    {
        public bool $enabledInEditor = true;

        #[SerializeField]
        protected int $speed = 3;

        public Vector2|array $spawnOffset;

        public function __construct(GameObject $gameObject)
        {
            $this->spawnOffset = new Vector2(2, 1);
            parent::__construct($gameObject);
        }
    }
}
PHP
    );

    file_put_contents(
        $workspace . '/assets/Scenes/level01.scene.php',
        <<<'PHP'
<?php

use Sendama\Engine\Core\GameObject;

return [
    'hierarchy' => [
        [
            'type' => GameObject::class,
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
            'components' => [
                ['class' => 'Sendama\\Game\\PlayerController'],
            ],
        ],
    ],
];
PHP
    );

    $loader = new SceneLoader($workspace);
    $scene = $loader->load(new EditorSceneSettings(active: 0, loaded: ['level01']));

    expect($scene)->not->toBeNull();
    expect($scene->sourceData['hierarchy'][0]['components'])->toBe([
        ['class' => 'Sendama\\Game\\PlayerController'],
    ]);
    expect($scene->hierarchy[0]['components'])->toBe([
        [
            'class' => 'Sendama\\Game\\PlayerController',
            'data' => [
                'enabledInEditor' => true,
                'speed' => 3,
                'spawnOffset' => ['x' => 2, 'y' => 1],
            ],
        ],
    ]);
});

test('scene loader backfills empty saved component data from serialized defaults', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-loader-component-merge-' . uniqid();
    mkdir($workspace . '/assets/Scenes', 0777, true);
    mkdir($workspace . '/vendor', 0777, true);

    file_put_contents(
        $workspace . '/vendor/autoload.php',
        <<<'PHP'
<?php

namespace Sendama\Engine\Core\Behaviours\Attributes {
    #[\Attribute(\Attribute::TARGET_PROPERTY)]
    class SerializeField
    {
        public function __construct(public ?string $name = null)
        {
        }
    }
}

namespace Sendama\Engine\Core\Scenes\Interfaces {
    interface SceneInterface
    {
    }
}

namespace Sendama\Engine\Core\Scenes {
    use Sendama\Engine\Core\Scenes\Interfaces\SceneInterface;

    class SceneManager
    {
        private static ?self $instance = null;

        public static function getInstance(): self
        {
            return self::$instance ??= new self();
        }

        public function getActiveScene(): ?SceneInterface
        {
            return null;
        }
    }
}

namespace Sendama\Engine\Core {
    use ReflectionObject;

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

        public function getScene(): ?\Sendama\Engine\Core\Scenes\Interfaces\SceneInterface
        {
            return null;
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

        public function __serialize(): array
        {
            $data = [];
            $properties = (new ReflectionObject($this))->getProperties();

            foreach ($properties as $property) {
                if ($property->isPublic() || $property->getAttributes(\Sendama\Engine\Core\Behaviours\Attributes\SerializeField::class)) {
                    $data[$property->getName()] = $property->getValue($this);
                }
            }

            return $data;
        }
    }
}

namespace Sendama\Engine\Core\Behaviours {
    use Sendama\Engine\Core\Component;
    use Sendama\Engine\Core\GameObject;
    use Sendama\Engine\Core\Scenes\Interfaces\SceneInterface;

    abstract class Behaviour extends Component
    {
        public SceneInterface $activeScene {
            get {
                $scene = $this->getGameObject()->getScene();

                if (!$scene instanceof SceneInterface) {
                    throw new \RuntimeException('No active scene');
                }

                return $scene;
            }
        }

        public SceneInterface $scene {
            get {
                $scene = $this->getGameObject()->getScene();

                if (!$scene instanceof SceneInterface) {
                    throw new \RuntimeException('No scene');
                }

                return $scene;
            }
        }

        public function __construct(GameObject $gameObject)
        {
            parent::__construct($gameObject);
        }
    }
}

namespace Sendama\Engine\Core {
    class Texture
    {
        public function __construct(public string $path)
        {
        }

        public function __toString(): string
        {
            return $this->path;
        }
    }
}

namespace Sendama\Game {
    use Sendama\Engine\Core\Behaviours\Attributes\SerializeField;
    use Sendama\Engine\Core\Behaviours\Behaviour;
    use Sendama\Engine\Core\Texture;

    class Gun extends Behaviour
    {
        #[SerializeField]
        protected float $fireRate = 0.5;

        #[SerializeField]
        protected int $maxBullets = 10;

        #[SerializeField]
        protected ?Texture $bulletTexture = null;
    }
}
PHP
    );

    file_put_contents(
        $workspace . '/assets/Scenes/level01.scene.php',
        <<<'PHP'
<?php

use Sendama\Engine\Core\GameObject;

return [
    'hierarchy' => [
        [
            'type' => GameObject::class,
            'name' => 'Player',
            'position' => ['x' => 4, 'y' => 12],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'components' => [
                [
                    'class' => 'Sendama\\Game\\Gun',
                    'data' => [],
                ],
            ],
        ],
    ],
];
PHP
    );

    $loader = new SceneLoader($workspace);
    $scene = $loader->load(new EditorSceneSettings(active: 0, loaded: ['level01']));

    expect($scene)->not->toBeNull();
    expect($scene->hierarchy[0]['components'])->toBe([
        [
            'class' => 'Sendama\\Game\\Gun',
            'data' => [
                'fireRate' => 0.5,
                'maxBullets' => 10,
                'bulletTexture' => null,
            ],
            '__editorFieldTypes' => [
                'fireRate' => 'float',
                'maxBullets' => 'int',
                'bulletTexture' => 'Sendama\\Engine\\Core\\Texture|null',
            ],
        ],
    ]);
});

test('scene loader annotates GameObject component fields for prefab assignment', function () {
    $workspace = sys_get_temp_dir() . '/sendama-scene-loader-prefab-field-' . uniqid();
    mkdir($workspace . '/Assets/Scenes', 0777, true);
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

    class Component
    {
        public function __construct(private ?GameObject $gameObject = null)
        {
        }
    }

    class GameObject
    {
        public function __construct(
            private string $name,
            private ?string $tag = null,
            private ?Vector2 $position = null,
            private ?Vector2 $rotation = null,
            private ?Vector2 $scale = null,
            private mixed $sprite = null,
        ) {
        }
    }
}

namespace Sendama\Game\Scripts {
    use Sendama\Engine\Core\Component;
    use Sendama\Engine\Core\GameObject;

    class Gun extends Component
    {
        public ?GameObject $bulletPrefab = null;
        public int $maxBullets = 10;
    }
}
PHP
    );

    file_put_contents(
        $workspace . '/Assets/Scenes/level01.scene.php',
        <<<'PHP'
<?php

use Sendama\Engine\Core\GameObject;
use Sendama\Game\Scripts\Gun;

return [
    'hierarchy' => [
        [
            'type' => GameObject::class,
            'name' => 'Player',
            'components' => [
                [
                    'class' => Gun::class,
                    'data' => [
                        'bulletPrefab' => 'Prefabs/enemy.prefab.php',
                    ],
                ],
            ],
        ],
    ],
];
PHP
    );

    $loader = new SceneLoader($workspace);
    $scene = $loader->load(new EditorSceneSettings(active: 0, loaded: ['level01']));
    $component = $scene?->hierarchy[0]['components'][0] ?? null;

    expect($component)->toBeArray()
        ->and($component['data']['bulletPrefab'] ?? null)->toBe('Prefabs/enemy.prefab.php')
        ->and($component['__editorFieldTypes']['bulletPrefab'] ?? null)->toBe('Sendama\\Engine\\Core\\GameObject|null');
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

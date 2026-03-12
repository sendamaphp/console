<?php

use Sendama\Console\Editor\Widgets\InspectorPanel;
use Sendama\Console\Editor\Widgets\Controls\InputControl;

function focusInspectorPanel(InspectorPanel $panel): void
{
    $hasFocus = new ReflectionProperty(\Sendama\Console\Editor\Widgets\Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);
}

function setInspectorInput(string $current, string $previous = ''): void
{
    $keyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'previousKeyPress');
    $keyPress->setAccessible(true);
    $previousKeyPress->setAccessible(true);
    $previousKeyPress->setValue($previous);
    $keyPress->setValue($current);
}

function selectInspectorControlByLabel(InspectorPanel $panel, string $label): void
{
    $focusableControls = new ReflectionProperty(InspectorPanel::class, 'focusableControls');
    $selectedControlIndex = new ReflectionProperty(InspectorPanel::class, 'selectedControlIndex');
    $applyControlSelection = new ReflectionMethod(InspectorPanel::class, 'applyControlSelection');
    $refreshContent = new ReflectionMethod(InspectorPanel::class, 'refreshContent');
    $focusableControls->setAccessible(true);
    $selectedControlIndex->setAccessible(true);
    $applyControlSelection->setAccessible(true);
    $refreshContent->setAccessible(true);

    /** @var array<int, InputControl> $controls */
    $controls = $focusableControls->getValue($panel);

    foreach ($controls as $index => $control) {
        if ($control->getLabel() !== $label) {
            continue;
        }

        $selectedControlIndex->setValue($panel, $index);
        $applyControlSelection->invoke($panel);
        $refreshContent->invoke($panel);
        return;
    }

    throw new RuntimeException('Unable to locate inspector control labeled ' . $label);
}

function inspectorComponentHeaders(InspectorPanel $panel): array
{
    return array_values(array_filter(
        $panel->content,
        static fn(string $line): bool => str_starts_with($line, '▼ ')
            && !in_array($line, ['▼ Transform', '▼ Renderer'], true),
    ));
}

function createInspectorComponentWorkspace(): string
{
    $workspace = sys_get_temp_dir() . '/sendama-inspector-components-' . uniqid();
    mkdir($workspace . '/Assets/Scripts', 0777, true);
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

namespace Sendama\Engine\Core\Behaviours {
    use Sendama\Engine\Core\Component;
    use Sendama\Engine\Core\GameObject;

    abstract class Behaviour extends Component
    {
        public function __construct(GameObject $gameObject)
        {
            parent::__construct($gameObject);
        }
    }
}

namespace {
    require __DIR__ . '/../Assets/Scripts/PlayerController.php';
}
PHP
    );

    file_put_contents(
        $workspace . '/Assets/Scripts/PlayerController.php',
        <<<'PHP'
<?php

namespace Sendama\Game\Scripts;

use Sendama\Engine\Core\Behaviours\Attributes\SerializeField;
use Sendama\Engine\Core\Behaviours\Behaviour;

class PlayerController extends Behaviour
{
    public int $speed = 2;

    #[SerializeField]
    protected int $lives = 3;
}
PHP
    );

    return $workspace;
}

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

test('inspector panel styles focused section headers with a light blue background', function () {
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
            'position' => ['x' => 0, 'y' => 0],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'components' => [],
        ],
    ]);

    $hasFocus = new ReflectionProperty(\Sendama\Console\Editor\Widgets\Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    for ($index = 0; $index < 3; $index++) {
        $panel->cycleFocusForward();
    }

    $decorateContentLine = new ReflectionMethod($panel, 'decorateContentLine');
    $decorateContentLine->setAccessible(true);
    $line = '|' . str_pad($panel->content[3], $panelWidth - 2) . '|';
    $renderedLine = $decorateContentLine->invoke($panel, $line, null, 3);

    expect($renderedLine)->toContain("\033[30;104m");
});

test('inspector panel styles component headers with a warm highlight in component move mode', function () {
    $panelWidth = 40;
    $panel = new InspectorPanel(width: $panelWidth, height: 18);

    $panel->inspectTarget([
        'context' => 'hierarchy',
        'name' => 'Player',
        'type' => 'GameObject',
        'path' => 'scene.0',
        'value' => [
            'type' => 'GameObject::class',
            'name' => 'Player',
            'tag' => 'Player',
            'position' => ['x' => 0, 'y' => 0],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'components' => [
                ['class' => 'Sendama\\Game\\AlphaComponent', 'data' => ['speed' => 2]],
            ],
        ],
    ]);

    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'AlphaComponent');

    setInspectorInput('W');
    $panel->update();

    $lineIndex = array_search('▼ AlphaComponent', $panel->content, true);
    expect($lineIndex)->not->toBeFalse();

    $decorateContentLine = new ReflectionMethod($panel, 'decorateContentLine');
    $decorateContentLine->setAccessible(true);
    $line = '|' . str_pad($panel->content[$lineIndex], $panelWidth - 2) . '|';
    $renderedLine = $decorateContentLine->invoke($panel, $line, null, $lineIndex);

    expect($renderedLine)->toContain("\033[5;30;43m");
});

test('inspector panel renders serialized component data with typed controls', function () {
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
            'components' => [
                [
                    'class' => 'Sendama\\Game\\PlayerController',
                    'data' => [
                        'isPlayerControlled' => true,
                        'maxBullets' => 10,
                        'spawnOffset' => ['x' => 2, 'y' => 1],
                    ],
                ],
            ],
        ],
    ]);

    expect($panel->content)->toContain('▼ PlayerController');
    expect($panel->content)->toContain('  Is Player Controlled: [x]');
    expect($panel->content)->toContain('  Max Bullets: 10');
    expect($panel->content)->toContain('  Spawn Offset:');
    expect($panel->content)->toContain('    X: 2');
    expect($panel->content)->toContain('    Y: 1');
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

test('inspector panel text edit supports repeated backspace input while held', function () {
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

    foreach (str_split('12') as $character) {
        $previousKeyPress->setValue('');
        $keyPress->setValue($character);
        $panel->update();
    }

    $previousKeyPress->setValue('');
    $keyPress->setValue("\177");
    $panel->update();

    $previousKeyPress->setValue("\177");
    $keyPress->setValue("\177");
    $panel->update();

    $previousKeyPress->setValue('');
    $keyPress->setValue("\n");
    $panel->update();

    expect($panel->consumeAssetMutation())->toBe([
        'path' => '/tmp/project/Assets/Textures/player.texture',
        'relativePath' => 'Textures/player.texture',
        'name' => 'player.texture',
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

test('inspector panel toggles focused sections with slash', function () {
    $panel = new InspectorPanel(width: 48, height: 24);

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
            'components' => [
                [
                    'class' => 'Sendama\\Game\\PlayerController',
                    'data' => [
                        'maxBullets' => 10,
                    ],
                ],
            ],
        ],
    ]);

    $hasFocus = new ReflectionProperty(\Sendama\Console\Editor\Widgets\Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    $keyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'previousKeyPress');
    $keyPress->setAccessible(true);
    $previousKeyPress->setAccessible(true);

    for ($index = 0; $index < 3; $index++) {
        $panel->cycleFocusForward();
    }

    expect($panel->content)->toContain('▼ Transform');
    expect($panel->content)->toContain('  Position:');

    $previousKeyPress->setValue('');
    $keyPress->setValue('/');
    $panel->update();

    expect($panel->content)->toContain('▶ Transform');
    expect($panel->content)->not->toContain('  Position:');

    $previousKeyPress->setValue('');
    $keyPress->setValue('/');
    $panel->update();

    expect($panel->content)->toContain('▼ Transform');
    expect($panel->content)->toContain('  Position:');
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

test('inspector panel opens a component menu with shift+a and appends the selected component', function () {
    $workspace = createInspectorComponentWorkspace();
    $panel = new InspectorPanel(width: 48, height: 24, workingDirectory: $workspace);
    $panel->setSceneHierarchy([]);

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
            'components' => [],
        ],
    ]);

    $hasFocus = new ReflectionProperty(\Sendama\Console\Editor\Widgets\Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);

    $keyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'previousKeyPress');
    $keyPress->setAccessible(true);
    $previousKeyPress->setAccessible(true);

    $previousKeyPress->setValue('');
    $keyPress->setValue('A');
    $panel->update();

    expect($panel->hasActiveModal())->toBeTrue();

    $previousKeyPress->setValue('');
    $keyPress->setValue("\n");
    $panel->update();

    expect($panel->hasActiveModal())->toBeFalse();
    expect($panel->content)->toContain('▼ PlayerController');
    expect($panel->content)->toContain('  Speed: 2');
    expect($panel->content)->toContain('  Lives: 3');
    expect($panel->consumeHierarchyMutation())->toBe([
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Player',
            'tag' => 'Player',
            'position' => ['x' => 4, 'y' => 12],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'components' => [
                [
                    'class' => 'Sendama\\Game\\Scripts\\PlayerController',
                    'data' => [
                        'speed' => 2,
                        'lives' => 3,
                    ],
                ],
            ],
        ],
    ]);
});

test('inspector panel opens a delete modal for the selected component header and cancels safely', function () {
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
            'components' => [
                ['class' => 'Sendama\\Game\\AlphaComponent', 'data' => ['speed' => 2]],
                ['class' => 'Sendama\\Game\\BetaComponent', 'data' => ['power' => 3]],
            ],
        ],
    ]);

    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'BetaComponent');

    setInspectorInput("\033[3~");
    $panel->update();

    expect($panel->hasActiveModal())->toBeTrue();

    setInspectorInput("\n");
    $panel->update();

    expect($panel->hasActiveModal())->toBeFalse();
    expect($panel->consumeHierarchyMutation())->toBeNull();
    expect($panel->content)->toContain('▼ AlphaComponent');
    expect($panel->content)->toContain('▼ BetaComponent');
});

test('inspector panel removes the selected component when deletion is confirmed', function () {
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
            'components' => [
                ['class' => 'Sendama\\Game\\AlphaComponent', 'data' => ['speed' => 2]],
                ['class' => 'Sendama\\Game\\BetaComponent', 'data' => ['power' => 3]],
                ['class' => 'Sendama\\Game\\GammaComponent', 'data' => ['range' => 4]],
            ],
        ],
    ]);

    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'BetaComponent');

    setInspectorInput("\033[3~");
    $panel->update();

    setInspectorInput("\033[A");
    $panel->update();

    setInspectorInput("\n");
    $panel->update();

    expect($panel->hasActiveModal())->toBeFalse();
    expect($panel->content)->toContain('▼ AlphaComponent');
    expect($panel->content)->not->toContain('▼ BetaComponent');
    expect($panel->content)->toContain('▼ GammaComponent');
    expect($panel->consumeHierarchyMutation())->toBe([
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Player',
            'tag' => 'Player',
            'position' => ['x' => 4, 'y' => 12],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'components' => [
                ['class' => 'Sendama\\Game\\AlphaComponent', 'data' => ['speed' => 2]],
                ['class' => 'Sendama\\Game\\GammaComponent', 'data' => ['range' => 4]],
            ],
        ],
    ]);
});

test('inspector panel reorders components upward with wraparound in move mode', function () {
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
            'components' => [
                ['class' => 'Sendama\\Game\\AlphaComponent', 'data' => ['speed' => 2]],
                ['class' => 'Sendama\\Game\\BetaComponent', 'data' => ['power' => 3]],
                ['class' => 'Sendama\\Game\\GammaComponent', 'data' => ['range' => 4]],
            ],
        ],
    ]);

    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'AlphaComponent');

    setInspectorInput('W');
    $panel->update();

    setInspectorInput("\033[A");
    $panel->update();

    expect(inspectorComponentHeaders($panel))->toBe([
        '▼ BetaComponent',
        '▼ GammaComponent',
        '▼ AlphaComponent',
    ]);
    expect($panel->consumeHierarchyMutation())->toBe([
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Player',
            'tag' => 'Player',
            'position' => ['x' => 4, 'y' => 12],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'components' => [
                ['class' => 'Sendama\\Game\\BetaComponent', 'data' => ['power' => 3]],
                ['class' => 'Sendama\\Game\\GammaComponent', 'data' => ['range' => 4]],
                ['class' => 'Sendama\\Game\\AlphaComponent', 'data' => ['speed' => 2]],
            ],
        ],
    ]);
});

test('inspector panel reorders components downward with wraparound in move mode', function () {
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
            'components' => [
                ['class' => 'Sendama\\Game\\AlphaComponent', 'data' => ['speed' => 2]],
                ['class' => 'Sendama\\Game\\BetaComponent', 'data' => ['power' => 3]],
                ['class' => 'Sendama\\Game\\GammaComponent', 'data' => ['range' => 4]],
            ],
        ],
    ]);

    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'GammaComponent');

    setInspectorInput('W');
    $panel->update();

    setInspectorInput("\033[B");
    $panel->update();

    expect(inspectorComponentHeaders($panel))->toBe([
        '▼ GammaComponent',
        '▼ AlphaComponent',
        '▼ BetaComponent',
    ]);
    expect($panel->consumeHierarchyMutation())->toBe([
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Player',
            'tag' => 'Player',
            'position' => ['x' => 4, 'y' => 12],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'components' => [
                ['class' => 'Sendama\\Game\\GammaComponent', 'data' => ['range' => 4]],
                ['class' => 'Sendama\\Game\\AlphaComponent', 'data' => ['speed' => 2]],
                ['class' => 'Sendama\\Game\\BetaComponent', 'data' => ['power' => 3]],
            ],
        ],
    ]);
});

test('inspector panel keeps reordering components while move mode remains active', function () {
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
            'components' => [
                ['class' => 'Sendama\\Game\\AlphaComponent', 'data' => ['speed' => 2]],
                ['class' => 'Sendama\\Game\\BetaComponent', 'data' => ['power' => 3]],
                ['class' => 'Sendama\\Game\\GammaComponent', 'data' => ['range' => 4]],
            ],
        ],
    ]);

    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'AlphaComponent');

    setInspectorInput('W');
    $panel->update();

    setInspectorInput("\033[A");
    $panel->update();

    expect(inspectorComponentHeaders($panel))->toBe([
        '▼ BetaComponent',
        '▼ GammaComponent',
        '▼ AlphaComponent',
    ]);

    setInspectorInput("\033[A", "\033[A");
    $panel->update();

    expect(inspectorComponentHeaders($panel))->toBe([
        '▼ AlphaComponent',
        '▼ BetaComponent',
        '▼ GammaComponent',
    ]);
    expect($panel->consumeHierarchyMutation())->toBe([
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Player',
            'tag' => 'Player',
            'position' => ['x' => 4, 'y' => 12],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'components' => [
                ['class' => 'Sendama\\Game\\AlphaComponent', 'data' => ['speed' => 2]],
                ['class' => 'Sendama\\Game\\BetaComponent', 'data' => ['power' => 3]],
                ['class' => 'Sendama\\Game\\GammaComponent', 'data' => ['range' => 4]],
            ],
        ],
    ]);
});

test('inspector panel preserves component move mode across hierarchy syncs', function () {
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
            'components' => [
                ['class' => 'Sendama\\Game\\AlphaComponent', 'data' => ['speed' => 2]],
                ['class' => 'Sendama\\Game\\BetaComponent', 'data' => ['power' => 3]],
                ['class' => 'Sendama\\Game\\GammaComponent', 'data' => ['range' => 4]],
            ],
        ],
    ]);

    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'AlphaComponent');

    setInspectorInput('W');
    $panel->update();

    setInspectorInput("\033[A");
    $panel->update();

    $firstMutation = $panel->consumeHierarchyMutation();
    expect($firstMutation)->toBe([
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Player',
            'tag' => 'Player',
            'position' => ['x' => 4, 'y' => 12],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'components' => [
                ['class' => 'Sendama\\Game\\BetaComponent', 'data' => ['power' => 3]],
                ['class' => 'Sendama\\Game\\GammaComponent', 'data' => ['range' => 4]],
                ['class' => 'Sendama\\Game\\AlphaComponent', 'data' => ['speed' => 2]],
            ],
        ],
    ]);

    $panel->syncHierarchyTarget($firstMutation['path'], $firstMutation['value']);

    setInspectorInput("\033[A", "\033[A");
    $panel->update();

    expect(inspectorComponentHeaders($panel))->toBe([
        '▼ AlphaComponent',
        '▼ BetaComponent',
        '▼ GammaComponent',
    ]);
    expect($panel->consumeHierarchyMutation())->toBe([
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Player',
            'tag' => 'Player',
            'position' => ['x' => 4, 'y' => 12],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'components' => [
                ['class' => 'Sendama\\Game\\AlphaComponent', 'data' => ['speed' => 2]],
                ['class' => 'Sendama\\Game\\BetaComponent', 'data' => ['power' => 3]],
                ['class' => 'Sendama\\Game\\GammaComponent', 'data' => ['range' => 4]],
            ],
        ],
    ]);
});

test('inspector panel updates help text for control selection and component move mode', function () {
    $panel = new InspectorPanel(width: 64, height: 24);

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
            'components' => [
                ['class' => 'Sendama\\Game\\AlphaComponent', 'data' => ['speed' => 2]],
            ],
        ],
    ]);

    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'AlphaComponent');

    $help = new ReflectionProperty(InspectorPanel::class, 'help');
    $modeHelpLabel = new ReflectionProperty(InspectorPanel::class, 'modeHelpLabel');
    $help->setAccessible(true);
    $modeHelpLabel->setAccessible(true);

    expect($help->getValue($panel))->toBe('Up/Down select  / toggle  Shift+A add  Shift+W move  Del remove');
    expect($modeHelpLabel->getValue($panel))->toBe('Mode: Control Select');

    setInspectorInput('W');
    $panel->update();

    expect($help->getValue($panel))->toBe('Up/Down reorder  Shift+W done  Esc cancel');
    expect($modeHelpLabel->getValue($panel))->toBe('Mode: Component Move');
});

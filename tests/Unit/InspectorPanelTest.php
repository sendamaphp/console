<?php

use Atatusoft\Termutil\Events\MouseEvent;
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

function selectedInspectorControlLabel(InspectorPanel $panel): ?string
{
    $focusableControls = new ReflectionProperty(InspectorPanel::class, 'focusableControls');
    $selectedControlIndex = new ReflectionProperty(InspectorPanel::class, 'selectedControlIndex');
    $focusableControls->setAccessible(true);
    $selectedControlIndex->setAccessible(true);

    /** @var array<int, InputControl> $controls */
    $controls = $focusableControls->getValue($panel);
    $selectedIndex = $selectedControlIndex->getValue($panel);

    if (!is_int($selectedIndex) || !isset($controls[$selectedIndex])) {
        return null;
    }

    return $controls[$selectedIndex]->getLabel();
}

function getInspectorContentAreaPosition(InspectorPanel $panel): array
{
    $getContentAreaLeft = new ReflectionMethod($panel, 'getContentAreaLeft');
    $getContentAreaTop = new ReflectionMethod($panel, 'getContentAreaTop');

    return [
        'x' => $getContentAreaLeft->invoke($panel),
        'y' => $getContentAreaTop->invoke($panel),
    ];
}

function getWidgetContentAreaPosition(object $widget): array
{
    $getContentAreaLeft = new ReflectionMethod($widget, 'getContentAreaLeft');
    $getContentAreaTop = new ReflectionMethod($widget, 'getContentAreaTop');

    return [
        'x' => $getContentAreaLeft->invoke($widget),
        'y' => $getContentAreaTop->invoke($widget),
    ];
}

function createMousePressEvent(int $x, int $y, int $buttonIndex = 0): MouseEvent
{
    return new MouseEvent(sprintf("\033[<%d;%d;%dM", $buttonIndex, $x, $y));
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

function createInspectorStandardComponentWorkspace(): string
{
    $workspace = sys_get_temp_dir() . '/sendama-inspector-standard-components-' . uniqid();
    mkdir($workspace . '/Assets', 0777, true);
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

namespace Sendama\Engine\Core\Behaviours {
    use Sendama\Engine\Core\Component;
    use Sendama\Engine\Core\GameObject;

    class CharacterMovement extends Component
    {
        public int $speed = 1;

        public function __construct(GameObject $gameObject)
        {
            parent::__construct($gameObject);
        }
    }

    class SimpleQuitListener extends Component
    {
        public function __construct(GameObject $gameObject)
        {
            parent::__construct($gameObject);
        }
    }

    class SimpleBackListener extends Component
    {
        public function __construct(GameObject $gameObject)
        {
            parent::__construct($gameObject);
        }
    }
}

namespace Sendama\Engine\Physics {
    use Sendama\Engine\Core\Behaviours\Attributes\SerializeField;
    use Sendama\Engine\Core\Component;
    use Sendama\Engine\Core\GameObject;

    class Collider extends Component
    {
        #[SerializeField]
        protected bool $isTrigger = false;

        public function __construct(GameObject $gameObject)
        {
            parent::__construct($gameObject);
        }
    }

    class CharacterController extends Component
    {
        public bool $isTrigger = false;

        public function __construct(GameObject $gameObject)
        {
            parent::__construct($gameObject);
        }
    }

    class Rigidbody extends Component
    {
        public int $mass = 1;

        public function __construct(GameObject $gameObject)
        {
            parent::__construct($gameObject);
        }
    }
}

namespace Sendama\Engine\Animation {
    use Sendama\Engine\Core\Component;
    use Sendama\Engine\Core\GameObject;

    class AnimationController extends Component
    {
        public function __construct(GameObject $gameObject)
        {
            parent::__construct($gameObject);
        }
    }
}
PHP
    );

    return $workspace;
}

function createInspectorCompoundComponentWorkspace(): string
{
    $workspace = sys_get_temp_dir() . '/sendama-inspector-compound-components-' . uniqid();
    mkdir($workspace . '/Assets/Scripts', 0777, true);
    mkdir($workspace . '/vendor', 0777, true);

    file_put_contents(
        $workspace . '/vendor/autoload.php',
        <<<'PHP'
<?php

namespace Sendama\Engine\Core\Attributes {
    #[\Attribute(\Attribute::TARGET_PROPERTY)]
    class Range
    {
        public function __construct(
            public int|float $min,
            public int|float $max,
            public int|float $step = 1,
        ) {
        }
    }
}

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
    }

    abstract class Component
    {
        public function __construct(private readonly GameObject $gameObject)
        {
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
    require __DIR__ . '/../Assets/Scripts/SchemaProbe.php';
}
PHP
    );

    file_put_contents(
        $workspace . '/Assets/Scripts/SchemaProbe.php',
        <<<'PHP'
<?php

namespace Sendama\Game\Scripts;

use Sendama\Engine\Core\Attributes\Range;
use Sendama\Engine\Core\Behaviours\Behaviour;
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

class SchemaProbe extends Behaviour
{
    #[Range(min: 0, max: 10)]
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

test('inspector panel renders gui texture controls for gui texture ui elements', function () {
    $workspace = sys_get_temp_dir() . '/sendama-inspector-gui-texture-' . uniqid();
    mkdir($workspace . '/Assets/Textures', 0777, true);
    file_put_contents($workspace . '/Assets/Textures/hud.texture', "##\n@@\n");

    $panel = new InspectorPanel(width: 40, height: 18, workingDirectory: $workspace);

    $panel->inspectTarget([
        'context' => 'hierarchy',
        'name' => 'HUD Logo',
        'type' => 'GUITexture',
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\UI\\GUITexture\\GUITexture',
            'name' => 'HUD Logo',
            'tag' => 'UI',
            'position' => ['x' => 1, 'y' => 1],
            'size' => ['x' => 2, 'y' => 2],
            'texture' => 'Textures/hud',
            'color' => 'Yellow',
        ],
    ]);

    $content = implode("\n", $panel->content);

    expect($content)->toContain('▼ Texture')
        ->toContain('Texture: Textures/hud')
        ->toContain('Color: <Yellow>')
        ->toContain('Preview:')
        ->toContain('##')
        ->toContain('@@')
        ->not->toContain('▼ Renderer');
});

test('inspector panel filters ui element reference pickers to gui textures when the field is typed as gui texture', function () {
    $panel = new InspectorPanel(width: 48, height: 24);
    $uiElementReferenceOptions = new ReflectionProperty(InspectorPanel::class, 'uiElementReferenceOptions');
    $uiElementReferenceModal = new ReflectionProperty(InspectorPanel::class, 'uiElementReferenceModal');
    $uiElementReferenceOptions->setAccessible(true);
    $uiElementReferenceModal->setAccessible(true);

    $panel->setSceneHierarchy([
        [
            'type' => 'Sendama\\Engine\\UI\\Label\\Label',
            'name' => 'Score',
            'text' => 'Score: 0',
        ],
        [
            'type' => 'Sendama\\Engine\\UI\\GUITexture\\GUITexture',
            'name' => 'Heart #1',
            'texture' => 'Textures/heart.texture',
        ],
        [
            'type' => 'Sendama\\Engine\\UI\\GUITexture\\GUITexture',
            'name' => 'Heart #2',
            'texture' => 'Textures/heart.texture',
        ],
    ]);

    focusInspectorPanel($panel);
    $panel->inspectTarget([
        'context' => 'hierarchy',
        'name' => 'Level Manager',
        'type' => 'GameObject',
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Level Manager',
            'components' => [
                [
                    'class' => 'Sendama\\Game\\Scripts\\LevelController',
                    'data' => [
                        'heart1' => null,
                    ],
                    '__editorFieldTypes' => [
                        'heart1' => 'Sendama\\Engine\\UI\\GUITexture\\GUITexture|null',
                    ],
                ],
            ],
        ],
    ]);

    selectInspectorControlByLabel($panel, 'Heart1');
    setInspectorInput('enter');
    $panel->update();

    /** @var array<string, array{name: string, type: string, display: string}> $options */
    $options = $uiElementReferenceOptions->getValue($panel);
    $modal = $uiElementReferenceModal->getValue($panel);

    expect($modal->isVisible())->toBeTrue()
        ->and(array_values(array_map(
            static fn(array $option): string => $option['name'],
            $options,
        )))->toBe(['Heart #1', 'Heart #2']);
});

test('inspector panel allows generic ui element fields to pick from all scene ui elements', function () {
    $panel = new InspectorPanel(width: 48, height: 24);
    $uiElementReferenceOptions = new ReflectionProperty(InspectorPanel::class, 'uiElementReferenceOptions');
    $inspectionTarget = new ReflectionProperty(InspectorPanel::class, 'inspectionTarget');
    $uiElementReferenceOptions->setAccessible(true);
    $inspectionTarget->setAccessible(true);

    $panel->setSceneHierarchy([
        [
            'type' => 'Sendama\\Engine\\UI\\Label\\Label',
            'name' => 'Score',
            'text' => 'Score: 0',
        ],
        [
            'type' => 'Sendama\\Engine\\UI\\GUITexture\\GUITexture',
            'name' => 'Heart #1',
            'texture' => 'Textures/heart.texture',
        ],
    ]);

    focusInspectorPanel($panel);
    $panel->inspectTarget([
        'context' => 'hierarchy',
        'name' => 'Level Manager',
        'type' => 'GameObject',
        'path' => 'scene.0',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Level Manager',
            'components' => [
                [
                    'class' => 'Sendama\\Game\\Scripts\\LevelController',
                    'data' => [
                        'statusUi' => null,
                    ],
                    '__editorFieldTypes' => [
                        'statusUi' => 'Sendama\\Engine\\UI\\UIElement|null',
                    ],
                ],
            ],
        ],
    ]);

    selectInspectorControlByLabel($panel, 'Status Ui');
    setInspectorInput('enter');
    $panel->update();

    /** @var array<string, array{name: string, type: string, display: string}> $options */
    $options = $uiElementReferenceOptions->getValue($panel);
    $scoreLabel = array_key_first(array_filter(
        $options,
        static fn(array $option): bool => $option['name'] === 'Score',
    ));

    expect(array_values(array_map(
        static fn(array $option): string => $option['name'],
        $options,
    )))->toBe(['Heart #1', 'Score']);

    expect($scoreLabel)->toBeString();

    $applyUIElementReferenceSelection = new ReflectionMethod(InspectorPanel::class, 'applyUIElementReferenceSelection');
    $applyUIElementReferenceSelection->setAccessible(true);
    $applyUIElementReferenceSelection->invoke($panel, $scoreLabel);

    expect($inspectionTarget->getValue($panel)['value']['components'][0]['data']['statusUi'] ?? null)->toBe('Score');
});

test('inspector panel enters edit mode when a control is double clicked', function () {
    $panel = new InspectorPanel(width: 48, height: 24);
    $interactionState = new ReflectionProperty(InspectorPanel::class, 'interactionState');
    $interactionState->setAccessible(true);

    focusInspectorPanel($panel);
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

    $contentArea = getInspectorContentAreaPosition($panel);
    $nameLineIndex = array_search('Name: Player', $panel->content, true);

    expect($nameLineIndex)->toBeInt();

    $panel->handleMouseClick($contentArea['x'] + 1, $contentArea['y'] + $nameLineIndex);
    $panel->handleMouseClick($contentArea['x'] + 1, $contentArea['y'] + $nameLineIndex);

    expect(selectedInspectorControlLabel($panel))->toBe('Name');
    expect($interactionState->getValue($panel))->toBe('control_edit');
});

test('inspector panel path selection modals can be operated with the mouse', function () {
    $workspace = sys_get_temp_dir() . '/sendama-inspector-path-modal-' . uniqid();
    mkdir($workspace . '/Assets/Maps', 0777, true);
    file_put_contents($workspace . '/Assets/Maps/level.tmap', "xxxx\n");

    $panel = new InspectorPanel(width: 48, height: 24, workingDirectory: $workspace);
    $pathInputActionModal = new ReflectionProperty(InspectorPanel::class, 'pathInputActionModal');
    $fileDialogModal = new ReflectionProperty(InspectorPanel::class, 'fileDialogModal');
    $pathInputActionModal->setAccessible(true);
    $fileDialogModal->setAccessible(true);

    focusInspectorPanel($panel);
    $panel->inspectTarget([
        'context' => 'scene',
        'name' => 'Level',
        'type' => 'Scene',
        'path' => 'scene',
        'value' => [
            'name' => 'Level',
            'width' => 80,
            'height' => 25,
            'environmentTileMapPath' => '',
            'environmentCollisionMapPath' => '',
        ],
    ]);

    selectInspectorControlByLabel($panel, 'Map');
    setInspectorInput("\n");
    $panel->update();

    /** @var object $actionModal */
    $actionModal = $pathInputActionModal->getValue($panel);
    $actionContentArea = getWidgetContentAreaPosition($actionModal);
    $panel->handleModalMouseEvent(createMousePressEvent($actionContentArea['x'] + 1, $actionContentArea['y']));

    /** @var object $dialogModal */
    $dialogModal = $fileDialogModal->getValue($panel);
    $dialogContentArea = getWidgetContentAreaPosition($dialogModal);

    $panel->handleModalMouseEvent(createMousePressEvent($dialogContentArea['x'], $dialogContentArea['y']));
    $panel->handleModalMouseEvent(createMousePressEvent($dialogContentArea['x'] + 3, $dialogContentArea['y'] + 1));
    $panel->handleModalMouseEvent(createMousePressEvent($dialogContentArea['x'] + 3, $dialogContentArea['y'] + 1));

    expect($panel->hasActiveModal())->toBeFalse();
    expect($panel->consumeHierarchyMutation())->toBe([
        'path' => 'scene',
        'value' => [
            'name' => 'Level',
            'width' => 80,
            'height' => 25,
            'environmentTileMapPath' => 'Maps/level.tmap',
            'environmentCollisionMapPath' => '',
        ],
    ]);
});

test('inspector panel add component workflow includes standard engine components without project scripts', function () {
    $workspace = createInspectorStandardComponentWorkspace();
    $panel = new InspectorPanel(width: 48, height: 24, workingDirectory: $workspace);
    $showAddComponentModal = new ReflectionMethod(InspectorPanel::class, 'showAddComponentModal');
    $showAddComponentModal->setAccessible(true);
    $addComponentModal = new ReflectionProperty(InspectorPanel::class, 'addComponentModal');
    $addComponentModal->setAccessible(true);

    focusInspectorPanel($panel);
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

    $showAddComponentModal->invoke($panel);

    $modal = $addComponentModal->getValue($panel);

    expect($modal->content)->toContain('> AnimationController')
        ->toContain('  CharacterController')
        ->toContain('  CharacterMovement')
        ->toContain('  Collider')
        ->toContain('  Rigidbody')
        ->toContain('  SimpleBackListener')
        ->toContain('  SimpleQuitListener');
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

    expect($renderedLine)->toContain("\033[97;100m");
});

test('inspector panel styles focused section headers with the primary accent background', function () {
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

    expect($renderedLine)->toContain("\033[30;101m");
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

    expect($renderedTypeLine)->toContain("\033[30;101m");

    $panel->cycleFocusForward();

    $nameLine = '|' . str_pad($panel->content[1], $panelWidth - 2) . '|';
    $renderedNameLine = $decorateContentLine->invoke($panel, $nameLine, null, 1);

    expect($renderedNameLine)->toContain("\033[30;101m");
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

    expect($renderedNameLine)->toContain("\033[30;101m");
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
            'environmentCollisionMapPath' => 'Maps/level.collider',
        ],
    ]);

    expect($panel->content)->toBe([
        'Type: Scene',
        'Name: level01',
        'Width: 80',
        'Height: 25',
        'Map: Maps/level',
        'Collider: Maps/level.collider',
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

test('inspector panel requests a background refresh when a larger file dialog closes back to the path action modal', function () {
    $workspace = sys_get_temp_dir() . '/sendama-inspector-path-refresh-' . uniqid();
    mkdir($workspace . '/Assets/Maps', 0777, true);
    file_put_contents($workspace . '/Assets/Maps/example.tmap', "xx\n");
    $panel = new InspectorPanel(width: 48, height: 24, workingDirectory: $workspace);

    $panel->inspectTarget([
        'context' => 'scene',
        'name' => 'level01',
        'type' => 'Scene',
        'path' => 'scene',
        'value' => [
            'name' => 'level01',
            'width' => 80,
            'height' => 25,
            'environmentTileMapPath' => 'Maps/example',
        ],
    ]);

    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'Map');

    setInspectorInput("\n");
    $panel->update();

    setInspectorInput("\n");
    $panel->update();

    setInspectorInput(chr(27));
    $panel->update();

    expect($panel->consumeModalBackgroundRefreshRequest())->toBeTrue()
        ->and($panel->hasActiveModal())->toBeTrue();
});

test('inspector panel opens a prefab picker for GameObject component fields and saves the selected prefab path', function () {
    $workspace = sys_get_temp_dir() . '/sendama-inspector-prefab-reference-' . uniqid();
    mkdir($workspace . '/Assets/Prefabs', 0777, true);
    mkdir($workspace . '/assets/Prefabs', 0777, true);
    mkdir($workspace . '/vendor', 0777, true);

    file_put_contents(
        $workspace . '/vendor/autoload.php',
        <<<'PHP'
<?php

namespace Sendama\Engine\Core {
    class GameObject
    {
    }
}
PHP
    );

    file_put_contents(
        $workspace . '/assets/Prefabs/enemy.prefab.php',
        <<<'PHP'
<?php

use Sendama\Engine\Core\GameObject;

return [
    'type' => GameObject::class,
    'name' => 'Enemy',
];
PHP
    );

    $panel = new InspectorPanel(width: 48, height: 24, workingDirectory: $workspace);
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
                    'class' => 'Sendama\\Game\\Scripts\\Gun',
                    'data' => [
                        'bulletPrefab' => null,
                    ],
                    '__editorFieldTypes' => [
                        'bulletPrefab' => 'Sendama\\Engine\\Core\\GameObject|null',
                    ],
                ],
            ],
        ],
    ]);

    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'Bullet Prefab');

    expect($panel->content)->toContain('  Bullet Prefab: None');

    setInspectorInput("\n");
    $panel->update();

    expect($panel->hasActiveModal())->toBeTrue();

    setInspectorInput("\033[B");
    $panel->update();

    setInspectorInput("\n");
    $panel->update();

    $mutation = $panel->consumeHierarchyMutation();

    expect($mutation['path'] ?? null)->toBe('scene.0')
        ->and($mutation['value']['components'][0]['class'] ?? null)->toBe('Sendama\\Game\\Scripts\\Gun')
        ->and($mutation['value']['components'][0]['data']['bulletPrefab'] ?? null)->toBe('Prefabs/enemy.prefab.php')
        ->and($mutation['value']['components'][0]['__editorFieldTypes']['bulletPrefab'] ?? null)->toBe('Sendama\\Engine\\Core\\GameObject|null');

    expect(implode("\n", $panel->content))->toContain('Bullet Prefab: Enemy');
});

test('inspector panel treats Texture component fields as texture path pickers', function () {
    $workspace = sys_get_temp_dir() . '/sendama-inspector-texture-reference-' . uniqid();
    mkdir($workspace . '/Assets/Textures', 0777, true);
    file_put_contents($workspace . '/Assets/Textures/bullet.texture', "<>\n");

    $panel = new InspectorPanel(width: 48, height: 24, workingDirectory: $workspace);
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
                    'class' => 'Sendama\\Game\\Scripts\\Gun',
                    'data' => [
                        'bulletTexture' => null,
                    ],
                    '__editorFieldTypes' => [
                        'bulletTexture' => 'Sendama\\Engine\\Core\\Texture|null',
                    ],
                ],
            ],
        ],
    ]);

    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'Bullet Texture');

    expect($panel->content)->toContain('  Bullet Texture: None');

    setInspectorInput("\n");
    $panel->update();

    expect($panel->hasActiveModal())->toBeTrue();
});

test('inspector panel renders typed Vector2 component fields as compound controls', function () {
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
                    'class' => 'Sendama\\Game\\Scripts\\Spawner',
                    'data' => [
                        'spawnOffset' => null,
                    ],
                    '__editorFieldTypes' => [
                        'spawnOffset' => 'Sendama\\Engine\\Core\\Vector2|null',
                    ],
                ],
            ],
        ],
    ]);

    expect($panel->content)->toContain('▼ Spawner')
        ->toContain('  Spawn Offset:')
        ->toContain('    X: 0')
        ->toContain('    Y: 0');
});

test('inspector panel renders range fields as sliders and nested compound structures as controls', function () {
    $workspace = createInspectorCompoundComponentWorkspace();
    $panel = new InspectorPanel(width: 64, height: 32, workingDirectory: $workspace);
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
                    'class' => 'Sendama\\Game\\Scripts\\SchemaProbe',
                    'data' => [
                        'speed' => 4,
                        'waypoints' => [
                            ['x' => 1, 'y' => 2],
                            ['x' => 3, 'y' => 4],
                        ],
                        'settings' => [
                            'waves' => 3,
                            'origin' => ['x' => 6, 'y' => 7],
                        ],
                    ],
                    '__editorFieldTypes' => [
                        'speed' => 'int',
                        'waypoints' => 'array',
                        'settings' => 'Sendama\\Game\\Scripts\\CompoundSettings',
                    ],
                ],
            ],
        ],
    ]);

    expect($panel->content)->toContain('▼ SchemaProbe')
        ->toContain('  Speed: [#####-------] 4')
        ->toContain('  ▼ Waypoints')
        ->toContain('    Item 1:')
        ->toContain('      X: 1')
        ->toContain('      Y: 2')
        ->toContain('    Item 2:')
        ->toContain('  ▼ Settings')
        ->toContain('    Waves: 3')
        ->toContain('    Origin:')
        ->toContain('      X: 6')
        ->toContain('      Y: 7');
});

test('inspector panel commits slider edits using the default range step', function () {
    $workspace = createInspectorCompoundComponentWorkspace();
    $panel = new InspectorPanel(width: 64, height: 32, workingDirectory: $workspace);
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
                    'class' => 'Sendama\\Game\\Scripts\\SchemaProbe',
                    'data' => [
                        'speed' => 4,
                        'waypoints' => [],
                        'settings' => [
                            'waves' => 3,
                            'origin' => ['x' => 6, 'y' => 7],
                        ],
                    ],
                    '__editorFieldTypes' => [
                        'speed' => 'int',
                        'waypoints' => 'array',
                        'settings' => 'Sendama\\Game\\Scripts\\CompoundSettings',
                    ],
                ],
            ],
        ],
    ]);

    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'Speed');

    setInspectorInput("\n");
    $panel->update();

    setInspectorInput("\033[C");
    $panel->update();

    setInspectorInput("\n");
    $panel->update();

    $mutation = $panel->consumeHierarchyMutation();

    expect($mutation['value']['components'][0]['data']['speed'] ?? null)->toBe(5);
});

test('inspector panel does not adjust slider edits with up and down keys', function () {
    $workspace = createInspectorCompoundComponentWorkspace();
    $panel = new InspectorPanel(width: 64, height: 32, workingDirectory: $workspace);
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
                    'class' => 'Sendama\\Game\\Scripts\\SchemaProbe',
                    'data' => [
                        'speed' => 4,
                        'waypoints' => [],
                        'settings' => [
                            'waves' => 3,
                            'origin' => ['x' => 6, 'y' => 7],
                        ],
                    ],
                    '__editorFieldTypes' => [
                        'speed' => 'int',
                        'waypoints' => 'array',
                        'settings' => 'Sendama\\Game\\Scripts\\CompoundSettings',
                    ],
                ],
            ],
        ],
    ]);

    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'Speed');

    setInspectorInput("\n");
    $panel->update();

    setInspectorInput("\033[A");
    $panel->update();

    setInspectorInput("\n");
    $panel->update();

    $mutation = $panel->consumeHierarchyMutation();

    expect($mutation['value']['components'][0]['data']['speed'] ?? null)->toBe(4);
});

test('inspector panel separates prefab file renames from prefab metadata edits', function () {
    $panel = new InspectorPanel(width: 48, height: 24);
    $panel->inspectTarget([
        'context' => 'prefab',
        'path' => 'Prefabs/enemy.prefab.php',
        'name' => 'Enemy',
        'type' => 'GameObject',
        'asset' => [
            'name' => 'enemy.prefab.php',
            'path' => '/tmp/project/Assets/Prefabs/enemy.prefab.php',
            'relativePath' => 'Prefabs/enemy.prefab.php',
            'isDirectory' => false,
            'children' => [],
        ],
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Enemy',
            'tag' => 'Enemy',
            'position' => ['x' => 60, 'y' => 12],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'components' => [],
        ],
    ]);

    expect($panel->content)->toContain('File Name: enemy.prefab.php')
        ->toContain('Name: Enemy');

    $focusableControls = new ReflectionProperty(InspectorPanel::class, 'focusableControls');
    $focusableControls->setAccessible(true);
    $applyControlValueToInspectionTarget = new ReflectionMethod(InspectorPanel::class, 'applyControlValueToInspectionTarget');
    $applyControlValueToInspectionTarget->setAccessible(true);

    $fileNameControl = null;
    $nameControl = null;

    foreach ($focusableControls->getValue($panel) as $control) {
        if (!$control instanceof InputControl) {
            continue;
        }

        if ($control->getLabel() === 'File Name') {
            $fileNameControl = $control;
        }

        if ($control->getLabel() === 'Name') {
            $nameControl = $control;
        }
    }

    expect($fileNameControl)->toBeInstanceOf(InputControl::class)
        ->and($nameControl)->toBeInstanceOf(InputControl::class);

    $fileNameControl->setValue('boss.prefab.php');
    $applyControlValueToInspectionTarget->invoke($panel, $fileNameControl);

    expect($panel->consumeAssetMutation())->toBe([
        'path' => '/tmp/project/Assets/Prefabs/enemy.prefab.php',
        'relativePath' => 'Prefabs/enemy.prefab.php',
        'name' => 'boss.prefab.php',
        'activatePrefab' => true,
    ]);

    $nameControl->setValue('Boss');
    $applyControlValueToInspectionTarget->invoke($panel, $nameControl);

    $prefabMutation = $panel->consumePrefabMutation();

    expect($prefabMutation['path'] ?? null)->toBe('Prefabs/enemy.prefab.php')
        ->and($prefabMutation['prefabPath'] ?? null)->toBe('/tmp/project/Assets/Prefabs/enemy.prefab.php')
        ->and($prefabMutation['value']['name'] ?? null)->toBe('Boss');
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
            'environmentCollisionMapPath' => '',
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
            'environmentCollisionMapPath' => '',
        ],
    ]);
});

test('inspector panel preserves the selected hierarchy control across syncs', function () {
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

    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'Tag');

    $panel->syncHierarchyTarget('scene.0', [
        'type' => 'Sendama\\Engine\\Core\\GameObject',
        'name' => 'Player',
        'tag' => 'Hero',
        'position' => ['x' => 4, 'y' => 12],
        'rotation' => ['x' => 0, 'y' => 0],
        'scale' => ['x' => 1, 'y' => 1],
    ]);

    expect(selectedInspectorControlLabel($panel))->toBe('Tag');
});

test('inspector panel preserves the selected scene control across syncs', function () {
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
            'environmentCollisionMapPath' => '',
        ],
    ]);

    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'Height');

    $panel->syncSceneTarget([
        'name' => 'level01',
        'width' => 80,
        'height' => 30,
        'environmentTileMapPath' => 'Maps/level',
        'environmentCollisionMapPath' => '',
    ]);

    expect(selectedInspectorControlLabel($panel))->toBe('Height');
});

test('inspector panel preserves the selected asset control across syncs', function () {
    $panel = new InspectorPanel(width: 48, height: 24);

    $panel->inspectTarget([
        'context' => 'asset',
        'name' => 'level01.tmap',
        'type' => 'File',
        'value' => [
            'name' => 'level01.tmap',
            'path' => '/tmp/project/Assets/Maps/level01.tmap',
            'relativePath' => 'Maps/level01.tmap',
            'isDirectory' => false,
        ],
    ]);

    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'Name');

    $panel->syncAssetTarget([
        'name' => 'level02.tmap',
        'path' => '/tmp/project/Assets/Maps/level02.tmap',
        'relativePath' => 'Maps/level02.tmap',
        'isDirectory' => false,
    ]);

    expect(selectedInspectorControlLabel($panel))->toBe('Name');
});

test('inspector panel preserves the selected control when the same target is re-inspected', function () {
    $panel = new InspectorPanel(width: 48, height: 24);
    $target = [
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
            'sprite' => [
                'texture' => [
                    'path' => 'Textures/player',
                    'position' => ['x' => 0, 'y' => 0],
                    'size' => ['x' => 1, 'y' => 5],
                ],
            ],
        ],
    ];

    $panel->inspectTarget($target);
    focusInspectorPanel($panel);
    selectInspectorControlByLabel($panel, 'Offset');

    $target['value']['sprite']['texture']['position']['x'] = 1;
    $panel->inspectTarget($target);

    expect(selectedInspectorControlLabel($panel))->toBe('Offset');
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

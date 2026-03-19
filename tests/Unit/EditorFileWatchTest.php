<?php

use Assegai\Collections\ItemList;
use Sendama\Console\Editor\DTOs\SceneDTO;
use Sendama\Console\Editor\Editor;
use Sendama\Console\Editor\EditorSceneSettings;
use Sendama\Console\Editor\SceneLoader;
use Sendama\Console\Editor\SceneWriter;
use Sendama\Console\Editor\Widgets\AssetsPanel;
use Sendama\Console\Editor\Widgets\ConsolePanel;
use Sendama\Console\Editor\Widgets\HierarchyPanel;
use Sendama\Console\Editor\Widgets\InspectorPanel;
use Sendama\Console\Editor\Widgets\MainPanel;
use Sendama\Console\Editor\Widgets\PanelListModal;
use Sendama\Console\Editor\Widgets\Snackbar;
use Sendama\Console\Editor\Widgets\Widget;

test('editor refreshes inspected hierarchy component fields when watched scripts change', function () {
    $workspace = createEditorFileWatchWorkspace();
    [$editor, $reflection, $inspectorPanel, $loadedScene] = createEditorForFileWatch($workspace);
    $synchronizeWatchedAssetChanges = $reflection->getMethod('synchronizeWatchedAssetChanges');
    $synchronizeWatchedAssetChanges->setAccessible(true);

    $loadedScene->isDirty = true;

    expect(implode("\n", $inspectorPanel->content))
        ->toContain('Speed: 1')
        ->not->toContain('Lives: 3');

    $synchronizeWatchedAssetChanges->invoke($editor, true);

    file_put_contents(
        $workspace . '/Assets/Scripts/WatcherComponent.php',
        <<<'PHP'
<?php

namespace Sendama\Game\Scripts;

use Sendama\Engine\Core\Component;

class WatcherComponent extends Component
{
    public int $speed = 1;
    public int $lives = 3;
}
PHP
    );

    $synchronizeWatchedAssetChanges->invoke($editor, true);

    $inspectionTarget = $inspectorPanel->getInspectionTarget();
    $componentData = $loadedScene->hierarchy[0]['components'][0]['data'] ?? [];

    expect($inspectionTarget)->toMatchArray([
        'context' => 'hierarchy',
        'path' => 'scene.0',
    ]);
    expect(implode("\n", $inspectorPanel->content))
        ->toContain('Speed: 1')
        ->toContain('Lives: 3');
    expect($componentData)->toMatchArray([
        'speed' => 1,
        'lives' => 3,
    ]);
    expect($loadedScene->isDirty)->toBeTrue();
});

function createEditorFileWatchWorkspace(): string
{
    $workspace = sys_get_temp_dir() . '/sendama-editor-file-watch-' . uniqid();
    mkdir($workspace . '/Assets/Scenes', 0777, true);
    mkdir($workspace . '/Assets/Scripts', 0777, true);
    mkdir($workspace . '/vendor', 0777, true);

    file_put_contents(
        $workspace . '/vendor/autoload.php',
        <<<'PHP'
<?php

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Sendama\\Game\\Scripts\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $path = __DIR__ . '/../Assets/Scripts/' . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($path)) {
            require $path;
        }
    });
}

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
        public function __construct(private ?object $gameObject = null)
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
PHP
    );

    file_put_contents(
        $workspace . '/Assets/Scripts/WatcherComponent.php',
        <<<'PHP'
<?php

namespace Sendama\Game\Scripts;

use Sendama\Engine\Core\Component;

class WatcherComponent extends Component
{
    public int $speed = 1;
}
PHP
    );

    file_put_contents(
        $workspace . '/Assets/Scenes/level01.scene.php',
        <<<'PHP'
<?php

return [
    'width' => 120,
    'height' => 40,
    'hierarchy' => [
        [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Player',
            'tag' => 'Player',
            'position' => ['x' => 4, 'y' => 20],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'components' => [
                ['class' => 'Sendama\\Game\\Scripts\\WatcherComponent'],
            ],
        ],
    ],
];
PHP
    );

    return $workspace;
}

function createEditorForFileWatch(string $workspace): array
{
    $loadedScene = (new SceneLoader($workspace))->load(new EditorSceneSettings(active: 0, loaded: ['level01']));

    expect($loadedScene)->toBeInstanceOf(SceneDTO::class);

    $editorReflection = new ReflectionClass(Editor::class);
    $editor = $editorReflection->newInstanceWithoutConstructor();
    $hierarchyPanel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: $loadedScene->name,
        hierarchy: $loadedScene->hierarchy,
        sceneWidth: $loadedScene->width,
        sceneHeight: $loadedScene->height,
        environmentTileMapPath: $loadedScene->environmentTileMapPath,
        environmentCollisionMapPath: $loadedScene->environmentCollisionMapPath,
    );
    $assetsPanel = new AssetsPanel(
        width: 40,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
        workingDirectory: $workspace,
    );
    $mainPanel = new MainPanel(
        width: 60,
        height: 12,
        sceneObjects: $loadedScene->hierarchy,
        workingDirectory: $workspace,
        sceneWidth: $loadedScene->width,
        sceneHeight: $loadedScene->height,
        environmentTileMapPath: $loadedScene->environmentTileMapPath,
    );
    $consolePanel = new ConsolePanel(width: 60, height: 12);
    $inspectorPanel = new InspectorPanel(width: 40, height: 12, workingDirectory: $workspace);

    $editorReflection->getProperty('workingDirectory')->setValue($editor, $workspace);
    $editorReflection->getProperty('assetsDirectoryPath')->setValue($editor, $workspace . '/Assets');
    $editorReflection->getProperty('loadedScene')->setValue($editor, $loadedScene);
    $editorReflection->getProperty('sceneWriter')->setValue($editor, new SceneWriter());
    $editorReflection->getProperty('hierarchyPanel')->setValue($editor, $hierarchyPanel);
    $editorReflection->getProperty('assetsPanel')->setValue($editor, $assetsPanel);
    $editorReflection->getProperty('mainPanel')->setValue($editor, $mainPanel);
    $editorReflection->getProperty('consolePanel')->setValue($editor, $consolePanel);
    $editorReflection->getProperty('inspectorPanel')->setValue($editor, $inspectorPanel);
    $editorReflection->getProperty('panelListModal')->setValue($editor, new PanelListModal());
    $editorReflection->getProperty('snackbar')->setValue($editor, new Snackbar());
    $editorReflection->getProperty('panels')->setValue($editor, new ItemList(Widget::class, [
        $hierarchyPanel,
        $assetsPanel,
        $mainPanel,
        $consolePanel,
        $inspectorPanel,
    ]));

    $hierarchyPanel->selectPath('scene.0');
    $mainPanel->selectSceneObject('scene.0');
    $inspectorPanel->setSceneHierarchy($loadedScene->hierarchy);
    $inspectorPanel->inspectTarget([
        'context' => 'hierarchy',
        'name' => $loadedScene->hierarchy[0]['name'] ?? 'Player',
        'type' => 'GameObject',
        'path' => 'scene.0',
        'value' => $loadedScene->hierarchy[0],
    ]);

    return [$editor, $editorReflection, $inspectorPanel, $loadedScene];
}

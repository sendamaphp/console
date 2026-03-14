<?php

use Sendama\Console\Editor\Editor;
use Sendama\Console\Editor\PrefabWriter;
use Sendama\Console\Editor\Widgets\AssetsPanel;
use Sendama\Console\Editor\Widgets\ConsolePanel;
use Sendama\Console\Editor\Widgets\HierarchyPanel;
use Sendama\Console\Editor\Widgets\InspectorPanel;
use Sendama\Console\Editor\Widgets\MainPanel;
use Sendama\Console\Editor\Widgets\Snackbar;

test('editor keeps highlighted texture assets in the inspector until enter is pressed', function () {
    $workspace = createEditorAssetSelectionWorkspace();
    [$editor, $reflection, $assetsPanel, $mainPanel, $inspectorPanel] = createEditorForAssetSelection($workspace);

    $assetsPanel->expandSelection();
    $assetsPanel->moveSelection(1);

    $synchronizeInspectorPanel = $reflection->getMethod('synchronizeInspectorPanel');
    $synchronizeInspectorPanel->setAccessible(true);
    $synchronizeInspectorPanel->invoke($editor);

    $activeSpriteAsset = new ReflectionProperty(MainPanel::class, 'activeSpriteAsset');
    $inspectionTarget = new ReflectionProperty(InspectorPanel::class, 'inspectionTarget');
    $activeSpriteAsset->setAccessible(true);
    $inspectionTarget->setAccessible(true);

    expect($mainPanel->getActiveTab())->toBe('Scene')
        ->and($activeSpriteAsset->getValue($mainPanel))->toBeNull()
        ->and($inspectionTarget->getValue($inspectorPanel)['name'] ?? null)->toBe('player.texture');
});

test('editor opens the selected texture asset in the main panel only on enter activation', function () {
    $workspace = createEditorAssetSelectionWorkspace();
    [$editor, $reflection, $assetsPanel, $mainPanel] = createEditorForAssetSelection($workspace);

    $assetsPanel->expandSelection();
    $assetsPanel->moveSelection(1);
    $assetsPanel->activateSelection();

    $synchronizeInspectorPanel = $reflection->getMethod('synchronizeInspectorPanel');
    $synchronizeInspectorPanel->setAccessible(true);
    $synchronizeInspectorPanel->invoke($editor);

    $activeSpriteAsset = new ReflectionProperty(MainPanel::class, 'activeSpriteAsset');
    $focusedPanel = $reflection->getProperty('focusedPanel');
    $activeSpriteAsset->setAccessible(true);
    $focusedPanel->setAccessible(true);

    expect($mainPanel->getActiveTab())->toBe('Sprite')
        ->and($activeSpriteAsset->getValue($mainPanel)['name'] ?? null)->toBe('player.texture')
        ->and($focusedPanel->getValue($editor))->toBe($mainPanel);
});

test('editor loads the selected prefab asset into a hierarchy-style inspector on enter activation', function () {
    $workspace = createEditorPrefabSelectionWorkspace();
    [$editor, $reflection, $assetsPanel, $mainPanel, $inspectorPanel] = createEditorForAssetSelection($workspace);

    $assetsPanel->expandSelection();
    $assetsPanel->expandSelection();
    $assetsPanel->activateSelection();

    $synchronizeInspectorPanel = $reflection->getMethod('synchronizeInspectorPanel');
    $synchronizeInspectorPanel->setAccessible(true);
    $synchronizeInspectorPanel->invoke($editor);

    $inspectionTarget = new ReflectionProperty(InspectorPanel::class, 'inspectionTarget');
    $inspectionTarget->setAccessible(true);
    $contentText = implode("\n", $inspectorPanel->content);

    expect($inspectionTarget->getValue($inspectorPanel))->toMatchArray([
        'context' => 'prefab',
        'name' => 'Enemy',
        'type' => 'GameObject',
    ]);
    expect($contentText)->toContain('Type: GameObject')
        ->toContain('Name: Enemy')
        ->toContain('Tag: Enemy')
        ->toContain('▼ EnemyComponent')
        ->toContain('Move Speed: 1')
        ->and($mainPanel->getActiveTab())->toBe('Scene');
});

test('editor creates a prefab from the selected hierarchy object and focuses the inspector', function () {
    $workspace = createEditorPrefabExportWorkspace();
    [$editor, $reflection, $hierarchyPanel, $assetsPanel, $mainPanel, $inspectorPanel] = createEditorForPrefabExport($workspace);

    $hierarchyPanel->expandSelection();
    $hierarchyPanel->beginPrefabCreationWorkflow();

    $synchronizeHierarchyPrefabCreations = $reflection->getMethod('synchronizeHierarchyPrefabCreations');
    $synchronizeHierarchyPrefabCreations->setAccessible(true);
    $synchronizeHierarchyPrefabCreations->invoke($editor);

    $focusedPanel = $reflection->getProperty('focusedPanel');
    $inspectionTarget = new ReflectionProperty(InspectorPanel::class, 'inspectionTarget');
    $focusedPanel->setAccessible(true);
    $inspectionTarget->setAccessible(true);

    $createdPrefabPath = $workspace . '/Assets/Prefabs/enemy-ship.prefab.php';

    expect(is_file($createdPrefabPath))->toBeTrue()
        ->and($assetsPanel->content)->toContain('▼ Prefabs')
        ->and($assetsPanel->getSelectedAssetEntry()['name'] ?? null)->toBe('enemy-ship.prefab.php')
        ->and($inspectionTarget->getValue($inspectorPanel))->toMatchArray([
            'context' => 'prefab',
            'name' => 'Enemy Ship',
            'type' => 'GameObject',
        ])
        ->and($focusedPanel->getValue($editor))->toBe($inspectorPanel)
        ->and($mainPanel->getActiveTab())->toBe('Scene');
});

function createEditorAssetSelectionWorkspace(): string
{
    $workspace = sys_get_temp_dir() . '/sendama-editor-asset-selection-' . uniqid();
    mkdir($workspace . '/Assets/Textures', 0777, true);
    file_put_contents($workspace . '/Assets/Textures/player.texture', ">\n");

    return $workspace;
}

function createEditorPrefabSelectionWorkspace(): string
{
    $workspace = sys_get_temp_dir() . '/sendama-editor-prefab-selection-' . uniqid();
    mkdir($workspace . '/Assets/Prefabs', 0777, true);
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

namespace Sendama\Game\Scripts {
    use Sendama\Engine\Core\Behaviours\Attributes\SerializeField;
    use Sendama\Engine\Core\Component;

    class EnemyComponent extends Component
    {
        public int $moveSpeed = 1;

        #[SerializeField]
        protected bool $isTrigger = true;
    }
}
PHP
    );

    file_put_contents(
        $workspace . '/Assets/Prefabs/enemy.prefab.php',
        <<<'PHP'
<?php

use Sendama\Engine\Core\GameObject;
use Sendama\Game\Scripts\EnemyComponent;

return [
    'type' => GameObject::class,
    'name' => 'Enemy',
    'tag' => 'Enemy',
    'position' => ['x' => 60, 'y' => 12],
    'rotation' => ['x' => 0, 'y' => 0],
    'scale' => ['x' => 1, 'y' => 1],
    'components' => [
        ['class' => EnemyComponent::class],
    ],
];
PHP
    );

    return $workspace;
}

function createEditorPrefabExportWorkspace(): string
{
    $workspace = sys_get_temp_dir() . '/sendama-editor-prefab-export-' . uniqid();
    mkdir($workspace . '/Assets/Prefabs', 0777, true);

    return $workspace;
}

function createEditorForAssetSelection(string $workspace): array
{
    $editorReflection = new ReflectionClass(Editor::class);
    $editor = $editorReflection->newInstanceWithoutConstructor();
    $hierarchyPanel = new HierarchyPanel();
    $assetsPanel = new AssetsPanel(
        width: 40,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
        workingDirectory: $workspace,
    );
    $mainPanel = new MainPanel(width: 60, height: 12, workingDirectory: $workspace);
    $inspectorPanel = new InspectorPanel(width: 40, height: 12, workingDirectory: $workspace);

    $editorReflection->getProperty('hierarchyPanel')->setValue($editor, $hierarchyPanel);
    $editorReflection->getProperty('assetsPanel')->setValue($editor, $assetsPanel);
    $editorReflection->getProperty('mainPanel')->setValue($editor, $mainPanel);
    $editorReflection->getProperty('inspectorPanel')->setValue($editor, $inspectorPanel);
    $editorReflection->getProperty('focusedPanel')->setValue($editor, $assetsPanel);

    return [$editor, $editorReflection, $assetsPanel, $mainPanel, $inspectorPanel];
}

function createEditorForPrefabExport(string $workspace): array
{
    $editorReflection = new ReflectionClass(Editor::class);
    $editor = $editorReflection->newInstanceWithoutConstructor();
    $hierarchyPanel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Enemy Ship',
                'tag' => 'Enemy',
                'position' => ['x' => 60, 'y' => 12],
                'rotation' => ['x' => 0, 'y' => 0],
                'scale' => ['x' => 1, 'y' => 1],
                'components' => [],
            ],
        ],
    );
    $assetsPanel = new AssetsPanel(
        width: 40,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
        workingDirectory: $workspace,
    );
    $mainPanel = new MainPanel(width: 60, height: 12, workingDirectory: $workspace);
    $inspectorPanel = new InspectorPanel(width: 40, height: 12, workingDirectory: $workspace);
    $consolePanel = new ConsolePanel(width: 60, height: 12);

    $editorReflection->getProperty('workingDirectory')->setValue($editor, $workspace);
    $editorReflection->getProperty('assetsDirectoryPath')->setValue($editor, $workspace . '/Assets');
    $editorReflection->getProperty('hierarchyPanel')->setValue($editor, $hierarchyPanel);
    $editorReflection->getProperty('assetsPanel')->setValue($editor, $assetsPanel);
    $editorReflection->getProperty('mainPanel')->setValue($editor, $mainPanel);
    $editorReflection->getProperty('inspectorPanel')->setValue($editor, $inspectorPanel);
    $editorReflection->getProperty('consolePanel')->setValue($editor, $consolePanel);
    $editorReflection->getProperty('prefabWriter')->setValue($editor, new PrefabWriter());
    $editorReflection->getProperty('snackbar')->setValue($editor, new Snackbar());
    $editorReflection->getProperty('focusedPanel')->setValue($editor, $hierarchyPanel);

    return [$editor, $editorReflection, $hierarchyPanel, $assetsPanel, $mainPanel, $inspectorPanel];
}

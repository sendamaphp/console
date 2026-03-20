<?php

use Assegai\Collections\ItemList;
use Atatusoft\Termutil\Events\MouseEvent;
use Sendama\Console\Editor\Editor;
use Sendama\Console\Editor\DTOs\SceneDTO;
use Sendama\Console\Editor\PrefabWriter;
use Sendama\Console\Editor\IO\InputManager;
use Sendama\Console\Editor\Widgets\AssetsPanel;
use Sendama\Console\Editor\Widgets\CommandHelpModal;
use Sendama\Console\Editor\Widgets\CommandLineModal;
use Sendama\Console\Editor\Widgets\ConsolePanel;
use Sendama\Console\Editor\Widgets\HierarchyPanel;
use Sendama\Console\Editor\Widgets\InspectorPanel;
use Sendama\Console\Editor\Widgets\MainPanel;
use Sendama\Console\Editor\Widgets\PanelListModal;
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

test('editor duplicates the selected hierarchy object beside the original and selects it', function () {
    $workspace = createEditorPrefabExportWorkspace();
    [$editor, $reflection, $hierarchyPanel, $assetsPanel, $mainPanel, $inspectorPanel] = createEditorForPrefabExport($workspace);

    $loadedScene = new SceneDTO(
        name: 'level01',
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
        rawData: [
            'hierarchy' => [
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
        ],
    );
    $reflection->getProperty('loadedScene')->setValue($editor, $loadedScene);

    $hierarchyPanel->expandSelection();
    $hierarchyPanel->beginDuplicationWorkflow();

    $synchronizeHierarchyDuplications = $reflection->getMethod('synchronizeHierarchyDuplications');
    $synchronizeHierarchyDuplications->setAccessible(true);
    $synchronizeHierarchyDuplications->invoke($editor);

    $sceneObjects = new ReflectionProperty(MainPanel::class, 'sceneObjects');
    $sceneObjects->setAccessible(true);

    expect($loadedScene->hierarchy)->toHaveCount(2)
        ->and($loadedScene->hierarchy[0]['name'] ?? null)->toBe('Enemy Ship')
        ->and($loadedScene->hierarchy[1]['name'] ?? null)->toBe('Enemy Ship 1')
        ->and($hierarchyPanel->getSelectedHierarchyObject()['name'] ?? null)->toBe('Enemy Ship 1')
        ->and($sceneObjects->getValue($mainPanel)[1]['name'] ?? null)->toBe('Enemy Ship 1')
        ->and($assetsPanel)->toBeInstanceOf(AssetsPanel::class)
        ->and($inspectorPanel)->toBeInstanceOf(InspectorPanel::class);
});

test('editor duplicates all selected hierarchy objects when hierarchy multiselect is active', function () {
    $workspace = createEditorPrefabExportWorkspace();
    [$editor, $reflection, $hierarchyPanel, $assetsPanel, $mainPanel, $inspectorPanel] = createEditorForPrefabExport($workspace);

    $loadedScene = new SceneDTO(
        name: 'level01',
        hierarchy: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'components' => [],
            ],
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Enemy',
                'components' => [],
            ],
        ],
        rawData: [
            'hierarchy' => [
                ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Player', 'components' => []],
                ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Enemy', 'components' => []],
            ],
        ],
    );
    $reflection->getProperty('loadedScene')->setValue($editor, $loadedScene);
    $hierarchyPanel->syncHierarchy($loadedScene->hierarchy);
    $hierarchyPanel->selectPaths(['scene.0', 'scene.1'], 'scene.1');
    $hierarchyPanel->beginDuplicationWorkflow();

    $synchronizeHierarchyDuplications = $reflection->getMethod('synchronizeHierarchyDuplications');
    $synchronizeHierarchyDuplications->setAccessible(true);
    $synchronizeHierarchyDuplications->invoke($editor);

    $sceneObjects = new ReflectionProperty(MainPanel::class, 'sceneObjects');
    $sceneObjects->setAccessible(true);

    expect(array_column($loadedScene->hierarchy, 'name'))->toBe(['Player', 'Player 1', 'Enemy', 'Enemy 1'])
        ->and($hierarchyPanel->getSelectedHierarchyObject()['name'] ?? null)->toBe('Enemy 1')
        ->and(array_column($sceneObjects->getValue($mainPanel), 'name'))->toBe(['Player', 'Player 1', 'Enemy', 'Enemy 1'])
        ->and($assetsPanel)->toBeInstanceOf(AssetsPanel::class)
        ->and($inspectorPanel)->toBeInstanceOf(InspectorPanel::class);
});

test('editor adds a new hierarchy game object as a child of the selected parent', function () {
    $workspace = createEditorPrefabExportWorkspace();
    [$editor, $reflection, $hierarchyPanel, $assetsPanel, $mainPanel, $inspectorPanel] = createEditorForPrefabExport($workspace);

    $loadedScene = new SceneDTO(
        name: 'level01',
        hierarchy: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'children' => [],
                'components' => [],
            ],
        ],
        rawData: [
            'hierarchy' => [
                [
                    'type' => 'Sendama\\Engine\\Core\\GameObject',
                    'name' => 'Player',
                    'children' => [],
                    'components' => [],
                ],
            ],
        ],
    );
    $reflection->getProperty('loadedScene')->setValue($editor, $loadedScene);
    $hierarchyPanel->syncHierarchy($loadedScene->hierarchy);

    $pendingCreationItem = new ReflectionProperty(HierarchyPanel::class, 'pendingCreationItem');
    $pendingCreationItem->setAccessible(true);
    $pendingCreationItem->setValue($hierarchyPanel, [
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'GameObject #2',
            'tag' => 'None',
            'position' => ['x' => 0, 'y' => 0],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'components' => [],
        ],
        'parentPath' => 'scene.0',
    ]);

    $synchronizeHierarchyAdditions = $reflection->getMethod('synchronizeHierarchyAdditions');
    $synchronizeHierarchyAdditions->setAccessible(true);
    $synchronizeHierarchyAdditions->invoke($editor);

    $inspectionTarget = new ReflectionProperty(InspectorPanel::class, 'inspectionTarget');
    $inspectionTarget->setAccessible(true);

    expect($loadedScene->hierarchy[0]['children'][0]['name'] ?? null)->toBe('GameObject #2')
        ->and($hierarchyPanel->content)->toContain('  ▼ Player')
        ->and($hierarchyPanel->content)->toContain('    • GameObject #2')
        ->and($hierarchyPanel->getSelectedHierarchyObject()['name'] ?? null)->toBe('GameObject #2')
        ->and($mainPanel)->toBeInstanceOf(MainPanel::class)
        ->and($assetsPanel)->toBeInstanceOf(AssetsPanel::class)
        ->and($inspectorPanel)->toBeInstanceOf(InspectorPanel::class)
        ->and($inspectionTarget->getValue($inspectorPanel))->not->toBeNull();
});

test('editor moves a hierarchy object into another tree during hierarchy move mode', function () {
    $workspace = createEditorPrefabExportWorkspace();
    [$editor, $reflection, $hierarchyPanel, $assetsPanel, $mainPanel, $inspectorPanel] = createEditorForPrefabExport($workspace);

    $loadedScene = new SceneDTO(
        name: 'level01',
        hierarchy: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'children' => [
                    ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Gun', 'components' => []],
                ],
                'components' => [],
            ],
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Enemy',
                'components' => [],
            ],
        ],
        rawData: [
            'hierarchy' => [
                [
                    'type' => 'Sendama\\Engine\\Core\\GameObject',
                    'name' => 'Player',
                    'children' => [
                        ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Gun', 'components' => []],
                    ],
                    'components' => [],
                ],
                [
                    'type' => 'Sendama\\Engine\\Core\\GameObject',
                    'name' => 'Enemy',
                    'components' => [],
                ],
            ],
        ],
    );
    $reflection->getProperty('loadedScene')->setValue($editor, $loadedScene);
    $hierarchyPanel->syncHierarchy($loadedScene->hierarchy);

    $pendingMoveItem = new ReflectionProperty(HierarchyPanel::class, 'pendingMoveItem');
    $pendingMoveItem->setAccessible(true);
    $pendingMoveItem->setValue($hierarchyPanel, [
        'path' => 'scene.1',
        'targetPath' => 'scene.0.0',
        'position' => 'before',
    ]);

    $synchronizeHierarchyMoves = $reflection->getMethod('synchronizeHierarchyMoves');
    $synchronizeHierarchyMoves->setAccessible(true);
    $synchronizeHierarchyMoves->invoke($editor);

    $inspectionTarget = new ReflectionProperty(InspectorPanel::class, 'inspectionTarget');
    $inspectionTarget->setAccessible(true);
    $sceneObjects = new ReflectionProperty(MainPanel::class, 'sceneObjects');
    $sceneObjects->setAccessible(true);

    expect($loadedScene->hierarchy[0]['children'][0]['name'] ?? null)->toBe('Enemy')
        ->and($loadedScene->hierarchy[1] ?? null)->toBeNull()
        ->and($hierarchyPanel->getSelectedHierarchyObject()['name'] ?? null)->toBe('Enemy')
        ->and(($sceneObjects->getValue($mainPanel)[0]['children'][0]['name'] ?? null))->toBe('Enemy')
        ->and($inspectionTarget->getValue($inspectorPanel))->toMatchArray([
            'context' => 'hierarchy',
            'name' => 'Enemy',
            'path' => 'scene.0.0',
        ])
        ->and($assetsPanel)->toBeInstanceOf(AssetsPanel::class);
});

test('editor reparents a hierarchy object as a child when append child move requests are synchronized', function () {
    $workspace = createEditorPrefabExportWorkspace();
    [$editor, $reflection, $hierarchyPanel, $assetsPanel, $mainPanel, $inspectorPanel] = createEditorForPrefabExport($workspace);

    $loadedScene = new SceneDTO(
        name: 'level01',
        hierarchy: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'components' => [],
            ],
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Enemy',
                'children' => [],
                'components' => [],
            ],
        ],
        rawData: [
            'hierarchy' => [
                ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Player', 'components' => []],
                ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Enemy', 'children' => [], 'components' => []],
            ],
        ],
    );
    $reflection->getProperty('loadedScene')->setValue($editor, $loadedScene);
    $hierarchyPanel->syncHierarchy($loadedScene->hierarchy);

    $pendingMoveItem = new ReflectionProperty(HierarchyPanel::class, 'pendingMoveItem');
    $pendingMoveItem->setAccessible(true);
    $pendingMoveItem->setValue($hierarchyPanel, [
        'path' => 'scene.0',
        'targetPath' => 'scene.1',
        'position' => 'append_child',
    ]);

    $synchronizeHierarchyMoves = $reflection->getMethod('synchronizeHierarchyMoves');
    $synchronizeHierarchyMoves->setAccessible(true);
    $synchronizeHierarchyMoves->invoke($editor);

    $inspectionTarget = new ReflectionProperty(InspectorPanel::class, 'inspectionTarget');
    $inspectionTarget->setAccessible(true);
    $sceneObjects = new ReflectionProperty(MainPanel::class, 'sceneObjects');
    $sceneObjects->setAccessible(true);

    expect($loadedScene->hierarchy)->toHaveCount(1)
        ->and($loadedScene->hierarchy[0]['name'] ?? null)->toBe('Enemy')
        ->and($loadedScene->hierarchy[0]['children'][0]['name'] ?? null)->toBe('Player')
        ->and($hierarchyPanel->getSelectedHierarchyObject()['name'] ?? null)->toBe('Player')
        ->and(($sceneObjects->getValue($mainPanel)[0]['children'][0]['name'] ?? null))->toBe('Player')
        ->and($inspectionTarget->getValue($inspectorPanel))->toMatchArray([
            'context' => 'hierarchy',
            'name' => 'Player',
            'path' => 'scene.0.0',
        ])
        ->and($assetsPanel)->toBeInstanceOf(AssetsPanel::class);
});

test('editor moves a hierarchy child back to the scene root when a root append move request is synchronized', function () {
    $workspace = createEditorPrefabExportWorkspace();
    [$editor, $reflection, $hierarchyPanel, $assetsPanel, $mainPanel, $inspectorPanel] = createEditorForPrefabExport($workspace);

    $loadedScene = new SceneDTO(
        name: 'level01',
        hierarchy: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'children' => [
                    ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Gun', 'components' => []],
                ],
                'components' => [],
            ],
        ],
        rawData: [
            'hierarchy' => [
                [
                    'type' => 'Sendama\\Engine\\Core\\GameObject',
                    'name' => 'Player',
                    'children' => [
                        ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Gun', 'components' => []],
                    ],
                    'components' => [],
                ],
            ],
        ],
    );
    $reflection->getProperty('loadedScene')->setValue($editor, $loadedScene);
    $hierarchyPanel->syncHierarchy($loadedScene->hierarchy);

    $pendingMoveItem = new ReflectionProperty(HierarchyPanel::class, 'pendingMoveItem');
    $pendingMoveItem->setAccessible(true);
    $pendingMoveItem->setValue($hierarchyPanel, [
        'path' => 'scene.0.0',
        'targetPath' => 'scene',
        'position' => 'append_child',
    ]);

    $synchronizeHierarchyMoves = $reflection->getMethod('synchronizeHierarchyMoves');
    $synchronizeHierarchyMoves->setAccessible(true);
    $synchronizeHierarchyMoves->invoke($editor);

    $inspectionTarget = new ReflectionProperty(InspectorPanel::class, 'inspectionTarget');
    $inspectionTarget->setAccessible(true);
    $sceneObjects = new ReflectionProperty(MainPanel::class, 'sceneObjects');
    $sceneObjects->setAccessible(true);

    expect($loadedScene->hierarchy)->toHaveCount(2)
        ->and($loadedScene->hierarchy[0]['name'] ?? null)->toBe('Player')
        ->and($loadedScene->hierarchy[0]['children'] ?? [])->toBe([])
        ->and($loadedScene->hierarchy[1]['name'] ?? null)->toBe('Gun')
        ->and($hierarchyPanel->getSelectedHierarchyObject()['name'] ?? null)->toBe('Gun')
        ->and(($sceneObjects->getValue($mainPanel)[1]['name'] ?? null))->toBe('Gun')
        ->and($inspectionTarget->getValue($inspectorPanel))->toMatchArray([
            'context' => 'hierarchy',
            'name' => 'Gun',
            'path' => 'scene.1',
        ])
        ->and($assetsPanel)->toBeInstanceOf(AssetsPanel::class);
});

test('editor duplicates all selected scene objects when scene multiselect is active', function () {
    $workspace = createEditorPrefabExportWorkspace();
    [$editor, $reflection, $hierarchyPanel, $assetsPanel, $mainPanel, $inspectorPanel] = createEditorForPrefabExport($workspace);

    $loadedScene = new SceneDTO(
        name: 'level01',
        hierarchy: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'position' => ['x' => 2, 'y' => 1],
                'components' => [],
            ],
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Enemy',
                'position' => ['x' => 8, 'y' => 1],
                'components' => [],
            ],
        ],
        rawData: [
            'hierarchy' => [
                ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Player', 'position' => ['x' => 2, 'y' => 1], 'components' => []],
                ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Enemy', 'position' => ['x' => 8, 'y' => 1], 'components' => []],
            ],
        ],
    );
    $reflection->getProperty('loadedScene')->setValue($editor, $loadedScene);
    $hierarchyPanel->syncHierarchy($loadedScene->hierarchy);
    $mainPanel->setSceneObjects($loadedScene->hierarchy);
    $mainPanel->selectSceneObjects(['scene.0', 'scene.1'], 'scene.1');

    $beginSceneDuplicationWorkflow = new ReflectionMethod(MainPanel::class, 'beginSceneDuplicationWorkflow');
    $beginSceneDuplicationWorkflow->setAccessible(true);
    $beginSceneDuplicationWorkflow->invoke($mainPanel);

    $synchronizeHierarchyDuplications = $reflection->getMethod('synchronizeHierarchyDuplications');
    $synchronizeHierarchyDuplications->setAccessible(true);
    $synchronizeHierarchyDuplications->invoke($editor);

    $sceneObjects = new ReflectionProperty(MainPanel::class, 'sceneObjects');
    $sceneObjects->setAccessible(true);

    expect(array_column($loadedScene->hierarchy, 'name'))->toBe(['Player', 'Player 1', 'Enemy', 'Enemy 1'])
        ->and($hierarchyPanel->getSelectedHierarchyObject()['name'] ?? null)->toBe('Enemy 1')
        ->and(array_column($sceneObjects->getValue($mainPanel), 'name'))->toBe(['Player', 'Player 1', 'Enemy', 'Enemy 1'])
        ->and($assetsPanel)->toBeInstanceOf(AssetsPanel::class)
        ->and($inspectorPanel)->toBeInstanceOf(InspectorPanel::class);
});

test('editor selects panels when they are left-clicked', function () {
    $workspace = createEditorAssetSelectionWorkspace();
    [$editor, $reflection, $assetsPanel, $mainPanel, $inspectorPanel] = createEditorForAssetSelection($workspace);

    $mouseEvent = new ReflectionProperty(InputManager::class, 'mouseEvent');
    $mouseEvent->setAccessible(true);
    $mouseEvent->setValue(new MouseEvent("\033[<0;45;4M"));

    $handlePanelFocus = $reflection->getMethod('handlePanelFocus');
    $handlePanelFocus->setAccessible(true);
    $handlePanelFocus->invoke($editor);

    $focusedPanel = $reflection->getProperty('focusedPanel');
    $focusedPanel->setAccessible(true);

    expect($focusedPanel->getValue($editor))->toBe($mainPanel)
        ->and($mainPanel->hasFocus())->toBeTrue()
        ->and($assetsPanel->hasFocus())->toBeFalse()
        ->and($inspectorPanel->hasFocus())->toBeFalse();
});

test('editor focuses the inspector after creating a script asset from the assets panel', function () {
    $workspace = createEditorAssetCreationWorkspace();
    [$editor, $reflection, $assetsPanel, $mainPanel, $inspectorPanel] = createEditorForAssetSelection($workspace);

    $pendingCreationRequest = new ReflectionProperty(AssetsPanel::class, 'pendingCreationRequest');
    $pendingCreationRequest->setAccessible(true);
    $pendingCreationRequest->setValue($assetsPanel, [
        'kind' => 'script',
        'workingDirectory' => $workspace,
    ]);

    $synchronizeAssetCreations = $reflection->getMethod('synchronizeAssetCreations');
    $synchronizeAssetCreations->setAccessible(true);
    $synchronizeAssetCreations->invoke($editor);

    $focusedPanel = $reflection->getProperty('focusedPanel');
    $focusedPanel->setAccessible(true);
    $inspectionTarget = new ReflectionProperty(InspectorPanel::class, 'inspectionTarget');
    $inspectionTarget->setAccessible(true);

    expect(is_file($workspace . '/Assets/Scripts/NewScript1.php'))->toBeTrue()
        ->and($assetsPanel->content)->toContain('▼ Scripts')
        ->and($assetsPanel->content)->toContain('  • NewScript1.php')
        ->and($inspectionTarget->getValue($inspectorPanel)['name'] ?? null)->toBe('NewScript1.php')
        ->and($focusedPanel->getValue($editor))->toBe($inspectorPanel)
        ->and($mainPanel->getActiveTab())->toBe('Scene');
});

test('editor resolves generated script assets when project paths are relative to the launch directory', function () {
    $parentDirectory = sys_get_temp_dir() . '/sendama-editor-relative-asset-creation-' . uniqid();
    $workspace = $parentDirectory . '/repro-game';
    mkdir($workspace . '/Assets/Scripts', 0777, true);
    mkdir($workspace . '/Assets/Textures', 0777, true);
    mkdir($workspace . '/logs', 0777, true);
    file_put_contents($workspace . '/Assets/Textures/player.texture', ">\n");
    file_put_contents($workspace . '/logs/debug.log', '');
    file_put_contents($workspace . '/logs/error.log', '');
    file_put_contents($workspace . '/sendama.json', json_encode([
        'name' => 'Relative Asset Creation Test',
        'editor' => [
            'scenes' => [
                'active' => 0,
                'loaded' => [],
            ],
            'console' => [
                'refreshInterval' => 5,
            ],
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($workspace . '/composer.json', json_encode([
        'name' => 'tmp/relative-asset-creation-test',
        'require' => [
            'sendamaphp/engine' => '*',
        ],
        'autoload' => [
            'psr-4' => [
                'Tmp\\RelativeAssetCreation\\' => 'Assets/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $editorReflection = new ReflectionClass(Editor::class);
    $editor = $editorReflection->newInstanceWithoutConstructor();
    $editorReflection->getProperty('workingDirectory')->setValue($editor, 'repro-game');
    $editorReflection->getProperty('assetsDirectoryPath')->setValue($editor, 'repro-game/Assets');
    $editorReflection->getProperty('consolePanel')->setValue(
        $editor,
        new ConsolePanel(
            logFilePath: $workspace . '/logs/debug.log',
            errorLogFilePath: $workspace . '/logs/error.log',
        )
    );
    $editorReflection->getProperty('snackbar')->setValue($editor, new Snackbar());

    $createAssetUsingCliCommand = $editorReflection->getMethod('createAssetUsingCliCommand');
    $createAssetUsingCliCommand->setAccessible(true);

    $originalWorkingDirectory = getcwd();

    try {
        chdir($parentDirectory);
        $createdAsset = $createAssetUsingCliCommand->invoke($editor, 'script');
    } finally {
        if ($originalWorkingDirectory !== false) {
            chdir($originalWorkingDirectory);
        }
    }

    expect($createdAsset)->toBeArray()
        ->and($createdAsset['path'] ?? null)->toBe($workspace . '/Assets/Scripts/NewScript1.php')
        ->and($createdAsset['relativePath'] ?? null)->toBe('Scripts/NewScript1.php')
        ->and(is_file($workspace . '/Assets/Scripts/NewScript1.php'))->toBeTrue();
});

function createEditorAssetSelectionWorkspace(): string
{
    $workspace = sys_get_temp_dir() . '/sendama-editor-asset-selection-' . uniqid();
    mkdir($workspace . '/Assets/Textures', 0777, true);
    file_put_contents($workspace . '/Assets/Textures/player.texture', ">\n");

    return $workspace;
}

function createEditorAssetCreationWorkspace(): string
{
    $workspace = sys_get_temp_dir() . '/sendama-editor-asset-creation-' . uniqid();
    mkdir($workspace . '/Assets/Scripts', 0777, true);
    mkdir($workspace . '/Assets/Textures', 0777, true);
    file_put_contents($workspace . '/Assets/Textures/player.texture', ">\n");
    file_put_contents($workspace . '/sendama.json', json_encode([
        'name' => 'Asset Creation Test',
        'editor' => [
            'scenes' => [
                'active' => 0,
                'loaded' => [],
            ],
            'console' => [
                'refreshInterval' => 5,
            ],
        ],
    ], JSON_PRETTY_PRINT));
    file_put_contents($workspace . '/composer.json', json_encode([
        'name' => 'tmp/asset-creation-test',
        'require' => [
            'sendamaphp/engine' => '*',
        ],
        'autoload' => [
            'psr-4' => [
                'Tmp\\AssetCreation\\' => 'Assets/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
    $hierarchyPanel = new HierarchyPanel(width: 20, height: 12);
    $hierarchyPanel->setPosition(1, 1);
    $assetsPanel = new AssetsPanel(
        width: 20,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
        workingDirectory: $workspace,
    );
    $assetsPanel->setPosition(1, 13);
    $mainPanel = new MainPanel(width: 60, height: 12, workingDirectory: $workspace);
    $mainPanel->setPosition(21, 1);
    $consolePanel = new ConsolePanel(width: 60, height: 12);
    $consolePanel->setPosition(21, 13);
    $inspectorPanel = new InspectorPanel(width: 20, height: 12, workingDirectory: $workspace);
    $inspectorPanel->setPosition(81, 1);

    $editorReflection->getProperty('workingDirectory')->setValue($editor, $workspace);
    $editorReflection->getProperty('assetsDirectoryPath')->setValue($editor, $workspace . '/Assets');
    $editorReflection->getProperty('hierarchyPanel')->setValue($editor, $hierarchyPanel);
    $editorReflection->getProperty('assetsPanel')->setValue($editor, $assetsPanel);
    $editorReflection->getProperty('mainPanel')->setValue($editor, $mainPanel);
    $editorReflection->getProperty('consolePanel')->setValue($editor, $consolePanel);
    $editorReflection->getProperty('inspectorPanel')->setValue($editor, $inspectorPanel);
    $editorReflection->getProperty('panelListModal')->setValue($editor, new PanelListModal());
    $editorReflection->getProperty('commandLineModal')->setValue($editor, new CommandLineModal());
    $editorReflection->getProperty('commandHelpModal')->setValue($editor, new CommandHelpModal());
    $editorReflection->getProperty('snackbar')->setValue($editor, new Snackbar());
    $editorReflection->getProperty('panels')->setValue($editor, new ItemList(\Sendama\Console\Editor\Widgets\Widget::class, [
        $hierarchyPanel,
        $assetsPanel,
        $mainPanel,
        $consolePanel,
        $inspectorPanel,
    ]));
    $editorReflection->getProperty('focusedPanel')->setValue($editor, $assetsPanel);
    $assetsPanel->focus(new \Sendama\Console\Editor\FocusTargetContext($editor, new \Sendama\Console\Editor\GameSettings(name: 'Test Game')));

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
    $editorReflection->getProperty('commandLineModal')->setValue($editor, new CommandLineModal());
    $editorReflection->getProperty('commandHelpModal')->setValue($editor, new CommandHelpModal());
    $editorReflection->getProperty('snackbar')->setValue($editor, new Snackbar());
    $editorReflection->getProperty('focusedPanel')->setValue($editor, $hierarchyPanel);

    return [$editor, $editorReflection, $hierarchyPanel, $assetsPanel, $mainPanel, $inspectorPanel];
}

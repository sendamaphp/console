<?php

use Sendama\Console\Editor\Editor;
use Sendama\Console\Editor\Widgets\AssetsPanel;
use Sendama\Console\Editor\Widgets\HierarchyPanel;
use Sendama\Console\Editor\Widgets\InspectorPanel;
use Sendama\Console\Editor\Widgets\MainPanel;

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

function createEditorAssetSelectionWorkspace(): string
{
    $workspace = sys_get_temp_dir() . '/sendama-editor-asset-selection-' . uniqid();
    mkdir($workspace . '/Assets/Textures', 0777, true);
    file_put_contents($workspace . '/Assets/Textures/player.texture', ">\n");

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

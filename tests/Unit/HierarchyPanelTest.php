<?php

use Sendama\Console\Editor\Widgets\HierarchyPanel;

function getHierarchyContentAreaPosition(HierarchyPanel $panel): array
{
    $getContentAreaLeft = new ReflectionMethod($panel, 'getContentAreaLeft');
    $getContentAreaTop = new ReflectionMethod($panel, 'getContentAreaTop');

    return [
        'x' => $getContentAreaLeft->invoke($panel),
        'y' => $getContentAreaTop->invoke($panel),
    ];
}

test('hierarchy panel expands nested objects and traverses visible children', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'children' => [
                    ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Gun'],
                    ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Shield'],
                ],
            ],
            ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Enemy'],
        ],
    );

    expect($panel->getSelectedHierarchyObject())->toBeNull();
    expect($panel->content[0])->toBe('▼ level01');
    expect($panel->content[1])->toBe('  ► Player');

    $panel->expandSelection();

    expect($panel->getSelectedHierarchyObject()['name'])->toBe('Player');

    $panel->expandSelection();

    expect($panel->content[1])->toBe('  ▼ Player');
    expect($panel->content[2])->toBe('    • Gun');
    expect($panel->content[3])->toBe('    • Shield');

    $panel->moveSelection(1);

    expect($panel->getSelectedHierarchyObject()['name'])->toBe('Gun');

    $panel->moveSelection(1);

    expect($panel->getSelectedHierarchyObject()['name'])->toBe('Shield');
});

test('hierarchy panel collapses back to the parent node', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'children' => [
                    ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Gun'],
                ],
            ],
        ],
    );

    $panel->expandSelection();
    $panel->expandSelection();
    $panel->moveSelection(1);

    expect($panel->getSelectedHierarchyObject()['name'])->toBe('Gun');

    $panel->collapseSelection();

    expect($panel->getSelectedHierarchyObject()['name'])->toBe('Player');
    expect($panel->content[1])->toBe('  ▼ Player');
});

test('hierarchy panel queues the selected object for inspection', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Player'],
        ],
    );

    $panel->expandSelection();
    $panel->activateSelection();

    expect($panel->consumeInspectionRequest())->toBe([
        'context' => 'hierarchy',
        'name' => 'Player',
        'type' => 'GameObject',
        'path' => 'scene.0',
        'value' => ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Player'],
    ]);
    expect($panel->consumeInspectionRequest())->toBeNull();
});

test('hierarchy panel infers inspector type from class metadata', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            ['type' => '\\Sendama\\Engine\\UI\\Label\\Label', 'name' => 'Score'],
        ],
    );

    $panel->expandSelection();
    $panel->activateSelection();

    expect($panel->consumeInspectionRequest()['type'])->toBe('Label');
});

test('hierarchy panel marks dirty scenes in the root label', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        isSceneDirty: true,
        hierarchy: [],
    );

    expect($panel->content[0])->toBe('• level01*');
});

test('hierarchy panel queues the scene root for inspection', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        sceneWidth: 80,
        sceneHeight: 25,
        environmentTileMapPath: 'Maps/level',
        environmentCollisionMapPath: 'Maps/level.collider',
        hierarchy: [
            ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Player'],
        ],
    );

    $panel->activateSelection();

    expect($panel->consumeInspectionRequest())->toBe([
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
});

test('hierarchy panel queues default game objects from the add workflow', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'GameObject #1'],
        ],
    );

    $showAddObjectModal = new ReflectionMethod(HierarchyPanel::class, 'showAddObjectModal');
    $showAddObjectModal->setAccessible(true);
    $showAddObjectModal->invoke($panel);

    $handleAddObjectTypeSelection = new ReflectionMethod(HierarchyPanel::class, 'handleAddObjectTypeSelection');
    $handleAddObjectTypeSelection->setAccessible(true);
    $handleAddObjectTypeSelection->invoke($panel, 'GameObject');

    expect($panel->consumeCreationRequest())->toBe([
        'type' => 'Sendama\\Engine\\Core\\GameObject',
        'name' => 'GameObject #2',
        'tag' => 'None',
        'position' => ['x' => 0, 'y' => 0],
        'rotation' => ['x' => 0, 'y' => 0],
        'scale' => ['x' => 1, 'y' => 1],
        'components' => [],
    ]);
});

test('hierarchy panel queues default ui elements from the add workflow', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            ['type' => 'Sendama\\Engine\\UI\\Label\\Label', 'name' => 'Label #1'],
        ],
    );

    $handleAddUiElementSelection = new ReflectionMethod(HierarchyPanel::class, 'handleAddUiElementSelection');
    $handleAddUiElementSelection->setAccessible(true);
    $handleAddUiElementSelection->invoke($panel, 'Label');

    expect($panel->consumeCreationRequest())->toBe([
        'type' => 'Sendama\\Engine\\UI\\Label\\Label',
        'name' => 'Label #2',
        'tag' => 'UI',
        'position' => ['x' => 0, 'y' => 0],
        'size' => ['x' => 1, 'y' => 1],
        'text' => 'Label #2',
    ]);
});

test('hierarchy panel queues the selected object for deletion when confirmed', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Player'],
        ],
    );

    $panel->expandSelection();

    $showDeleteConfirmModal = new ReflectionMethod(HierarchyPanel::class, 'showDeleteConfirmModal');
    $showDeleteConfirmModal->setAccessible(true);
    $showDeleteConfirmModal->invoke($panel);

    $handleDeleteConfirmationSelection = new ReflectionMethod(HierarchyPanel::class, 'handleDeleteConfirmationSelection');
    $handleDeleteConfirmationSelection->setAccessible(true);
    $handleDeleteConfirmationSelection->invoke($panel, 'Delete');

    expect($panel->consumeDeletionRequest())->toBe([
        'path' => 'scene.0',
        'name' => 'Player',
    ]);
});

test('hierarchy panel cancels delete confirmation without queuing a deletion', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Player'],
        ],
    );

    $panel->expandSelection();

    $showDeleteConfirmModal = new ReflectionMethod(HierarchyPanel::class, 'showDeleteConfirmModal');
    $showDeleteConfirmModal->setAccessible(true);
    $showDeleteConfirmModal->invoke($panel);

    $handleDeleteConfirmationSelection = new ReflectionMethod(HierarchyPanel::class, 'handleDeleteConfirmationSelection');
    $handleDeleteConfirmationSelection->setAccessible(true);
    $handleDeleteConfirmationSelection->invoke($panel, 'Cancel');

    expect($panel->consumeDeletionRequest())->toBeNull();
});

test('hierarchy panel can queue prefab creation for the selected object', function () {
    $panel = new HierarchyPanel(
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

    $panel->expandSelection();
    $panel->beginPrefabCreationWorkflow();

    expect($panel->consumePrefabCreationRequest())->toBe([
        'path' => 'scene.0',
        'name' => 'Enemy Ship',
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'Enemy Ship',
            'tag' => 'Enemy',
            'position' => ['x' => 60, 'y' => 12],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'components' => [],
        ],
    ]);
    expect($panel->consumePrefabCreationRequest())->toBeNull();
});

test('hierarchy panel toggles expand and collapse when the icon is clicked', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'children' => [
                    ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Gun'],
                ],
            ],
        ],
    );

    $contentArea = getHierarchyContentAreaPosition($panel);
    $panel->handleMouseClick($contentArea['x'] + 2, $contentArea['y'] + 1);

    expect($panel->content[1])->toBe('  ▼ Player')
        ->and($panel->content[2])->toBe('    • Gun');

    $panel->handleMouseClick($contentArea['x'] + 2, $contentArea['y'] + 1);

    expect($panel->content[1])->toBe('  ► Player');
});

test('hierarchy panel activates the selected row on double click', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Player'],
        ],
    );

    $contentArea = getHierarchyContentAreaPosition($panel);
    $clickX = $contentArea['x'] + 4;
    $clickY = $contentArea['y'] + 1;

    $panel->handleMouseClick($clickX, $clickY);
    $panel->handleMouseClick($clickX, $clickY);

    expect($panel->consumeInspectionRequest())->toBe([
        'context' => 'hierarchy',
        'name' => 'Player',
        'type' => 'GameObject',
        'path' => 'scene.0',
        'value' => ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Player'],
    ]);
});

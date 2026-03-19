<?php

use Atatusoft\Termutil\Events\MouseEvent;
use Sendama\Console\Editor\IO\InputManager;
use Sendama\Console\Editor\Widgets\HierarchyPanel;

function pressHierarchyPanelKey(string $keyPress): void
{
    $currentKeyPress = new ReflectionProperty(InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(InputManager::class, 'previousKeyPress');
    $currentKeyPress->setAccessible(true);
    $previousKeyPress->setAccessible(true);
    $previousKeyPress->setValue('');
    $currentKeyPress->setValue($keyPress);
}

function getHierarchyContentAreaPosition(HierarchyPanel $panel): array
{
    $getContentAreaLeft = new ReflectionMethod($panel, 'getContentAreaLeft');
    $getContentAreaTop = new ReflectionMethod($panel, 'getContentAreaTop');

    return [
        'x' => $getContentAreaLeft->invoke($panel),
        'y' => $getContentAreaTop->invoke($panel),
    ];
}

function setHierarchyPanelMouseEvent(?MouseEvent $event): void
{
    $mouseEvent = new ReflectionProperty(InputManager::class, 'mouseEvent');
    $mouseEvent->setAccessible(true);
    $mouseEvent->setValue($event);
}

function focusHierarchyPanel(HierarchyPanel $panel): void
{
    $hasFocus = new ReflectionProperty(\Sendama\Console\Editor\Widgets\Widget::class, 'hasFocus');
    $hasFocus->setAccessible(true);
    $hasFocus->setValue($panel, true);
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

test('hierarchy panel offers gui textures in the ui element creation workflow', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [],
    );

    $showAddUiElementModal = new ReflectionMethod(HierarchyPanel::class, 'showAddUiElementModal');
    $showAddUiElementModal->setAccessible(true);
    $showAddUiElementModal->invoke($panel);

    $addUiElementModal = new ReflectionProperty(HierarchyPanel::class, 'addUiElementModal');
    $modalOptions = new ReflectionProperty(\Sendama\Console\Editor\Widgets\OptionListModal::class, 'options');
    $handleAddUiElementSelection = new ReflectionMethod(HierarchyPanel::class, 'handleAddUiElementSelection');
    $addUiElementModal->setAccessible(true);
    $modalOptions->setAccessible(true);
    $handleAddUiElementSelection->setAccessible(true);

    expect($modalOptions->getValue($addUiElementModal->getValue($panel)))->toContain('GUITexture');

    $handleAddUiElementSelection->invoke($panel, 'GUITexture');

    expect($panel->consumeCreationRequest())->toBe([
        'value' => [
            'type' => 'Sendama\\Engine\\UI\\GUITexture\\GUITexture',
            'name' => 'GUITexture #1',
            'tag' => 'UI',
            'position' => ['x' => 0, 'y' => 0],
            'size' => ['x' => 1, 'y' => 1],
            'texture' => 'None',
            'color' => 'White',
        ],
        'parentPath' => null,
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
        'value' => [
            'type' => 'Sendama\\Engine\\Core\\GameObject',
            'name' => 'GameObject #2',
            'tag' => 'None',
            'position' => ['x' => 0, 'y' => 0],
            'rotation' => ['x' => 0, 'y' => 0],
            'scale' => ['x' => 1, 'y' => 1],
            'components' => [],
        ],
        'parentPath' => null,
    ]);
});

test('hierarchy panel can queue a new game object as a child of the selected game object', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Player',
                'children' => [],
            ],
        ],
    );

    $panel->expandSelection();

    $handleAddObjectTypeSelection = new ReflectionMethod(HierarchyPanel::class, 'handleAddObjectTypeSelection');
    $handleAddObjectTypeSelection->setAccessible(true);
    $handleAddObjectTypeSelection->invoke($panel, 'GameObject');

    $handleAddObjectPlacementSelection = new ReflectionMethod(HierarchyPanel::class, 'handleAddObjectPlacementSelection');
    $handleAddObjectPlacementSelection->setAccessible(true);
    $handleAddObjectPlacementSelection->invoke($panel, 'Child of Player');

    expect($panel->consumeCreationRequest())->toBe([
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
        'value' => [
            'type' => 'Sendama\\Engine\\UI\\Label\\Label',
            'name' => 'Label #2',
            'tag' => 'UI',
            'position' => ['x' => 0, 'y' => 0],
            'size' => ['x' => 1, 'y' => 1],
            'text' => 'Label #2',
        ],
        'parentPath' => null,
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

test('hierarchy panel scrolls to keep the selected row visible when content overflows', function () {
    $panel = new HierarchyPanel(
        width: 32,
        height: 6,
        sceneName: 'level01',
        hierarchy: array_map(
            static fn (int $index): array => [
                'type' => 'Sendama\\Engine\\Core\\GameObject',
                'name' => 'Object ' . $index,
            ],
            range(1, 8),
        ),
    );

    $panel->moveSelection(8);

    $buildRenderedContentLines = new ReflectionMethod($panel, 'buildRenderedContentLines');
    $buildRenderedContentLines->setAccessible(true);
    $lines = $buildRenderedContentLines->invoke($panel);

    expect(array_any($lines, static fn (string $line): bool => str_contains($line, 'Object 8')))->toBeTrue()
        ->and(array_any($lines, static fn (string $line): bool => str_contains($line, '█') || str_contains($line, '░')))->toBeTrue();
});

test('hierarchy panel can queue duplication for the selected object and append a differentiating number', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Enemy'],
        ],
    );

    $panel->expandSelection();
    $panel->beginDuplicationWorkflow();

    expect($panel->consumeDuplicationRequest())->toBe([
        'items' => [
            [
                'path' => 'scene.0',
                'value' => [
                    'type' => 'Sendama\\Engine\\Core\\GameObject',
                    'name' => 'Enemy',
                ],
            ],
        ],
        'primaryPath' => 'scene.0',
    ]);
});

test('hierarchy panel adds ctrl-clicked rows to the duplication selection set', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Enemy 01'],
            ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Enemy 02'],
        ],
    );

    $panel->expandSelection();
    $panel->expandSelection();
    $panel->expandSelection();
    $contentArea = getHierarchyContentAreaPosition($panel);
    setHierarchyPanelMouseEvent(new MouseEvent("\033[<16;" . ($contentArea['x'] + 4) . ';' . ($contentArea['y'] + 2) . 'M'));
    $panel->handleMouseClick($contentArea['x'] + 4, $contentArea['y'] + 2);
    setHierarchyPanelMouseEvent(null);
    $panel->beginDuplicationWorkflow();

    expect($panel->consumeDuplicationRequest())->toBe([
        'items' => [
            [
                'path' => 'scene.0',
                'value' => ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Enemy 01'],
            ],
            [
                'path' => 'scene.1',
                'value' => ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Enemy 02'],
            ],
        ],
        'primaryPath' => 'scene.1',
    ]);
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

test('hierarchy panel reparents a dragged object onto another object on mouse release', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Player'],
            ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Enemy'],
        ],
    );

    $contentArea = getHierarchyContentAreaPosition($panel);

    $panel->handleMouseClick($contentArea['x'] + 4, $contentArea['y'] + 1);
    $panel->handleMouseDrag($contentArea['x'] + 4, $contentArea['y'] + 2);
    $panel->handleMouseRelease($contentArea['x'] + 4, $contentArea['y'] + 2);

    expect($panel->consumeMoveRequest())->toBe([
        'path' => 'scene.0',
        'targetPath' => 'scene.1',
        'position' => 'append_child',
    ]);
});

test('hierarchy panel moves a dragged child onto the scene root on mouse release', function () {
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
    $panel->expandSelection();
    $contentArea = getHierarchyContentAreaPosition($panel);

    $panel->handleMouseClick($contentArea['x'] + 4, $contentArea['y'] + 2);
    $panel->handleMouseDrag($contentArea['x'] + 4, $contentArea['y']);
    $panel->handleMouseRelease($contentArea['x'] + 4, $contentArea['y']);

    expect($panel->consumeMoveRequest())->toBe([
        'path' => 'scene.0.0',
        'targetPath' => 'scene',
        'position' => 'append_child',
    ]);
});

test('hierarchy panel moves a dragged child to the scene root when released over empty space', function () {
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
    $contentArea = getHierarchyContentAreaPosition($panel);

    $panel->handleMouseClick($contentArea['x'] + 4, $contentArea['y'] + 2);
    $panel->handleMouseDrag($contentArea['x'] + 4, $contentArea['y'] + 6);
    $panel->handleMouseRelease($contentArea['x'] + 4, $contentArea['y'] + 6);

    expect($panel->consumeMoveRequest())->toBe([
        'path' => 'scene.0.0',
        'targetPath' => 'scene',
        'position' => 'append_child',
    ]);
});

test('hierarchy panel queues move requests that can place objects into other trees', function () {
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
            ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Enemy'],
        ],
    );

    $panel->expandSelection();
    $panel->expandSelection();
    $panel->moveSelection(2);
    focusHierarchyPanel($panel);

    pressHierarchyPanelKey('W');
    $panel->update();
    pressHierarchyPanelKey("\033[A");
    $panel->update();

    expect($panel->consumeMoveRequest())->toBe([
        'path' => 'scene.1',
        'targetPath' => 'scene.0.0',
        'position' => 'before',
    ]);
});

test('hierarchy panel returns to select mode on shift+q after move mode is active', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Player'],
        ],
    );

    $panel->expandSelection();
    focusHierarchyPanel($panel);

    $interactionMode = new ReflectionProperty(HierarchyPanel::class, 'interactionMode');
    $interactionMode->setAccessible(true);

    pressHierarchyPanelKey('W');
    $panel->update();

    expect($interactionMode->getValue($panel))->toBe('move');

    pressHierarchyPanelKey('Q');
    $panel->update();

    expect($interactionMode->getValue($panel))->toBe('select');
});

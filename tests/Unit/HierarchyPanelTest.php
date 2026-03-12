<?php

use Sendama\Console\Editor\Widgets\HierarchyPanel;

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

test('hierarchy panel does not inspect the scene root', function () {
    $panel = new HierarchyPanel(
        width: 40,
        height: 12,
        sceneName: 'level01',
        hierarchy: [
            ['type' => 'Sendama\\Engine\\Core\\GameObject', 'name' => 'Player'],
        ],
    );

    $panel->activateSelection();

    expect($panel->consumeInspectionRequest())->toBeNull();
});

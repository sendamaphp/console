<?php

use Sendama\Console\Editor\IO\InputManager;
use Sendama\Console\Editor\Widgets\AssetsPanel;
use Sendama\Console\Editor\Widgets\Widget;

/**
 * @throws ReflectionException
 */
function getAssetsContentAreaPosition(AssetsPanel $panel): array
{
    $getContentAreaLeft = new ReflectionMethod($panel, 'getContentAreaLeft');
    $getContentAreaTop = new ReflectionMethod($panel, 'getContentAreaTop');

    return [
        'x' => $getContentAreaLeft->invoke($panel),
        'y' => $getContentAreaTop->invoke($panel),
    ];
}

test('assets panel loads and traverses nested project assets', function () {
    $workspace = sys_get_temp_dir() . '/sendama-assets-panel-' . uniqid();
    mkdir($workspace . '/Assets/Textures', 0777, true);
    mkdir($workspace . '/Assets/Scripts/Player', 0777, true);

    file_put_contents($workspace . '/Assets/Textures/player.texture', 'texture');
    file_put_contents($workspace . '/Assets/Scripts/Player/controller.php', '<?php');
    file_put_contents($workspace . '/Assets/readme.txt', 'docs');

    $panel = new AssetsPanel(
        width: 40,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
    );

    expect($panel->getSelectedAssetEntry()['name'])->toBe('Scripts')
        ->and($panel->content[0])->toBe('► Scripts')
        ->and($panel->content[1])->toBe('► Textures')
        ->and($panel->content[2])->toBe('• readme.txt');

    $panel->expandSelection();

    expect($panel->content[0])->toBe('▼ Scripts')
        ->and($panel->content[1])->toBe('  ► Player');

    $panel->expandSelection();

    expect($panel->getSelectedAssetEntry()['name'])->toBe('Player');

    $panel->expandSelection();
    $panel->moveSelection(1);

    expect($panel->getSelectedAssetEntry()['name'])->toBe('controller.php');
});

test('assets panel queues the selected asset for inspection', function () {
    $workspace = sys_get_temp_dir() . '/sendama-assets-panel-' . uniqid();
    mkdir($workspace . '/Assets', 0777, true);
    file_put_contents($workspace . '/Assets/readme.txt', 'docs');

    $panel = new AssetsPanel(
        width: 40,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
    );

    $panel->moveSelection(0);
    $panel->activateSelection();

    expect($panel->consumeInspectionRequest())->toBe([
        'context' => 'asset',
        'name' => 'readme.txt',
        'type' => 'File',
        'value' => [
            'name' => 'readme.txt',
            'path' => $workspace . '/Assets/readme.txt',
            'relativePath' => 'readme.txt',
            'isDirectory' => false,
            'children' => [],
        ],
        'openInMainPanel' => true,
    ])
        ->and($panel->consumeInspectionRequest())->toBeNull();
});

test('assets panel queues inspection when selection changes', function () {
    $workspace = sys_get_temp_dir() . '/sendama-assets-panel-' . uniqid();
    mkdir($workspace . '/Assets/Maps', 0777, true);
    mkdir($workspace . '/Assets/Textures', 0777, true);
    file_put_contents($workspace . '/Assets/Maps/level.tmap', "xx\n");
    file_put_contents($workspace . '/Assets/Textures/player.texture', ">\n");

    $panel = new AssetsPanel(
        width: 40,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
    );

    $panel->expandSelection();
    $panel->moveSelection(1);

    expect($panel->consumeInspectionRequest())->toBe([
        'context' => 'asset',
        'name' => 'level.tmap',
        'type' => 'File',
        'value' => [
            'name' => 'level.tmap',
            'path' => $workspace . '/Assets/Maps/level.tmap',
            'relativePath' => 'Maps/level.tmap',
            'isDirectory' => false,
            'children' => [],
        ],
        'openInMainPanel' => false,
    ]);
});

test('assets panel reports folders as folder type in inspector payload', function () {
    $workspace = sys_get_temp_dir() . '/sendama-assets-panel-' . uniqid();
    mkdir($workspace . '/Assets/Scripts', 0777, true);

    $panel = new AssetsPanel(
        width: 40,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
    );

    $panel->activateSelection();

    expect($panel->consumeInspectionRequest())->toBe([
        'context' => 'asset',
        'name' => 'Scripts',
        'type' => 'Folder',
        'value' => [
            'name' => 'Scripts',
            'path' => $workspace . '/Assets/Scripts',
            'relativePath' => 'Scripts',
            'isDirectory' => true,
            'children' => [],
        ],
        'openInMainPanel' => true,
    ]);
});


test('assets panel queues the selected asset for deletion when confirmed', /** @throws Exception */function () {
    $workspace = sys_get_temp_dir() . '/sendama-assets-panel-' . uniqid();
    mkdir($workspace . '/Assets', 0777, true);
    file_put_contents($workspace . '/Assets/readme.txt', 'docs');

    $panel = new AssetsPanel(
        width: 40,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
    );

    $showDeleteConfirmModal = new ReflectionMethod(AssetsPanel::class, 'showDeleteConfirmModal');
    $showDeleteConfirmModal->invoke($panel);

    $handleModalInput = new ReflectionMethod(AssetsPanel::class, 'handleModalInput');

    $keyPress = new ReflectionProperty(InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(InputManager::class, 'previousKeyPress');
    $previousKeyPress->setValue(null, '');
    $keyPress->setValue(null, "\n");

    $deleteConfirmModal = new ReflectionProperty(AssetsPanel::class, 'deleteConfirmModal');
    $modal = $deleteConfirmModal->getValue($panel);
    $moveSelection = new ReflectionMethod($modal, 'moveSelection');
    $moveSelection->invoke($modal, -1);

    $handleModalInput->invoke($panel);

    expect($panel->consumeDeletionRequest())->toBe([
        'path' => '0',
        'assetPath' => $workspace . '/Assets/readme.txt',
        'name' => 'readme.txt',
        'isDirectory' => false,
    ]);
});

test('assets panel cancels delete confirmation without queuing a deletion', /** @throws Exception */ function () {
    $workspace = sys_get_temp_dir() . '/sendama-assets-panel-' . uniqid();
    mkdir($workspace . '/Assets', 0777, true);
    file_put_contents($workspace . '/Assets/readme.txt', 'docs');

    $panel = new AssetsPanel(
        width: 40,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
    );

    $showDeleteConfirmModal = new ReflectionMethod(AssetsPanel::class, 'showDeleteConfirmModal');
    $showDeleteConfirmModal->invoke($panel);

    $handleModalInput = new ReflectionMethod(AssetsPanel::class, 'handleModalInput');

    $keyPress = new ReflectionProperty(InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(InputManager::class, 'previousKeyPress');
    $previousKeyPress->setValue(null, '');
    $keyPress->setValue(null, "\n");

    $handleModalInput->invoke($panel);

    expect($panel->consumeDeletionRequest())->toBeNull();
});

test('assets panel queues the selected asset type for creation when confirmed', /** @throws Exception */ function () {
    $workspace = sys_get_temp_dir() . '/sendama-assets-panel-' . uniqid();
    mkdir($workspace . '/Assets', 0777, true);

    $panel = new AssetsPanel(
        width: 40,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
        workingDirectory: $workspace,
    );

    $panel->beginCreateWorkflow();

    $handleModalInput = new ReflectionMethod(AssetsPanel::class, 'handleModalInput');

    $keyPress = new ReflectionProperty(InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(InputManager::class, 'previousKeyPress');
    $previousKeyPress->setValue(null, '');
    $keyPress->setValue(null, "\n");

    $handleModalInput->invoke($panel);

    expect($panel->consumeCreationRequest())->toBe([
        'kind' => 'script',
        'workingDirectory' => $workspace,
    ]);
});

test('assets panel can queue prefab creation requests', function () {
    $workspace = sys_get_temp_dir() . '/sendama-assets-panel-' . uniqid();
    mkdir($workspace . '/Assets', 0777, true);

    $panel = new AssetsPanel(
        width: 40,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
        workingDirectory: $workspace,
    );

    $panel->beginCreateWorkflow();

    $handleModalInput = new ReflectionMethod(AssetsPanel::class, 'handleModalInput');

    $keyPress = new ReflectionProperty(InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(InputManager::class, 'previousKeyPress');

    $createAssetModal = new ReflectionProperty(AssetsPanel::class, 'createAssetModal');
    $modal = $createAssetModal->getValue($panel);
    $moveSelection = new ReflectionMethod($modal, 'moveSelection');
    $moveSelection->invoke($modal, 2);

    $previousKeyPress->setValue(null, '');
    $keyPress->setValue(null, "\n");
    $handleModalInput->invoke($panel);

    expect($panel->consumeCreationRequest())->toBe([
        'kind' => 'prefab',
        'workingDirectory' => $workspace,
    ]);
});

test('assets panel opens the create modal with shift+a while focused', function () {
    $workspace = sys_get_temp_dir() . '/sendama-assets-panel-' . uniqid();
    mkdir($workspace . '/Assets', 0777, true);

    $panel = new AssetsPanel(
        width: 40,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
        workingDirectory: $workspace,
    );

    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setValue($panel, true);

    $keyPress = new ReflectionProperty(InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(InputManager::class, 'previousKeyPress');
    $previousKeyPress->setValue(null, '');
    $keyPress->setValue(null, 'A');

    $panel->update();

    expect($panel->hasActiveModal())->toBeTrue()
        ->and($panel->consumeCreationRequest())->toBeNull();
});

test('assets panel toggles folder expand and collapse when the icon is clicked', /** @throws Exception */ function () {
    $workspace = sys_get_temp_dir() . '/sendama-assets-panel-' . uniqid();
    mkdir($workspace . '/Assets/Scripts/Player', 0777, true);
    file_put_contents($workspace . '/Assets/Scripts/Player/controller.php', '<?php');

    $panel = new AssetsPanel(
        width: 40,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
    );

    $contentArea = getAssetsContentAreaPosition($panel);
    $panel->handleMouseClick($contentArea['x'], $contentArea['y']);

    expect($panel->content[0])->toBe('▼ Scripts')
        ->and($panel->content[1])->toBe('  ► Player');

    $panel->handleMouseClick($contentArea['x'], $contentArea['y']);

    expect($panel->content[0])->toBe('► Scripts');
});

test('assets panel activates the selected row on double click', function () {
    $workspace = sys_get_temp_dir() . '/sendama-assets-panel-' . uniqid();
    mkdir($workspace . '/Assets', 0777, true);
    file_put_contents($workspace . '/Assets/readme.txt', 'docs');

    $panel = new AssetsPanel(
        width: 40,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
    );

    $contentArea = getAssetsContentAreaPosition($panel);
    $clickX = $contentArea['x'] + 2;
    $clickY = $contentArea['y'];

    $panel->handleMouseClick($clickX, $clickY);
    $panel->handleMouseClick($clickX, $clickY);

    expect($panel->consumeInspectionRequest())->toBe([
        'context' => 'asset',
        'name' => 'readme.txt',
        'type' => 'File',
        'value' => [
            'name' => 'readme.txt',
            'path' => $workspace . '/Assets/readme.txt',
            'relativePath' => 'readme.txt',
            'isDirectory' => false,
            'children' => [],
        ],
        'openInMainPanel' => true,
    ]);
});

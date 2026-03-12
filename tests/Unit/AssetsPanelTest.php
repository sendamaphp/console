<?php

use Sendama\Console\Editor\Widgets\AssetsPanel;

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

    expect($panel->getSelectedAssetEntry()['name'])->toBe('Scripts');
    expect($panel->content[0])->toBe('► Scripts');
    expect($panel->content[1])->toBe('► Textures');
    expect($panel->content[2])->toBe('• readme.txt');

    $panel->expandSelection();

    expect($panel->content[0])->toBe('▼ Scripts');
    expect($panel->content[1])->toBe('  ► Player');

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
    ]);
    expect($panel->consumeInspectionRequest())->toBeNull();
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
    ]);
});

test('assets panel queues the selected asset for deletion when confirmed', function () {
    $workspace = sys_get_temp_dir() . '/sendama-assets-panel-' . uniqid();
    mkdir($workspace . '/Assets', 0777, true);
    file_put_contents($workspace . '/Assets/readme.txt', 'docs');

    $panel = new AssetsPanel(
        width: 40,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
    );

    $showDeleteConfirmModal = new ReflectionMethod(AssetsPanel::class, 'showDeleteConfirmModal');
    $showDeleteConfirmModal->setAccessible(true);
    $showDeleteConfirmModal->invoke($panel);

    $handleModalInput = new ReflectionMethod(AssetsPanel::class, 'handleModalInput');
    $handleModalInput->setAccessible(true);

    $keyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'previousKeyPress');
    $keyPress->setAccessible(true);
    $previousKeyPress->setAccessible(true);
    $previousKeyPress->setValue('');
    $keyPress->setValue("\n");

    $deleteConfirmModal = new ReflectionProperty(AssetsPanel::class, 'deleteConfirmModal');
    $deleteConfirmModal->setAccessible(true);
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

test('assets panel cancels delete confirmation without queuing a deletion', function () {
    $workspace = sys_get_temp_dir() . '/sendama-assets-panel-' . uniqid();
    mkdir($workspace . '/Assets', 0777, true);
    file_put_contents($workspace . '/Assets/readme.txt', 'docs');

    $panel = new AssetsPanel(
        width: 40,
        height: 12,
        assetsDirectoryPath: $workspace . '/Assets',
    );

    $showDeleteConfirmModal = new ReflectionMethod(AssetsPanel::class, 'showDeleteConfirmModal');
    $showDeleteConfirmModal->setAccessible(true);
    $showDeleteConfirmModal->invoke($panel);

    $handleModalInput = new ReflectionMethod(AssetsPanel::class, 'handleModalInput');
    $handleModalInput->setAccessible(true);

    $keyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'previousKeyPress');
    $keyPress->setAccessible(true);
    $previousKeyPress->setAccessible(true);
    $previousKeyPress->setValue('');
    $keyPress->setValue("\n");

    $handleModalInput->invoke($panel);

    expect($panel->consumeDeletionRequest())->toBeNull();
});

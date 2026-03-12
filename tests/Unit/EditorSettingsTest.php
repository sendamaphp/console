<?php

use Sendama\Console\Editor\EditorSettings;

test('editor settings read scenes and console refresh interval from the editor section', function () {
    $settings = EditorSettings::fromArray([
        'editor' => [
            'scenes' => [
                'active' => 1,
                'loaded' => [
                    'Scenes/alpha.scene.php',
                    'Scenes/beta.scene.php',
                ],
            ],
            'console' => [
                'refreshInterval' => 2.5,
            ],
        ],
    ]);

    expect($settings->scenes->active)->toBe(1);
    expect($settings->scenes->loaded)->toBe([
        'Scenes/alpha.scene.php',
        'Scenes/beta.scene.php',
    ]);
    expect($settings->consoleRefreshIntervalSeconds)->toBe(2.5);
});

test('editor settings default the console refresh interval to five seconds', function () {
    $settings = EditorSettings::fromArray([
        'editor' => [
            'scenes' => [
                'active' => 0,
                'loaded' => ['Scenes/level01.scene.php'],
            ],
        ],
    ]);

    expect($settings->consoleRefreshIntervalSeconds)->toBe(5.0);
});

test('editor settings load editor config from sendama json', function () {
    $workspace = sys_get_temp_dir() . '/sendama-editor-settings-' . uniqid();
    mkdir($workspace, 0777, true);

    file_put_contents(
        $workspace . '/sendama.json',
        json_encode([
            'name' => 'blasters',
            'editor' => [
                'scenes' => [
                    'active' => 0,
                    'loaded' => ['Scenes/level01.scene.php'],
                ],
                'console' => [
                    'refreshInterval' => 3,
                ],
            ],
        ], JSON_PRETTY_PRINT)
    );

    $settings = EditorSettings::loadFromDirectory($workspace);

    expect($settings->scenes->loaded)->toBe(['Scenes/level01.scene.php']);
    expect($settings->consoleRefreshIntervalSeconds)->toBe(3.0);
});

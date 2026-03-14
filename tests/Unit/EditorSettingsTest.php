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
            'notifications' => [
                'duration' => 6.0,
            ],
        ],
    ]);

    expect($settings->scenes->active)->toBe(1);
    expect($settings->scenes->loaded)->toBe([
        'Scenes/alpha.scene.php',
        'Scenes/beta.scene.php',
    ]);
    expect($settings->consoleRefreshIntervalSeconds)->toBe(2.5);
    expect($settings->notificationDurationSeconds)->toBe(6.0);
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
    expect($settings->notificationDurationSeconds)->toBe(4.0);
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
                'notifications' => [
                    'duration' => 4.5,
                ],
            ],
        ], JSON_PRETTY_PRINT)
    );

    $settings = EditorSettings::loadFromDirectory($workspace);

    expect($settings->scenes->loaded)->toBe(['Scenes/level01.scene.php']);
    expect($settings->consoleRefreshIntervalSeconds)->toBe(3.0);
    expect($settings->notificationDurationSeconds)->toBe(4.5);
});

test('editor settings fall back to defaults when sendama json is missing', function () {
    $workspace = sys_get_temp_dir() . '/sendama-editor-settings-missing-' . uniqid();
    mkdir($workspace, 0777, true);

    $settings = EditorSettings::loadFromDirectory($workspace);

    expect($settings->scenes->loaded)->toBe([]);
    expect($settings->scenes->active)->toBe(0);
    expect($settings->consoleRefreshIntervalSeconds)->toBe(5.0);
    expect($settings->notificationDurationSeconds)->toBe(4.0);
});

test('editor settings fall back to defaults when sendama json is invalid', function () {
    $workspace = sys_get_temp_dir() . '/sendama-editor-settings-invalid-' . uniqid();
    mkdir($workspace, 0777, true);
    file_put_contents($workspace . '/sendama.json', '{invalid json');

    $settings = EditorSettings::loadFromDirectory($workspace);

    expect($settings->scenes->loaded)->toBe([]);
    expect($settings->consoleRefreshIntervalSeconds)->toBe(5.0);
    expect($settings->notificationDurationSeconds)->toBe(4.0);
});

test('editor settings fall back to the notification default when notification duration is invalid', function () {
    $settings = EditorSettings::fromArray([
        'editor' => [
            'notifications' => [
                'duration' => 0,
            ],
        ],
    ]);

    expect($settings->notificationDurationSeconds)->toBe(4.0);
});

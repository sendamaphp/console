<?php

use Sendama\Console\Editor\Widgets\ConsolePanel;

test('console panel loads the last three log lines on startup', function () {
    $workspace = sys_get_temp_dir() . '/sendama-console-panel-' . uniqid();
    mkdir($workspace . '/logs', 0777, true);

    file_put_contents(
        $workspace . '/logs/debug.log',
        implode(PHP_EOL, [
            '[2026-03-11 10:00:00] [DEBUG] - First',
            '[2026-03-11 10:00:01] [INFO] - Second',
            '[2026-03-11 10:00:02] [WARN] - Third',
            '[2026-03-11 10:00:03] [ERROR] - Fourth',
        ]) . PHP_EOL
    );

    $panel = new ConsolePanel(
        width: 60,
        height: 8,
        logFilePath: $workspace . '/logs/debug.log',
    );

    expect($panel->content)->toBe([
        '[2026-03-11 10:00:01] [INFO] - Second',
        '[2026-03-11 10:00:02] [WARN] - Third',
        '[2026-03-11 10:00:03] [ERROR] - Fourth',
    ]);
});

test('console panel scrolls upward through older log lines', function () {
    $workspace = sys_get_temp_dir() . '/sendama-console-panel-' . uniqid();
    mkdir($workspace . '/logs', 0777, true);

    file_put_contents(
        $workspace . '/logs/debug.log',
        implode(PHP_EOL, [
            'line 1',
            'line 2',
            'line 3',
            'line 4',
            'line 5',
        ]) . PHP_EOL
    );

    $panel = new ConsolePanel(
        width: 40,
        height: 6,
        logFilePath: $workspace . '/logs/debug.log',
    );

    expect($panel->content)->toBe([
        'line 3',
        'line 4',
        'line 5',
    ]);

    $panel->scrollUp();

    expect($panel->content)->toBe([
        'line 2',
        'line 3',
        'line 4',
        'line 5',
    ]);

    $panel->scrollUp();
    $panel->scrollUp();

    expect($panel->content)->toBe([
        'line 1',
        'line 2',
        'line 3',
        'line 4',
    ]);
});

test('console panel scrolls down until the last log line is at the top', function () {
    $workspace = sys_get_temp_dir() . '/sendama-console-panel-' . uniqid();
    mkdir($workspace . '/logs', 0777, true);

    file_put_contents(
        $workspace . '/logs/debug.log',
        implode(PHP_EOL, [
            'line 1',
            'line 2',
            'line 3',
            'line 4',
            'line 5',
        ]) . PHP_EOL
    );

    $panel = new ConsolePanel(
        width: 40,
        height: 6,
        logFilePath: $workspace . '/logs/debug.log',
    );

    $panel->scrollDown();
    $panel->scrollDown();
    $panel->scrollDown();
    $panel->scrollDown();

    expect($panel->content)->toBe([
        'line 5',
    ]);
});

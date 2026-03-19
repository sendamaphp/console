<?php

use Sendama\Console\Editor\IO\InputManager;
use Sendama\Console\Editor\Widgets\ConsolePanel;
use Sendama\Console\Editor\Widgets\Widget;

function pressConsoleKey(string $keyPress): void
{
    $currentKeyPress = new ReflectionProperty(InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(InputManager::class, 'previousKeyPress');
    $previousKeyPress->setValue(null, '');
    $currentKeyPress->setValue(null, $keyPress);
}

function getConsoleContentAreaPosition(ConsolePanel $panel): array
{
    $getContentAreaLeft = new ReflectionMethod($panel, 'getContentAreaLeft');
    $getContentAreaTop = new ReflectionMethod($panel, 'getContentAreaTop');

    return [
        'x' => $getContentAreaLeft->invoke($panel),
        'y' => $getContentAreaTop->invoke($panel),
    ];
}

test('console panel loads the last three debug log lines on startup', function () {
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

    expect($panel->getActiveTab())->toBe('Debug')
        ->and($panel->getActiveFilter())->toBe('DEBUG')
        ->and(array_slice($panel->content, 2))->toBe([
            '[2026-03-11 10:00:00] [DEBUG] - First',
        ]);
});

test('console panel switches between debug and error tabs', function () {
    $workspace = sys_get_temp_dir() . '/sendama-console-panel-' . uniqid();
    mkdir($workspace . '/logs', 0777, true);

    file_put_contents(
        $workspace . '/logs/debug.log',
        implode(PHP_EOL, [
            'debug 1',
            'debug 2',
            'debug 3',
        ]) . PHP_EOL
    );

    file_put_contents(
        $workspace . '/logs/error.log',
        implode(PHP_EOL, [
            'error 1',
            'error 2',
        ]) . PHP_EOL
    );

    $panel = new ConsolePanel(
        width: 60,
        height: 8,
        logFilePath: $workspace . '/logs/debug.log',
        errorLogFilePath: $workspace . '/logs/error.log',
    );

    expect($panel->content[0])->toContain('Debug')
        ->and($panel->content[0])->toContain('Error')
        ->and(array_slice($panel->content, 2))->toBe([
            'debug 1',
            'debug 2',
            'debug 3',
        ]);

    $panel->cycleFocusForward();

    expect($panel->getActiveTab())->toBe('Error')
        ->and(array_slice($panel->content, 2))->toBe([
            'error 1',
            'error 2',
        ]);

    $panel->cycleFocusBackward();

    expect($panel->getActiveTab())->toBe('Debug');
});

test('console panel switches tabs when the tab label is clicked', function () {
    $workspace = sys_get_temp_dir() . '/sendama-console-panel-' . uniqid();
    mkdir($workspace . '/logs', 0777, true);

    file_put_contents($workspace . '/logs/debug.log', "debug 1\n");
    file_put_contents($workspace . '/logs/error.log', "error 1\n");

    $panel = new ConsolePanel(
        width: 60,
        height: 8,
        logFilePath: $workspace . '/logs/debug.log',
        errorLogFilePath: $workspace . '/logs/error.log',
    );

    $contentArea = getConsoleContentAreaPosition($panel);
    $errorTabOffset = mb_strpos($panel->content[0], 'Error');

    expect($errorTabOffset)->not->toBeFalse();

    $panel->handleMouseClick($contentArea['x'] + $errorTabOffset, $contentArea['y']);

    expect($panel->getActiveTab())->toBe('Error')
        ->and(array_slice($panel->content, 2))->toBe(['error 1']);
});

test('console panel ignores missing tab log files', function () {
    $workspace = sys_get_temp_dir() . '/sendama-console-panel-' . uniqid();
    mkdir($workspace . '/logs', 0777, true);

    file_put_contents(
        $workspace . '/logs/error.log',
        implode(PHP_EOL, [
            '[2026-03-11 10:00:01] [ERROR] - First',
            '[2026-03-11 10:00:02] [ERROR] - Second',
        ]) . PHP_EOL
    );

    $panel = new ConsolePanel(
        width: 60,
        height: 8,
        logFilePath: $workspace . '/logs/debug.log',
        errorLogFilePath: $workspace . '/logs/error.log',
    );

    expect(array_slice($panel->content, 2))->toBe([]);

    $panel->cycleFocusForward();

    expect($panel->getActiveTab())->toBe('Error')
        ->and(array_slice($panel->content, 2))->toBe([
            '[2026-03-11 10:00:01] [ERROR] - First',
            '[2026-03-11 10:00:02] [ERROR] - Second',
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
        height: 8,
        logFilePath: $workspace . '/logs/debug.log',
    );

    expect(array_slice($panel->content, 2))->toBe([
        'line 3',
        'line 4',
        'line 5',
    ]);

    $panel->scrollUp();

    expect(array_slice($panel->content, 2))->toBe([
        'line 2',
        'line 3',
        'line 4',
        'line 5',
    ]);

    $panel->scrollUp();
    $panel->scrollUp();

    expect(array_slice($panel->content, 2))->toBe([
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
        height: 8,
        logFilePath: $workspace . '/logs/debug.log',
    );

    $panel->scrollDown();
    $panel->scrollDown();
    $panel->scrollDown();
    $panel->scrollDown();

    expect(array_slice($panel->content, 2))->toBe([
        'line 5',
    ]);
});

test('console panel wraps long log lines across visible rows', function () {
    $workspace = sys_get_temp_dir() . '/sendama-console-panel-' . uniqid();
    mkdir($workspace . '/logs', 0777, true);

    $message = '[2026-03-15 10:00:00] [DEBUG] - This is a very long log line that should wrap cleanly.';

    file_put_contents(
        $workspace . '/logs/debug.log',
        $message . PHP_EOL
    );

    $panel = new ConsolePanel(
        width: 36,
        height: 8,
        logFilePath: $workspace . '/logs/debug.log',
    );

    $visibleMessages = array_slice($panel->content, 2);

    expect(count($visibleMessages))->toBeGreaterThan(1)
        ->and(implode('', $visibleMessages))->toBe($message)
        ->and(array_all(
            $visibleMessages,
            static fn(string $line): bool => mb_strwidth($line, 'UTF-8') <= 32,
        ))->toBeTrue();
});

test('console panel renders a scrollbar when there are more wrapped lines than the viewport can show', function () {
    $workspace = sys_get_temp_dir() . '/sendama-console-panel-' . uniqid();
    mkdir($workspace . '/logs', 0777, true);

    file_put_contents(
        $workspace . '/logs/debug.log',
        implode(PHP_EOL, array_map(
            static fn (int $index): string => '[2026-03-15 10:00:0' . $index . '] [DEBUG] - line ' . $index,
            range(1, 8),
        )) . PHP_EOL
    );

    $panel = new ConsolePanel(
        width: 36,
        height: 8,
        logFilePath: $workspace . '/logs/debug.log',
    );

    $buildRenderedContentLines = new ReflectionMethod(Widget::class, 'buildRenderedContentLines');
    $buildRenderedContentLines->setAccessible(true);
    $lines = $buildRenderedContentLines->invoke($panel);

    expect(array_any($lines, static fn (string $line): bool => str_contains($line, '█') || str_contains($line, '░')))->toBeTrue();
});

test('console panel refreshes the active tab from disk on shift+r when focused', function () {
    $workspace = sys_get_temp_dir() . '/sendama-console-panel-' . uniqid();
    mkdir($workspace . '/logs', 0777, true);

    $logFilePath = $workspace . '/logs/error.log';

    file_put_contents(
        $logFilePath,
        implode(PHP_EOL, [
            'line 1',
            'line 2',
            'line 3',
        ]) . PHP_EOL
    );

    $panel = new ConsolePanel(
        width: 40,
        height: 8,
        errorLogFilePath: $logFilePath,
    );

    $panel->cycleFocusForward();

    file_put_contents(
        $logFilePath,
        implode(PHP_EOL, [
            'line 1',
            'line 2',
            'line 3',
            'line 4',
            'line 5',
            'line 6',
        ]) . PHP_EOL
    );

    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setValue($panel, true);

    $keyPress = new ReflectionProperty(InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(InputManager::class, 'previousKeyPress');
    $previousKeyPress->setValue(null, '');
    $keyPress->setValue(null, 'R');

    $panel->update();

    expect($panel->getActiveTab())->toBe('Error')
        ->and(array_slice($panel->content, 2))->toBe([
            'line 3',
            'line 4',
            'line 5',
            'line 6',
        ]);
});

test('console panel does not auto refresh outside play mode', function () {
    $workspace = sys_get_temp_dir() . '/sendama-console-panel-' . uniqid();
    mkdir($workspace . '/logs', 0777, true);

    $logFilePath = $workspace . '/logs/debug.log';

    file_put_contents(
        $logFilePath,
        implode(PHP_EOL, [
            'line 1',
            'line 2',
            'line 3',
        ]) . PHP_EOL
    );

    $panel = new ConsolePanel(
        width: 40,
        height: 8,
        logFilePath: $logFilePath,
        refreshIntervalSeconds: 1.0,
    );

    file_put_contents(
        $logFilePath,
        implode(PHP_EOL, [
            'line 1',
            'line 2',
            'line 3',
            'line 4',
            'line 5',
        ]) . PHP_EOL
    );

    $lastLogRefreshAt = new ReflectionProperty(ConsolePanel::class, 'lastLogRefreshAt');
    $lastLogRefreshAt->setValue($panel, microtime(true) - 2);

    $panel->update();

    expect(array_slice($panel->content, 2))->toBe([
        'line 1',
        'line 2',
        'line 3',
    ]);
});

test('console panel automatically refreshes from the active tab log file during play mode', function () {
    $workspace = sys_get_temp_dir() . '/sendama-console-panel-' . uniqid();
    mkdir($workspace . '/logs', 0777, true);

    $logFilePath = $workspace . '/logs/debug.log';

    file_put_contents(
        $logFilePath,
        implode(PHP_EOL, [
            'line 1',
            'line 2',
            'line 3',
        ]) . PHP_EOL
    );

    $panel = new ConsolePanel(
        width: 40,
        height: 8,
        logFilePath: $logFilePath,
        refreshIntervalSeconds: 1.0,
    );

    $panel->setPlayModeActive(true);

    file_put_contents(
        $logFilePath,
        implode(PHP_EOL, [
            'line 1',
            'line 2',
            'line 3',
            'line 4',
            'line 5',
        ]) . PHP_EOL
    );

    $lastLogRefreshAt = new ReflectionProperty(ConsolePanel::class, 'lastLogRefreshAt');
    $lastLogRefreshAt->setValue($panel, microtime(true) - 2);

    $panel->update();

    expect(array_slice($panel->content, 2))->toBe([
        'line 2',
        'line 3',
        'line 4',
        'line 5',
    ]);
});

test('console panel opens a filter modal on shift+f and filters debug logs by level', function () {
    $workspace = sys_get_temp_dir() . '/sendama-console-panel-' . uniqid();
    mkdir($workspace . '/logs', 0777, true);

    file_put_contents(
        $workspace . '/logs/debug.log',
        implode(PHP_EOL, [
            '[2026-03-13 10:00:00] [DEBUG] - First',
            '[2026-03-13 10:00:01] [INFO] - Second',
            '[2026-03-13 10:00:02] [WARN] - Third',
            '[2026-03-13 10:00:03] [ERROR] - Fourth',
        ]) . PHP_EOL
    );

    $panel = new ConsolePanel(
        width: 60,
        height: 8,
        logFilePath: $workspace . '/logs/debug.log',
    );

    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setValue($panel, true);

    pressConsoleKey('F');
    $panel->update();

    expect($panel->hasActiveModal())->toBeTrue();

    pressConsoleKey("\033[B");
    $panel->update();
    pressConsoleKey("\n");
    $panel->update();

    expect($panel->hasActiveModal())->toBeFalse()
        ->and(array_slice($panel->content, 2))->toBe([
            '[2026-03-13 10:00:01] [INFO] - Second',
        ]);
});

test('console panel filters error logs with error-tab-specific levels', function () {
    $workspace = sys_get_temp_dir() . '/sendama-console-panel-' . uniqid();
    mkdir($workspace . '/logs', 0777, true);

    file_put_contents(
        $workspace . '/logs/error.log',
        implode(PHP_EOL, [
            '[2026-03-13 10:00:00] [ERROR] - First',
            '[2026-03-13 10:00:01] [CRITICAL] - Second',
            '[2026-03-13 10:00:02] [FATAL] - Third',
        ]) . PHP_EOL
    );

    $panel = new ConsolePanel(
        width: 60,
        height: 8,
        errorLogFilePath: $workspace . '/logs/error.log',
    );

    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setValue($panel, true);

    $panel->cycleFocusForward();

    pressConsoleKey('F');
    $panel->update();
    pressConsoleKey("\033[B");
    $panel->update();
    pressConsoleKey("\033[B");
    $panel->update();
    pressConsoleKey("\033[B");
    $panel->update();
    pressConsoleKey("\n");
    $panel->update();

    expect($panel->getActiveTab())->toBe('Error')
        ->and(array_slice($panel->content, 2))->toBe([
            '[2026-03-13 10:00:02] [FATAL] - Third',
        ]);
});

test('console panel rotates and clears the active log file on confirmed shift+c', function () {
    $workspace = sys_get_temp_dir() . '/sendama-console-panel-' . uniqid();
    mkdir($workspace . '/logs', 0777, true);
    $logFilePath = $workspace . '/logs/debug.log';

    file_put_contents(
        $logFilePath,
        implode(PHP_EOL, [
            '[2026-03-13 10:00:00] [DEBUG] - First',
            '[2026-03-13 10:00:01] [INFO] - Second',
        ]) . PHP_EOL
    );

    $panel = new ConsolePanel(
        width: 60,
        height: 8,
        logFilePath: $logFilePath,
    );

    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setValue($panel, true);

    pressConsoleKey('C');
    $panel->update();

    expect($panel->hasActiveModal())->toBeTrue();

    pressConsoleKey("\033[B");
    $panel->update();
    pressConsoleKey("\n");
    $panel->update();

    expect($panel->hasActiveModal())->toBeFalse()
        ->and(file_get_contents($logFilePath))->toBe('')
        ->and(file_get_contents($logFilePath . '.1'))->toContain('[2026-03-13 10:00:00] [DEBUG] - First')
        ->and(array_slice($panel->content, 2))->toBe([]);
});

test('console panel leaves the active log file unchanged when clear is cancelled', function () {
    $workspace = sys_get_temp_dir() . '/sendama-console-panel-' . uniqid();
    mkdir($workspace . '/logs', 0777, true);
    $logFilePath = $workspace . '/logs/error.log';

    file_put_contents(
        $logFilePath,
        implode(PHP_EOL, [
            '[2026-03-13 10:00:00] [ERROR] - First',
            '[2026-03-13 10:00:01] [FATAL] - Second',
        ]) . PHP_EOL
    );

    $panel = new ConsolePanel(
        width: 60,
        height: 8,
        errorLogFilePath: $logFilePath,
    );

    $hasFocus = new ReflectionProperty(Widget::class, 'hasFocus');
    $hasFocus->setValue($panel, true);
    $panel->cycleFocusForward();

    pressConsoleKey('C');
    $panel->update();

    expect($panel->hasActiveModal())->toBeTrue();

    pressConsoleKey("\n");
    $panel->update();

    expect($panel->hasActiveModal())->toBeFalse()
        ->and(file_get_contents($logFilePath))->toContain('[2026-03-13 10:00:01] [FATAL] - Second')
        ->and(file_exists($logFilePath . '.1'))->toBeFalse();
});

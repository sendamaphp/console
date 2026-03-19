<?php

use Sendama\Console\Editor\Editor;
use Sendama\Console\Editor\EditorSceneSettings;
use Sendama\Console\Editor\EditorSettings;

beforeEach(function () {
    putenv('VISUAL');
    putenv('EDITOR');
    unset($_ENV['VISUAL'], $_ENV['EDITOR']);
});

afterEach(function () {
    putenv('VISUAL');
    putenv('EDITOR');
    unset($_ENV['VISUAL'], $_ENV['EDITOR']);
});

test('editor builds an external editor command from VISUAL', function () {
    [$reflection, $editor] = newBareEditorForTmuxTests();
    putenv('VISUAL=nvim');

    $method = $reflection->getMethod('buildExternalEditorCommand');

    $command = $method->invoke($editor, '/tmp/Assets/Scripts/PlayerController.php');

    expect($command)->toBe("nvim '/tmp/Assets/Scripts/PlayerController.php'");
});

test('editor builds an external editor command from configured templates', function () {
    [$reflection, $editor] = newBareEditorForTmuxTests();
    $reflection->getProperty('settings')->setValue(
        $editor,
        new EditorSettings(
            scenes: EditorSceneSettings::fromArray([]),
            externalEditorCommand: 'code --wait {path}',
            externalEditorMode: 'gui',
            externalEditorBlocking: true,
        ),
    );

    $method = $reflection->getMethod('buildExternalEditorCommand');
    $command = $method->invoke($editor, '/tmp/Assets/Scripts/PlayerController.php');

    expect($command)->toBe("code --wait '/tmp/Assets/Scripts/PlayerController.php'");
});

test('editor auto-detects gui editor commands when the mode is automatic', function () {
    [$reflection, $editor] = newBareEditorForTmuxTests();
    $reflection->getProperty('settings')->setValue(
        $editor,
        new EditorSettings(
            scenes: EditorSceneSettings::fromArray([]),
            externalEditorCommand: 'code {path}',
        ),
    );

    $commandMethod = $reflection->getMethod('buildExternalEditorCommand');
    $modeMethod = $reflection->getMethod('resolveExternalEditorMode');
    $blockingMethod = $reflection->getMethod('shouldBlockOnExternalEditor');

    $command = $commandMethod->invoke($editor, '/tmp/Assets/Scripts/PlayerController.php');

    expect($modeMethod->invoke($editor, $command))->toBe('gui')
        ->and($blockingMethod->invoke($editor, $command, 'gui'))->toBeFalse();
});

test('editor builds a tmux play command that keeps the game inside the pane', function () {
    $workspace = sys_get_temp_dir() . '/sendama-editor-tmux-' . uniqid();
    mkdir($workspace, 0777, true);

    [$reflection, $editor] = newBareEditorForTmuxTests();
    $reflection->getProperty('workingDirectory')->setValue($editor, $workspace);

    $method = $reflection->getMethod('buildTmuxPlayCommand');

    $command = $method->invoke($editor);

    expect($command)->toContain('SENDAMA_TMUX_CHILD=1')
        ->toContain(escapeshellarg(PHP_BINARY))
        ->toContain(escapeshellarg('/home/amasiye/development/games/sendama/console/bin/sendama'))
        ->toContain(' play --directory ')
        ->toContain(escapeshellarg($workspace));
});

test('editor builds a tmux pane command for play mode', function () {
    $reflection = new ReflectionClass(Editor::class);
    $method = $reflection->getMethod('buildTmuxSplitPaneCommand');

    $command = $method->invoke(null, '/tmp/game', "php '/tmp/sendama' play --directory '/tmp/game'");

    expect($command)->toContain('tmux split-window -v -d -P')
        ->toContain("-p 40")
        ->toContain(escapeshellarg('/tmp/game'))
        ->toContain(escapeshellarg("php '/tmp/sendama' play --directory '/tmp/game'"));
});

test('editor builds a tmux window command for external script editors', function () {
    $reflection = new ReflectionClass(Editor::class);
    $method = $reflection->getMethod('buildTmuxNewWindowCommand');

    $command = $method->invoke(null, 'PlayerController', '/tmp/project', "nvim '/tmp/project/Assets/Scripts/PlayerController.php'");

    expect($command)->toContain('tmux new-window -n')
        ->toContain(escapeshellarg('PlayerController'))
        ->toContain(escapeshellarg('/tmp/project'))
        ->toContain(escapeshellarg("nvim '/tmp/project/Assets/Scripts/PlayerController.php'"));
});

/**
 * @return array{ReflectionClass, Editor}
 */
function newBareEditorForTmuxTests(): array
{
    $reflection = new ReflectionClass(Editor::class);
    $editor = $reflection->newInstanceWithoutConstructor();
    $initializeObservers = $reflection->getMethod('initializeObservers');
    $initializeObservers->invoke($editor);

    return [$reflection, $editor];
}

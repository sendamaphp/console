<?php

use Sendama\Console\Commands\PlayGame;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

test('play command launches the game from the target project directory when given a relative directory', function () {
    $parentDirectory = sys_get_temp_dir() . '/sendama-play-game-' . uniqid();
    $projectDirectory = $parentDirectory . '/break-out';
    mkdir($projectDirectory, 0777, true);

    file_put_contents($projectDirectory . '/sendama.json', json_encode([
        'name' => 'Break Out',
        'main' => 'break-out.php',
    ], JSON_PRETTY_PRINT));

    file_put_contents(
        $projectDirectory . '/break-out.php',
        <<<'PHP'
<?php
file_put_contents(__DIR__ . '/cwd.txt', getcwd() ?: '');
PHP
    );

    $command = new PlayGame();
    $input = new ArrayInput([
        '--directory' => 'break-out',
    ]);
    $output = new BufferedOutput();
    $originalWorkingDirectory = getcwd();

    try {
        chdir($parentDirectory);
        $exitCode = $command->run($input, $output);
    } finally {
        if ($originalWorkingDirectory !== false) {
            chdir($originalWorkingDirectory);
        }
    }

    expect($exitCode)->toBe(0)
        ->and(file_get_contents($projectDirectory . '/cwd.txt'))->toBe($projectDirectory);
});

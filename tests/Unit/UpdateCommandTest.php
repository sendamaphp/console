<?php

use Sendama\Console\Commands\Update;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function createValidUpdateProject(string $projectDirectory): void
{
    mkdir($projectDirectory, 0777, true);

    file_put_contents($projectDirectory . '/composer.json', json_encode([
        'name' => 'sendama/test-game',
        'require' => [
            'sendamaphp/engine' => '*',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    file_put_contents($projectDirectory . '/sendama.json', json_encode([
        'name' => 'Test Game',
        'main' => 'game.php',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

test('update command runs composer update inside the target project directory', function () {
    $projectDirectory = sys_get_temp_dir() . '/sendama-update-' . uniqid();
    createValidUpdateProject($projectDirectory);

    $command = new class extends Update {
        public ?string $capturedDirectory = null;

        protected function runComposerUpdate(string $directory): int
        {
            $this->capturedDirectory = $directory;
            echo "composer update {$directory}\n";

            return Command::SUCCESS;
        }
    };

    $input = new ArrayInput([
        '--directory' => $projectDirectory,
    ]);
    $output = new BufferedOutput();

    ob_start();
    $exitCode = $command->run($input, $output);
    $passthruOutput = ob_get_clean();

    expect($exitCode)->toBe(0)
        ->and($command->capturedDirectory)->toBe($projectDirectory)
        ->and($output->fetch())->toContain('Updating the game...')
        ->toContain('Update completed.')
        ->and($passthruOutput)->toContain("composer update {$projectDirectory}");
});

test('update command returns failure when composer update fails', function () {
    $projectDirectory = sys_get_temp_dir() . '/sendama-update-fail-' . uniqid();
    createValidUpdateProject($projectDirectory);

    $command = new class extends Update {
        protected function runComposerUpdate(string $directory): int
        {
            echo "composer update failed for {$directory}\n";

            return 1;
        }
    };

    $input = new ArrayInput([
        '--directory' => $projectDirectory,
    ]);
    $output = new BufferedOutput();

    ob_start();
    $exitCode = $command->run($input, $output);
    $passthruOutput = ob_get_clean();

    expect($exitCode)->toBe(1)
        ->and($output->fetch())->toContain('Updating the game...')
        ->toContain('Update failed.')
        ->and($passthruOutput)->toContain("composer update failed for {$projectDirectory}");
});

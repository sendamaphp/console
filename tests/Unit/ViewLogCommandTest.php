<?php

use Sendama\Console\Commands\ViewLog;
use Sendama\Console\Enumerations\LogOption;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function createValidLogProject(string $projectDirectory): void
{
    mkdir($projectDirectory . '/logs', 0777, true);

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

    file_put_contents($projectDirectory . '/logs/debug.log', "debug line\n");
    file_put_contents($projectDirectory . '/logs/error.log', "error line\n");
}

test('view log command resolves a relative project directory and opens the requested log file', function () {
    $parentDirectory = sys_get_temp_dir() . '/sendama-view-log-parent-' . uniqid();
    $projectDirectory = $parentDirectory . '/blasters';
    createValidLogProject($projectDirectory);

    $command = new class extends ViewLog {
        public array $capturedLogFiles = [];
        public ?string $capturedType = null;

        protected function runLogViewer(array $logFiles, LogOption $type): int
        {
            $this->capturedLogFiles = $logFiles;
            $this->capturedType = $type->value;

            return Command::SUCCESS;
        }
    };

    $input = new ArrayInput([
        'type' => 'debug',
        '--directory' => 'blasters',
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
        ->and($command->capturedType)->toBe('debug')
        ->and($command->capturedLogFiles)->toBe([
            $projectDirectory . '/logs/debug.log',
        ]);
});

test('view log command opens both debug and error logs when all is requested', function () {
    $projectDirectory = sys_get_temp_dir() . '/sendama-view-log-all-' . uniqid();
    createValidLogProject($projectDirectory);

    $command = new class extends ViewLog {
        public array $capturedLogFiles = [];

        protected function runLogViewer(array $logFiles, LogOption $type): int
        {
            $this->capturedLogFiles = $logFiles;

            return Command::SUCCESS;
        }
    };

    $input = new ArrayInput([
        'type' => 'all',
        '--directory' => $projectDirectory,
    ]);
    $output = new BufferedOutput();

    $exitCode = $command->run($input, $output);

    expect($exitCode)->toBe(0)
        ->and($command->capturedLogFiles)->toBe([
            $projectDirectory . '/logs/debug.log',
            $projectDirectory . '/logs/error.log',
        ]);
});

test('view log command rejects invalid log types', function () {
    $projectDirectory = sys_get_temp_dir() . '/sendama-view-log-invalid-' . uniqid();
    createValidLogProject($projectDirectory);

    $command = new ViewLog();
    $input = new ArrayInput([
        'type' => 'trace',
        '--directory' => $projectDirectory,
    ]);
    $output = new BufferedOutput();

    $exitCode = $command->run($input, $output);

    expect($exitCode)->toBe(1)
        ->and($output->fetch())->toContain('Invalid log type.');
});

test('view log command fails when the requested log file is missing', function () {
    $projectDirectory = sys_get_temp_dir() . '/sendama-view-log-missing-' . uniqid();
    createValidLogProject($projectDirectory);
    unlink($projectDirectory . '/logs/error.log');

    $command = new ViewLog();
    $input = new ArrayInput([
        'type' => 'error',
        '--directory' => $projectDirectory,
    ]);
    $output = new BufferedOutput();

    $exitCode = $command->run($input, $output);

    expect($exitCode)->toBe(1)
        ->and($output->fetch())->toContain('Log file ' . $projectDirectory . '/logs/error.log not found.');
});

test('view log command prefers multitail for all logs when available', function () {
    $command = new class extends ViewLog {
        protected function hasMultitail(): bool
        {
            return true;
        }

        public function exposeBuildLogViewerCommand(array $logFiles, LogOption $type): string
        {
            return $this->buildLogViewerCommand($logFiles, $type);
        }
    };

    $commandLine = $command->exposeBuildLogViewerCommand([
        '/tmp/debug.log',
        '/tmp/error.log',
    ], LogOption::ALL);

    expect($commandLine)->toBe("multitail '/tmp/debug.log' '/tmp/error.log'");
});

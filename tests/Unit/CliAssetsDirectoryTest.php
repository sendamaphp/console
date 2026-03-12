<?php

use Sendama\Console\Commands\GenerateScene;
use Sendama\Console\Commands\GenerateScript;
use Sendama\Console\Commands\GenerateTexture;
use Sendama\Console\Commands\NewGame;
use Sendama\Console\Util\Path;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function createCliAssetsWorkspace(): string
{
    $workspace = sys_get_temp_dir() . '/sendama-cli-assets-' . uniqid();
    mkdir($workspace, 0777, true);

    file_put_contents($workspace . '/sendama.json', json_encode([
        'name' => 'CLI Test Game',
    ], JSON_PRETTY_PRINT));

    file_put_contents($workspace . '/composer.json', json_encode([
        'name' => 'tmp/cli-test-game',
        'require' => [
            'sendamaphp/engine' => '*',
        ],
        'autoload' => [
            'psr-4' => [
                'Tmp\\CliTest\\' => 'Assets/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    mkdir($workspace . '/Assets', 0777, true);

    return $workspace;
}

function createLegacyCliAssetsWorkspace(): string
{
    $workspace = sys_get_temp_dir() . '/sendama-cli-assets-legacy-' . uniqid();
    mkdir($workspace, 0777, true);

    file_put_contents($workspace . '/sendama.json', json_encode([
        'name' => 'CLI Legacy Test Game',
    ], JSON_PRETTY_PRINT));

    file_put_contents($workspace . '/composer.json', json_encode([
        'name' => 'tmp/cli-legacy-test-game',
        'require' => [
            'sendamaphp/engine' => '*',
        ],
        'autoload' => [
            'psr-4' => [
                'Tmp\\CliLegacy\\' => 'assets/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    mkdir($workspace . '/assets', 0777, true);

    return $workspace;
}

function runGeneratorCommandInWorkspace(object $command, string $workspace, array $arguments): int
{
    $originalWorkingDirectory = getcwd();
    $input = new ArrayInput($arguments);
    $input->setInteractive(false);
    $output = new BufferedOutput();

    chdir($workspace);

    try {
        return $command->run($input, $output);
    } finally {
        if ($originalWorkingDirectory !== false) {
            chdir($originalWorkingDirectory);
        }
    }
}

test('new game composer configuration uses Assets autoload path', function () {
    $command = new NewGame();
    $method = new ReflectionMethod(NewGame::class, 'getComposerConfiguration');
    $method->setAccessible(true);

    $configuration = json_decode($method->invoke($command, 'sendama-engine/test-game'), true, flags: JSON_THROW_ON_ERROR);

    expect($configuration['autoload']['psr-4']['SendamaEngine\\TestGame\\'])->toBe('Assets/');
});

test('new game creates an Assets directory', function () {
    $workspace = sys_get_temp_dir() . '/sendama-new-game-assets-' . uniqid();
    mkdir($workspace, 0777, true);

    $command = new NewGame();
    $property = new ReflectionProperty(NewGame::class, 'targetDirectory');
    $property->setAccessible(true);
    $property->setValue($command, $workspace);

    $method = new ReflectionMethod(NewGame::class, 'createAssetsDirectory');
    $method->setAccessible(true);
    $assetsDirectory = $method->invoke($command);

    expect($assetsDirectory)->toBe($workspace . '/Assets');
    expect(is_dir($workspace . '/Assets'))->toBeTrue();
});

test('new game creates configuration json content for new projects', function () {
    $workspace = sys_get_temp_dir() . '/sendama-new-game-configuration-' . uniqid();
    mkdir($workspace, 0777, true);

    $command = new NewGame();
    $property = new ReflectionProperty(NewGame::class, 'targetDirectory');
    $property->setAccessible(true);
    $property->setValue($command, $workspace);

    $method = new ReflectionMethod(NewGame::class, 'createConfigurationJsonFile');
    $method->setAccessible(true);
    $method->invoke($command, 'Test Game');

    $configuration = json_decode(
        file_get_contents($workspace . '/configuration.json'),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    expect($configuration['project']['name'])->toBe('Test Game');
    expect($configuration['project']['main'])->toBe('test-game.php');
});

test('generate script creates files under Assets', function () {
    $workspace = createCliAssetsWorkspace();
    $exitCode = runGeneratorCommandInWorkspace(
        new GenerateScript(),
        $workspace,
        ['name' => 'player'],
    );

    expect($exitCode)->toBe(0);
    expect(is_file($workspace . '/Assets/Scripts/Player.php'))->toBeTrue();
});

test('generate texture creates files under Assets', function () {
    $workspace = createCliAssetsWorkspace();
    $exitCode = runGeneratorCommandInWorkspace(
        new GenerateTexture(),
        $workspace,
        ['name' => 'player'],
    );

    expect($exitCode)->toBe(0);
    expect(is_file($workspace . '/Assets/Textures/player.texture'))->toBeTrue();
});

test('generate scene creates files under Assets', function () {
    $workspace = createCliAssetsWorkspace();
    $exitCode = runGeneratorCommandInWorkspace(
        new GenerateScene(),
        $workspace,
        ['name' => 'level01'],
    );

    expect($exitCode)->toBe(0);
    expect(is_file($workspace . '/Assets/Scenes/level01.scene.php'))->toBeTrue();
});

test('working directory assets path prefers Assets', function () {
    $workspace = createCliAssetsWorkspace();
    $originalWorkingDirectory = getcwd();
    chdir($workspace);

    try {
        expect(Path::getWorkingDirectoryAssetsPath())->toBe($workspace . '/Assets');
    } finally {
        if ($originalWorkingDirectory !== false) {
            chdir($originalWorkingDirectory);
        }
    }
});

test('generate script uses the existing lowercase assets directory in legacy projects', function () {
    $workspace = createLegacyCliAssetsWorkspace();
    $exitCode = runGeneratorCommandInWorkspace(
        new GenerateScript(),
        $workspace,
        ['name' => 'player'],
    );

    expect($exitCode)->toBe(0);
    expect(is_file($workspace . '/assets/Scripts/Player.php'))->toBeTrue();
    expect(is_dir($workspace . '/Assets'))->toBeFalse();
});

test('working directory assets path falls back to legacy lowercase assets when needed', function () {
    $workspace = createLegacyCliAssetsWorkspace();
    $originalWorkingDirectory = getcwd();
    chdir($workspace);

    try {
        expect(Path::getWorkingDirectoryAssetsPath())->toBe($workspace . '/assets');
    } finally {
        if ($originalWorkingDirectory !== false) {
            chdir($originalWorkingDirectory);
        }
    }
});

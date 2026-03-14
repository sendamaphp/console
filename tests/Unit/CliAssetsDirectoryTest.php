<?php

use Sendama\Console\Commands\GenerateScene;
use Sendama\Console\Commands\GeneratePrefab;
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

    $configuration = json_decode($method->invoke($command, 'sendama-engine/test-game'), true, flags: JSON_THROW_ON_ERROR);

    expect($configuration['autoload']['psr-4']['SendamaEngine\\TestGame\\'])->toBe('Assets/');
});

test('new game project configuration includes editor defaults for the Level scene and console refresh', function () {
    $command = new NewGame();
    $method = new ReflectionMethod(NewGame::class, 'getProjectConfiguration');

    $configuration = json_decode($method->invoke($command, 'Test Game'), true, flags: JSON_THROW_ON_ERROR);

    expect($configuration['editor']['scenes']['active'])->toBe(0);
    expect($configuration['editor']['scenes']['loaded'])->toBe(['Scenes/Level.scene.php']);
    expect($configuration['editor']['console']['refreshInterval'])->toBe(5);
    expect($configuration['editor']['notifications']['duration'])->toBe(4);
});

test('new game creates an Assets directory', function () {
    $workspace = sys_get_temp_dir() . '/sendama-new-game-assets-' . uniqid();
    mkdir($workspace, 0777, true);

    $command = new NewGame();
    $property = new ReflectionProperty(NewGame::class, 'targetDirectory');
    $property->setValue($command, $workspace);

    $method = new ReflectionMethod(NewGame::class, 'createAssetsDirectory');
    $assetsDirectory = $method->invoke($command);

    expect($assetsDirectory)->toBe($workspace . '/Assets');
    expect(is_dir($workspace . '/Assets'))->toBeTrue();
});

test('asset root resolution prefers populated legacy assets over empty canonical Assets', function () {
    $workspace = sys_get_temp_dir() . '/sendama-assets-root-resolution-' . uniqid();
    mkdir($workspace . '/Assets/Prefabs', 0777, true);
    mkdir($workspace . '/assets/Prefabs', 0777, true);
    file_put_contents($workspace . '/assets/Prefabs/enemy.prefab.php', "<?php return ['name' => 'Enemy'];");

    expect(Path::resolveAssetsDirectory($workspace))->toBe($workspace . '/assets');
});

test('new game creates configuration json content for new projects', function () {
    $workspace = sys_get_temp_dir() . '/sendama-new-game-configuration-' . uniqid();
    mkdir($workspace, 0777, true);

    $command = new NewGame();
    $property = new ReflectionProperty(NewGame::class, 'targetDirectory');
    $property->setValue($command, $workspace);

    $method = new ReflectionMethod(NewGame::class, 'createConfigurationJsonFile');
    $method->invoke($command, 'Test Game');

    $configuration = json_decode(
        file_get_contents($workspace . '/preferences.json'),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    expect($configuration)->toBeEmpty();
});

test('new game creates a default Level scene metadata file', /** @throws Exception */ function () {
    $workspace = sys_get_temp_dir() . '/sendama-new-game-scene-' . uniqid();
    mkdir($workspace, 0777, true);
    mkdir($workspace . '/Assets/Scenes', 0777, true);

    $command = new NewGame();
    $property = new ReflectionProperty(NewGame::class, 'targetDirectory');
    $property->setValue($command, $workspace);

    $method = new ReflectionMethod(NewGame::class, 'createDefaultSceneFile');
    $method->invoke($command, $workspace . '/Assets');

    $sceneContents = file_get_contents($workspace . '/Assets/Scenes/Level.scene.php');

    expect(is_file($workspace . '/Assets/Scenes/Level.scene.php'))->toBeTrue()
        ->and($sceneContents)->toContain('"environmentTileMapPath" => "Maps/example"')
        ->and($sceneContents)->toContain('"position" => ["x" => 0, "y" => 0]');
});

test('new game main template loads the default Level scene metadata file', function () {
    $workspace = sys_get_temp_dir() . '/sendama-new-game-main-' . uniqid();
    mkdir($workspace, 0777, true);

    $command = new NewGame();
    $property = new ReflectionProperty(NewGame::class, 'targetDirectory');
    $property->setValue($command, $workspace);

    $method = new ReflectionMethod(NewGame::class, 'createMainFile');
    $method->invoke($command, 'Test Game');

    $mainContents = file_get_contents($workspace . '/' . basename($workspace) . '.php');

    expect($mainContents)->toContain("loadScenes('Scenes/Level')")
        ->and($mainContents)->not->toContain('ExampleScene');
});

test('generate script creates files under Assets', function () {
    $workspace = createCliAssetsWorkspace();
    $exitCode = runGeneratorCommandInWorkspace(
        new GenerateScript(),
        $workspace,
        ['name' => 'player'],
    );

    expect($exitCode)->toBe(0)
        ->and(is_file($workspace . '/Assets/Scripts/Player.php'))->toBeTrue();
});

test('generate texture creates files under Assets', function () {
    $workspace = createCliAssetsWorkspace();
    $exitCode = runGeneratorCommandInWorkspace(
        new GenerateTexture(),
        $workspace,
        ['name' => 'player'],
    );

    expect($exitCode)->toBe(0)
        ->and(is_file($workspace . '/Assets/Textures/player.texture'))->toBeTrue();
});

test('generate scene creates files under Assets', function () {
    $workspace = createCliAssetsWorkspace();
    $exitCode = runGeneratorCommandInWorkspace(
        new GenerateScene(),
        $workspace,
        ['name' => 'level01'],
    );

    expect($exitCode)->toBe(0)
        ->and(is_file($workspace . '/Assets/Scenes/level01.scene.php'))->toBeTrue();
});

test('generate prefab creates metadata prefab files under Assets', function () {
    $workspace = createCliAssetsWorkspace();
    $exitCode = runGeneratorCommandInWorkspace(
        new GeneratePrefab(),
        $workspace,
        ['name' => 'enemy'],
    );

    $prefabPath = $workspace . '/Assets/Prefabs/enemy.prefab.php';
    $prefabContents = file_get_contents($prefabPath);

    expect($exitCode)->toBe(0)
        ->and(is_file($prefabPath))->toBeTrue()
        ->and($prefabContents)->toContain("'type' => GameObject::class")
        ->and($prefabContents)->toContain("'name' => 'Enemy'");
});

test('generate prefab can create ui element prefab metadata', function () {
    $workspace = createCliAssetsWorkspace();
    $exitCode = runGeneratorCommandInWorkspace(
        new GeneratePrefab(),
        $workspace,
        ['name' => 'score-label', '--kind' => 'label'],
    );

    $prefabPath = $workspace . '/Assets/Prefabs/score-label.prefab.php';
    $prefabContents = file_get_contents($prefabPath);

    expect($exitCode)->toBe(0)
        ->and(is_file($prefabPath))->toBeTrue()
        ->and($prefabContents)->toContain("'type' => Label::class")
        ->and($prefabContents)->toContain("'tag' => 'UI'")
        ->and($prefabContents)->toContain("'text' => 'Score Label'");
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

    expect($exitCode)->toBe(0)
        ->and(is_file($workspace . '/assets/Scripts/Player.php'))->toBeTrue()
        ->and(is_dir($workspace . '/Assets'))->toBeFalse();
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

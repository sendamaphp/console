<?php

namespace Sendama\Console\Editor;

use Sendama\Console\Debug\Debug;
use Sendama\Console\Editor\DTOs\SceneDTO;
use Sendama\Console\Util\Path;
use Throwable;

final class SceneLoader
{
    public function __construct(
        private readonly string $workingDirectory
    )
    {
    }

    public function load(EditorSceneSettings $sceneSettings): ?SceneDTO
    {
        $scenePath = $this->resolveActiveScenePath($sceneSettings);

        if (!$scenePath) {
            return null;
        }

        $sceneData = $this->loadSceneData($scenePath);

        return new SceneDTO(
            name: basename($scenePath, '.scene.php'),
            width: $sceneData['width'] ?? DEFAULT_TERMINAL_WIDTH,
            height: $sceneData['height'] ?? DEFAULT_TERMINAL_HEIGHT,
            environmentTileMapPath: $sceneData['environmentTileMapPath'] ?? 'Maps/example',
            isDirty: $sceneData['isDirty'] ?? false,
            hierarchy: $sceneData['hierarchy'] ?? [],
            sourcePath: $scenePath,
            rawData: $sceneData,
            sourceData: $sceneData,
        );
    }

    public function resolveAssetsDirectory(): ?string
    {
        $candidates = [
            Path::join($this->workingDirectory, 'Assets'),
            Path::join($this->workingDirectory, 'assets'),
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function resolveActiveScenePath(EditorSceneSettings $sceneSettings): ?string
    {
        $scenesDirectory = $this->resolveScenesDirectory();

        $configuredScenePath = $this->resolveConfiguredScenePath($sceneSettings, $scenesDirectory);

        if ($configuredScenePath) {
            return $configuredScenePath;
        }

        return $this->resolveFirstScenePath($scenesDirectory);
    }

    private function resolveScenesDirectory(): ?string
    {
        $assetsDirectory = $this->resolveAssetsDirectory();

        if (!$assetsDirectory) {
            return null;
        }

        $candidates = [
            Path::join($assetsDirectory, 'Scenes'),
            Path::join($assetsDirectory, 'scenes'),
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveConfiguredScenePath(
        EditorSceneSettings $sceneSettings,
        ?string $scenesDirectory
    ): ?string {
        $configuredScene = $sceneSettings->loaded[$sceneSettings->active] ?? $sceneSettings->loaded[0] ?? null;

        if (!is_string($configuredScene) || trim($configuredScene) === '') {
            return null;
        }

        foreach ($this->buildScenePathCandidates($configuredScene, $scenesDirectory) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveFirstScenePath(?string $scenesDirectory): ?string
    {
        if (!$scenesDirectory) {
            return null;
        }

        $sceneFiles = glob(Path::join($scenesDirectory, '*.scene.php')) ?: [];
        sort($sceneFiles);

        return $sceneFiles[0] ?? null;
    }

    private function buildScenePathCandidates(string $configuredScene, ?string $scenesDirectory): array
    {
        $configuredScene = trim($configuredScene);
        $sceneVariants = [$configuredScene];

        if (!str_ends_with($configuredScene, '.scene.php')) {
            $sceneVariants[] = $configuredScene . '.scene.php';
        }

        $candidates = [];

        foreach ($sceneVariants as $sceneVariant) {
            if ($this->isAbsolutePath($sceneVariant)) {
                $candidates[] = Path::normalize($sceneVariant);
            }

            $candidates[] = Path::join($this->workingDirectory, $sceneVariant);

            if ($scenesDirectory) {
                $trimmedVariant = preg_replace('#^(Assets|assets)/(Scenes|scenes)/#', '', $sceneVariant) ?? $sceneVariant;
                $trimmedVariant = preg_replace('#^(Scenes|scenes)/#', '', $trimmedVariant) ?? $trimmedVariant;
                $candidates[] = Path::join($scenesDirectory, $trimmedVariant);
                $candidates[] = Path::join($scenesDirectory, basename($sceneVariant));
            }
        }

        return array_values(array_unique($candidates));
    }

    private function loadSceneData(string $scenePath): array
    {
        $isolatedSceneData = $this->loadSceneDataInIsolatedProcess($scenePath);

        if (is_array($isolatedSceneData)) {
            return $isolatedSceneData;
        }

        try {
            $sceneData = require $scenePath;

            if (is_array($sceneData)) {
                return $sceneData;
            }

            Debug::warn("Scene metadata at {$scenePath} did not return an array.");
        } catch (Throwable $throwable) {
            Debug::warn("Failed to load scene metadata at {$scenePath}: {$throwable->getMessage()}");
        }

        return $this->extractSceneDataFromSource($scenePath);
    }

    private function loadSceneDataInIsolatedProcess(string $scenePath): ?array
    {
        $autoloadPath = Path::join($this->workingDirectory, 'vendor', 'autoload.php');
        $script = <<<'PHP'
$autoloadPath = $argv[1] ?? '';
$scenePath = $argv[2] ?? '';

if ($scenePath === '' || !is_file($scenePath)) {
    fwrite(STDERR, "Scene file not found.\n");
    exit(1);
}

ob_start();

try {
    if ($autoloadPath !== '' && is_file($autoloadPath)) {
        require $autoloadPath;
    }

    $sceneData = require $scenePath;
} finally {
    ob_end_clean();
}

if (!is_array($sceneData ?? null)) {
    fwrite(STDERR, "Scene metadata did not return an array.\n");
    exit(2);
}

$encodedSceneData = json_encode($sceneData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (!is_string($encodedSceneData)) {
    fwrite(STDERR, "Failed to encode scene metadata.\n");
    exit(3);
}

echo $encodedSceneData;
PHP;

        $command = [PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script, $autoloadPath, $scenePath];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $this->workingDirectory);

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            Debug::warn(
                "Failed to load scene metadata in isolated process at {$scenePath}: " . trim($stderr)
            );

            return null;
        }

        $sceneData = json_decode($stdout, true);

        if (!is_array($sceneData)) {
            Debug::warn("Failed to decode isolated scene metadata at {$scenePath}.");
            return null;
        }

        return $sceneData;
    }

    private function extractSceneDataFromSource(string $scenePath): array
    {
        $source = file_get_contents($scenePath);

        if ($source === false) {
            return [];
        }

        preg_match_all('/["\']name["\']\s*=>\s*["\']([^"\']+)["\']/', $source, $nameMatches);
        preg_match_all(
            '/["\']type["\']\s*=>\s*(?:"([^"]+)"|\'([^\']+)\'|([A-Za-z_\\\\][A-Za-z0-9_\\\\]*::class))/',
            $source,
            $typeMatches,
            PREG_SET_ORDER
        );

        $names = $nameMatches[1] ?? [];
        $types = array_map(function (array $match) {
            return $match[1] ?: $match[2] ?: $match[3] ?: null;
        }, $typeMatches);
        $hierarchy = [];

        foreach ($names as $index => $name) {
            $entry = ['name' => $name];

            if (isset($types[$index]) && is_string($types[$index]) && $types[$index] !== '') {
                $entry['type'] = $types[$index];
            }

            $hierarchy[] = $entry;
        }

        return [
            'hierarchy' => $hierarchy,
        ];
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}

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
            hierarchy: $sceneData['hierarchy'] ?? [],
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

    private function extractSceneDataFromSource(string $scenePath): array
    {
        $source = file_get_contents($scenePath);

        if ($source === false) {
            return [];
        }

        preg_match_all('/["\']name["\']\s*=>\s*["\']([^"\']+)["\']/', $source, $nameMatches);

        return [
            'hierarchy' => array_map(
                fn(string $name) => ['name' => $name],
                $nameMatches[1] ?? [],
            )
        ];
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}

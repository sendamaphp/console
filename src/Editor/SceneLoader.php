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

        $sceneDataBundle = $this->loadSceneDataBundle($scenePath);
        $sceneData = $sceneDataBundle['editor'] ?? [];
        $sourceSceneData = $sceneDataBundle['source'] ?? $sceneData;

        return new SceneDTO(
            name: basename($scenePath, '.scene.php'),
            width: $sceneData['width'] ?? DEFAULT_TERMINAL_WIDTH,
            height: $sceneData['height'] ?? DEFAULT_TERMINAL_HEIGHT,
            environmentTileMapPath: $sceneData['environmentTileMapPath'] ?? 'Maps/example',
            isDirty: $sceneData['isDirty'] ?? false,
            hierarchy: $sceneData['hierarchy'] ?? [],
            sourcePath: $scenePath,
            rawData: $sceneData,
            sourceData: $sourceSceneData,
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

    private function loadSceneDataBundle(string $scenePath): array
    {
        $isolatedSceneDataBundle = $this->loadSceneDataInIsolatedProcess($scenePath);

        if (
            is_array($isolatedSceneDataBundle)
            && is_array($isolatedSceneDataBundle['source'] ?? null)
            && is_array($isolatedSceneDataBundle['editor'] ?? null)
        ) {
            return $isolatedSceneDataBundle;
        }

        try {
            $sceneData = require $scenePath;

            if (is_array($sceneData)) {
                return [
                    'source' => $sceneData,
                    'editor' => $sceneData,
                ];
            }

            Debug::warn("Scene metadata at {$scenePath} did not return an array.");
        } catch (Throwable $throwable) {
            Debug::warn("Failed to load scene metadata at {$scenePath}: {$throwable->getMessage()}");
        }

        $fallbackSceneData = $this->extractSceneDataFromSource($scenePath);

        return [
            'source' => $fallbackSceneData,
            'editor' => $fallbackSceneData,
        ];
    }

    private function loadSceneDataInIsolatedProcess(string $scenePath): ?array
    {
        $autoloadPath = Path::join($this->workingDirectory, 'vendor', 'autoload.php');
        $script = <<<'PHP'
$autoloadPath = $argv[1] ?? '';
$scenePath = $argv[2] ?? '';

function normalize_editor_value(mixed $value): mixed
{
    if (is_array($value)) {
        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = normalize_editor_value($item);
        }

        return $normalized;
    }

    if ($value instanceof UnitEnum) {
        return $value instanceof BackedEnum ? $value->value : $value->name;
    }

    if (!is_object($value)) {
        return $value;
    }

    if (method_exists($value, 'getX') && method_exists($value, 'getY')) {
        return [
            'x' => normalize_editor_value($value->getX()),
            'y' => normalize_editor_value($value->getY()),
        ];
    }

    if (method_exists($value, 'getName')) {
        try {
            return $value->getName();
        } catch (Throwable) {
            // Ignore and continue.
        }
    }

    if (method_exists($value, '__serialize')) {
        try {
            $serializedValue = $value->__serialize();

            return is_array($serializedValue)
                ? normalize_editor_value($serializedValue)
                : normalize_editor_value((array) $serializedValue);
        } catch (Throwable) {
            // Ignore and continue.
        }
    }

    if ($value instanceof Stringable) {
        return (string) $value;
    }

    return get_class($value);
}

function build_vector(mixed $value, array $default = ['x' => 0, 'y' => 0]): ?object
{
    if (!class_exists('\Sendama\Engine\Core\Vector2')) {
        return null;
    }

    $vectorValue = is_array($value) ? $value : $default;

    return new \Sendama\Engine\Core\Vector2(
        (int) ($vectorValue['x'] ?? $default['x']),
        (int) ($vectorValue['y'] ?? $default['y']),
    );
}

function build_sprite(array $item): ?object
{
    $texture = is_array($item['sprite']['texture'] ?? null) ? $item['sprite']['texture'] : null;

    if (
        !is_array($texture)
        || !is_string($texture['path'] ?? null)
        || $texture['path'] === ''
        || !class_exists('\Sendama\Engine\Core\Texture')
        || !class_exists('\Sendama\Engine\Core\Sprite')
    ) {
        return null;
    }

    $textureObject = new \Sendama\Engine\Core\Texture($texture['path']);
    $rect = [
        'position' => normalize_editor_value(build_vector($texture['position'] ?? null)),
        'size' => normalize_editor_value(build_vector($texture['size'] ?? ['x' => 1, 'y' => 1], ['x' => 1, 'y' => 1])),
    ];

    return new \Sendama\Engine\Core\Sprite($textureObject, $rect);
}

function build_dummy_game_object(array $item): ?object
{
    if (!class_exists('\Sendama\Engine\Core\GameObject')) {
        return null;
    }

    $tag = is_string($item['tag'] ?? null) && $item['tag'] !== 'None'
        ? $item['tag']
        : null;

    return new \Sendama\Engine\Core\GameObject(
        is_string($item['name'] ?? null) ? $item['name'] : 'GameObject',
        $tag,
        build_vector($item['position'] ?? null) ?? new \Sendama\Engine\Core\Vector2(),
        build_vector($item['rotation'] ?? null) ?? new \Sendama\Engine\Core\Vector2(),
        build_vector($item['scale'] ?? ['x' => 1, 'y' => 1], ['x' => 1, 'y' => 1]) ?? new \Sendama\Engine\Core\Vector2(1, 1),
        null,
    );
}

function serialize_component_data(string $componentClass, array $item): ?array
{
    if (
        !class_exists($componentClass)
        || !class_exists('\Sendama\Engine\Core\Component')
        || !is_a($componentClass, '\Sendama\Engine\Core\Component', true)
    ) {
        return null;
    }

    try {
        $gameObject = build_dummy_game_object($item);

        if (!is_object($gameObject)) {
            return null;
        }

        $component = new $componentClass($gameObject);

        return normalize_editor_value(extract_component_serializable_data($component));
    } catch (Throwable) {
        return null;
    }
}

function extract_component_serializable_data(object $component): array
{
    $serializedData = [];
    $reflection = new ReflectionObject($component);

    foreach ($reflection->getProperties() as $property) {
        $isSerializable = $property->isPublic()
            || $property->getAttributes('Sendama\Engine\Core\Behaviours\Attributes\SerializeField') !== [];

        if (!$isSerializable) {
            continue;
        }

        if (method_exists($property, 'isVirtual') && $property->isVirtual()) {
            continue;
        }

        try {
            $serializedData[$property->getName()] = $property->getValue($component);
        } catch (Throwable) {
            continue;
        }
    }

    return $serializedData;
}

function enrich_component_entry(mixed $component, array $item): mixed
{
    if (!is_array($component)) {
        return $component;
    }

    $componentClass = $component['class'] ?? null;
    $defaultComponentData = is_string($componentClass) && $componentClass !== ''
        ? serialize_component_data($componentClass, $item)
        : null;

    if (array_key_exists('data', $component)) {
        $existingComponentData = is_array($component['data'])
            ? normalize_editor_value($component['data'])
            : normalize_editor_value((array) $component['data']);

        if (is_array($defaultComponentData)) {
            $component['data'] = merge_component_data($defaultComponentData, $existingComponentData);
        } else {
            $component['data'] = $existingComponentData;
        }

        return $component;
    }

    if (!is_string($componentClass) || $componentClass === '') {
        return $component;
    }

    if (is_array($defaultComponentData)) {
        $component['data'] = $defaultComponentData;
    }

    return $component;
}

function merge_component_data(array $defaultData, array $existingData): array
{
    if ($existingData === []) {
        return $defaultData;
    }

    $mergedData = $defaultData;

    foreach ($existingData as $key => $value) {
        if (
            array_key_exists($key, $defaultData)
            && is_array($defaultData[$key])
            && is_array($value)
            && !array_is_list($defaultData[$key])
            && !array_is_list($value)
        ) {
            $mergedData[$key] = merge_component_data($defaultData[$key], $value);
            continue;
        }

        $mergedData[$key] = $value;
    }

    return $mergedData;
}

function enrich_hierarchy_item(mixed $item): mixed
{
    if (!is_array($item)) {
        return $item;
    }

    if (is_array($item['components'] ?? null)) {
        $item['components'] = array_values(array_map(
            static fn (mixed $component): mixed => enrich_component_entry($component, $item),
            $item['components'],
        ));
    }

    if (is_array($item['children'] ?? null)) {
        $item['children'] = array_values(array_map(
            static fn (mixed $child): mixed => enrich_hierarchy_item($child),
            $item['children'],
        ));
    }

    return $item;
}

function enrich_scene_data(array $sceneData): array
{
    if (!is_array($sceneData['hierarchy'] ?? null)) {
        return $sceneData;
    }

    $sceneData['hierarchy'] = array_values(array_map(
        static fn (mixed $item): mixed => enrich_hierarchy_item($item),
        $sceneData['hierarchy'],
    ));

    return $sceneData;
}

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

$payload = [
    'source' => $sceneData,
    'editor' => enrich_scene_data($sceneData),
];

$encodedSceneData = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

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

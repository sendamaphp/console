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

        return $this->loadFromPath($scenePath);
    }

    public function loadFromPath(string $scenePath): ?SceneDTO
    {
        $normalizedScenePath = Path::normalize(trim($scenePath));

        if ($normalizedScenePath === '' || !is_file($normalizedScenePath)) {
            return null;
        }

        $sceneDataBundle = $this->loadSceneDataBundle($normalizedScenePath);
        $sceneData = $sceneDataBundle['editor'] ?? [];
        $sourceSceneData = $sceneDataBundle['source'] ?? $sceneData;
        $normalizedEnvironmentTileMapPath = $this->normalizeEnvironmentTileMapPath(
            $sceneData['environmentTileMapPath'] ?? $sourceSceneData['environmentTileMapPath'] ?? 'Maps/example',
        );
        $normalizedEnvironmentCollisionMapPath = $this->normalizeEnvironmentCollisionMapPath(
            $sceneData['environmentCollisionMapPath'] ?? $sourceSceneData['environmentCollisionMapPath'] ?? '',
        );
        $sceneData['environmentTileMapPath'] = $normalizedEnvironmentTileMapPath;
        $sourceSceneData['environmentTileMapPath'] = $normalizedEnvironmentTileMapPath;
        $sceneData['environmentCollisionMapPath'] = $normalizedEnvironmentCollisionMapPath;
        $sourceSceneData['environmentCollisionMapPath'] = $normalizedEnvironmentCollisionMapPath;

        return new SceneDTO(
            name: basename($normalizedScenePath, '.scene.php'),
            width: $sceneData['width'] ?? DEFAULT_TERMINAL_WIDTH,
            height: $sceneData['height'] ?? DEFAULT_TERMINAL_HEIGHT,
            environmentTileMapPath: $normalizedEnvironmentTileMapPath,
            environmentCollisionMapPath: $normalizedEnvironmentCollisionMapPath,
            isDirty: $sceneData['isDirty'] ?? false,
            hierarchy: $sceneData['hierarchy'] ?? [],
            sourcePath: $normalizedScenePath,
            rawData: $sceneData,
            sourceData: $sourceSceneData,
        );
    }

    public function resolveAssetsDirectory(): ?string
    {
        $assetsDirectory = Path::resolveAssetsDirectory($this->workingDirectory);

        return is_dir($assetsDirectory) ? $assetsDirectory : null;
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

    private function normalizeEnvironmentTileMapPath(mixed $value): string
    {
        if (!is_string($value)) {
            return 'Maps/example';
        }

        $normalizedValue = trim(str_replace('\\', '/', $value));

        if ($normalizedValue === '') {
            return 'Maps/example';
        }

        return preg_replace('/\.tmap$/i', '', $normalizedValue) ?? $normalizedValue;
    }

    private function normalizeEnvironmentCollisionMapPath(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $normalizedValue = trim(str_replace('\\', '/', $value));

        if ($normalizedValue === '') {
            return '';
        }

        return preg_replace('/\.tmap$/i', '', $normalizedValue) ?? $normalizedValue;
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

    $vectorValue = parse_vector_value($value) ?? $default;

    return new \Sendama\Engine\Core\Vector2(
        (int) ($vectorValue['x'] ?? $default['x']),
        (int) ($vectorValue['y'] ?? $default['y']),
    );
}

function parse_vector_value(mixed $value): ?array
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            return [
                'x' => (int) ($value[0] ?? 0),
                'y' => (int) ($value[1] ?? 0),
            ];
        }

        if (array_key_exists('x', $value) || array_key_exists('y', $value)) {
            return [
                'x' => (int) ($value['x'] ?? 0),
                'y' => (int) ($value['y'] ?? 0),
            ];
        }

        return null;
    }

    if (is_object($value)) {
        if (method_exists($value, 'getX') && method_exists($value, 'getY')) {
            return [
                'x' => (int) $value->getX(),
                'y' => (int) $value->getY(),
            ];
        }

        return parse_vector_value((array) $value);
    }

    if (!is_string($value)) {
        return null;
    }

    $normalizedValue = trim($value);

    if ($normalizedValue === '') {
        return null;
    }

    $decodedValue = json_decode($normalizedValue, true);

    if (is_array($decodedValue)) {
        return parse_vector_value($decodedValue);
    }

    if (
        preg_match('/^\[\s*(-?\d+)\s*,\s*(-?\d+)\s*\]$/', $normalizedValue, $matches) === 1
        || preg_match('/^\s*(-?\d+)\s*,\s*(-?\d+)\s*$/', $normalizedValue, $matches) === 1
    ) {
        return [
            'x' => (int) $matches[1],
            'y' => (int) $matches[2],
        ];
    }

    return null;
}

function is_vector_field_type(?string $fieldType): bool
{
    if (!is_string($fieldType) || trim($fieldType) === '') {
        return false;
    }

    $normalizedTypes = array_map(
        static fn (string $type): string => ltrim(trim($type), '\\'),
        explode('|', $fieldType),
    );

    return in_array('Sendama\Engine\Core\Vector2', $normalizedTypes, true);
}

function normalize_component_data_by_field_types(array $componentData, array $fieldTypes): array
{
    $normalizedData = $componentData;

    foreach ($normalizedData as $key => $value) {
        $fieldType = $fieldTypes[$key] ?? null;

        if (is_string($fieldType) && is_vector_field_type($fieldType)) {
            $normalizedData[$key] = parse_vector_value($value) ?? $value;
            continue;
        }

        if (is_array($fieldType) && is_array($value) && !array_is_list($value)) {
            $normalizedData[$key] = normalize_component_data_by_field_types($value, $fieldType);
        }
    }

    return $normalizedData;
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

function extract_component_editor_field_types(object $component): array
{
    $fieldTypes = [];
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

        $resolvedType = resolve_property_type($property);

        if ($resolvedType !== null) {
            $fieldTypes[$property->getName()] = $resolvedType;
        }
    }

    return $fieldTypes;
}

function resolve_property_type(ReflectionProperty $property): ?string
{
    $type = $property->getType();

    if ($type instanceof ReflectionNamedType) {
        $resolvedType = $type->getName();

        if ($type->allowsNull() && $resolvedType !== 'null') {
            return $resolvedType . '|null';
        }

        return $resolvedType;
    }

    if ($type instanceof ReflectionUnionType) {
        $resolvedTypes = [];

        foreach ($type->getTypes() as $namedType) {
            if ($namedType instanceof ReflectionNamedType) {
                $resolvedTypes[] = $namedType->getName();
            }
        }

        $resolvedTypes = array_values(array_unique(array_filter($resolvedTypes)));

        return $resolvedTypes !== [] ? implode('|', $resolvedTypes) : null;
    }

    return null;
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
    $defaultComponentFieldTypes = is_string($componentClass) && $componentClass !== ''
        && class_exists($componentClass)
        && class_exists('\Sendama\Engine\Core\Component')
        && is_a($componentClass, '\Sendama\Engine\Core\Component', true)
        && !empty($gameObject = build_dummy_game_object($item))
        ? (function () use ($componentClass, $gameObject): array {
            try {
                $componentInstance = new $componentClass($gameObject);

                return extract_component_editor_field_types($componentInstance);
            } catch (Throwable) {
                return [];
            }
        })()
        : [];

    if (array_key_exists('data', $component)) {
        $existingComponentData = is_array($component['data'])
            ? normalize_editor_value($component['data'])
            : normalize_editor_value((array) $component['data']);
        $existingComponentData = normalize_component_data_by_field_types(
            is_array($existingComponentData) ? $existingComponentData : [],
            $defaultComponentFieldTypes,
        );

        if (is_array($defaultComponentData)) {
            $component['data'] = merge_component_data($defaultComponentData, $existingComponentData);
        } else {
            $component['data'] = $existingComponentData;
        }

        if ($defaultComponentFieldTypes !== []) {
            $component['__editorFieldTypes'] = $defaultComponentFieldTypes;
        }

        return $component;
    }

    if (!is_string($componentClass) || $componentClass === '') {
        return $component;
    }

    if (is_array($defaultComponentData)) {
        $component['data'] = $defaultComponentData;
    }

    if ($defaultComponentFieldTypes !== []) {
        $component['__editorFieldTypes'] = $defaultComponentFieldTypes;
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

        preg_match('/["\']environmentTileMapPath["\']\s*=>\s*["\']([^"\']+)["\']/', $source, $tileMapPathMatch);
        preg_match('/["\']environmentCollisionMapPath["\']\s*=>\s*["\']([^"\']+)["\']/', $source, $collisionMapPathMatch);
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
            'environmentTileMapPath' => $this->normalizeEnvironmentTileMapPath($tileMapPathMatch[1] ?? 'Maps/example'),
            'environmentCollisionMapPath' => $this->normalizeEnvironmentCollisionMapPath($collisionMapPathMatch[1] ?? ''),
            'hierarchy' => $hierarchy,
        ];
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}

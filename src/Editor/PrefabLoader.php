<?php

namespace Sendama\Console\Editor;

use Sendama\Console\Debug\Debug;
use Sendama\Console\Util\Path;
use Throwable;

final class PrefabLoader
{
    public function __construct(
        private readonly string $workingDirectory
    )
    {
    }

    public function load(string $prefabPath): ?array
    {
        $prefabDataBundle = $this->loadPrefabDataBundle($prefabPath);

        return is_array($prefabDataBundle['editor'] ?? null)
            ? $prefabDataBundle['editor']
            : null;
    }

    private function loadPrefabDataBundle(string $prefabPath): ?array
    {
        $isolatedPrefabDataBundle = $this->loadPrefabDataInIsolatedProcess($prefabPath);

        if (
            is_array($isolatedPrefabDataBundle)
            && is_array($isolatedPrefabDataBundle['source'] ?? null)
            && is_array($isolatedPrefabDataBundle['editor'] ?? null)
        ) {
            return $isolatedPrefabDataBundle;
        }

        try {
            $prefabData = require $prefabPath;

            if (is_array($prefabData)) {
                return [
                    'source' => $prefabData,
                    'editor' => $prefabData,
                ];
            }

            Debug::warn("Prefab metadata at {$prefabPath} did not return an array.");
        } catch (Throwable $throwable) {
            Debug::warn("Failed to load prefab metadata at {$prefabPath}: {$throwable->getMessage()}");
        }

        return null;
    }

    private function loadPrefabDataInIsolatedProcess(string $prefabPath): ?array
    {
        $autoloadPath = Path::join($this->workingDirectory, 'vendor', 'autoload.php');
        $script = <<<'PHP'
$autoloadPath = $argv[1] ?? '';
$prefabPath = $argv[2] ?? '';

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

function build_dummy_game_object(array $item): ?object
{
    if (
        !class_exists('\Sendama\Engine\Core\GameObject')
        || !class_exists('\Sendama\Engine\Core\Vector2')
    ) {
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

    if (is_array($defaultComponentData)) {
        $component['data'] = $defaultComponentData;
    }

    if ($defaultComponentFieldTypes !== []) {
        $component['__editorFieldTypes'] = $defaultComponentFieldTypes;
    }

    return $component;
}

function enrich_prefab_item(mixed $item): mixed
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
            static fn (mixed $child): mixed => enrich_prefab_item($child),
            $item['children'],
        ));
    }

    return $item;
}

if ($prefabPath === '' || !is_file($prefabPath)) {
    fwrite(STDERR, "Prefab file not found.\n");
    exit(1);
}

ob_start();

try {
    if ($autoloadPath !== '' && is_file($autoloadPath)) {
        require $autoloadPath;
    }

    $prefabData = require $prefabPath;
} finally {
    ob_end_clean();
}

if (!is_array($prefabData ?? null)) {
    fwrite(STDERR, "Prefab metadata did not return an array.\n");
    exit(2);
}

$payload = [
    'source' => $prefabData,
    'editor' => enrich_prefab_item($prefabData),
];

$encodedPrefabData = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (!is_string($encodedPrefabData)) {
    fwrite(STDERR, "Failed to encode prefab metadata.\n");
    exit(3);
}

echo $encodedPrefabData;
PHP;

        $command = [PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script, $autoloadPath, $prefabPath];
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

        if ($exitCode !== 0 || $stdout === '') {
            if ($stderr !== '') {
                Debug::warn("Failed to evaluate prefab metadata at {$prefabPath}: {$stderr}");
            }

            return null;
        }

        $decodedPrefabData = json_decode($stdout, true);

        return is_array($decodedPrefabData) ? $decodedPrefabData : null;
    }
}

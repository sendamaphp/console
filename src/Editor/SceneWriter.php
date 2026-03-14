<?php

namespace Sendama\Console\Editor;

use Sendama\Console\Editor\DTOs\SceneDTO;

final class SceneWriter
{
    public function __construct(
        private readonly SceneSourceParser $sourceParser = new SceneSourceParser(),
    )
    {
    }

    public function save(SceneDTO $scene): bool
    {
        if (!is_string($scene->sourcePath) || $scene->sourcePath === '') {
            return false;
        }

        $serializedScene = $this->serialize($scene);

        return file_put_contents($scene->sourcePath, $serializedScene) !== false;
    }

    public function serialize(SceneDTO $scene): string
    {
        $sceneData = $this->snapshot($scene);
        $parsedSource = $this->parseSceneSource($scene);
        $originalSceneData = is_array($scene->sourceData) ? $scene->sourceData : [];

        if ($parsedSource !== null && $originalSceneData !== []) {
            $mergedSource = $this->renderMergedValue(
                $sceneData,
                $originalSceneData,
                $parsedSource['root']
            );

            return $parsedSource['prefix'] . $mergedSource . $parsedSource['suffix'];
        }

        return "<?php\n\nreturn " . $this->exportValue($sceneData) . ";\n";
    }

    public function snapshot(SceneDTO $scene): array
    {
        $sceneData = is_array($scene->rawData) ? $scene->rawData : [];
        $sceneData['width'] = $scene->width;
        $sceneData['height'] = $scene->height;
        $sceneData['environmentTileMapPath'] = $scene->environmentTileMapPath;
        $sceneData['environmentCollisionMapPath'] = $scene->environmentCollisionMapPath;
        $sceneData['hierarchy'] = $scene->hierarchy;

        unset($sceneData['isDirty']);

        return $this->stripEditorOnlyMetadata($sceneData);
    }

    private function parseSceneSource(SceneDTO $scene): ?array
    {
        if (!is_string($scene->sourcePath) || $scene->sourcePath === '' || !is_file($scene->sourcePath)) {
            return null;
        }

        return $this->sourceParser->parseFile($scene->sourcePath);
    }

    private function renderMergedValue(
        mixed $currentValue,
        mixed $originalValue,
        array $sourceNode,
        int $depth = 0,
        array $path = [],
    ): string {
        if ($currentValue === $originalValue && isset($sourceNode['source'])) {
            return $sourceNode['source'];
        }

        if (
            is_array($currentValue)
            && is_array($originalValue)
            && ($sourceNode['kind'] ?? null) === 'array'
            && $this->canRenderMergedArray($currentValue, $originalValue, $sourceNode)
        ) {
            return $this->renderMergedArray($currentValue, $originalValue, $sourceNode, $depth, $path);
        }

        return $this->exportValue($currentValue, $depth);
    }

    private function canRenderMergedArray(array $currentValue, array $originalValue, array $sourceNode): bool
    {
        if (($sourceNode['kind'] ?? null) !== 'array') {
            return false;
        }

        if (array_is_list($currentValue) !== array_is_list($originalValue)) {
            return false;
        }

        if (array_is_list($currentValue)) {
            return true;
        }

        return true;
    }

    private function renderMergedArray(
        array $currentValue,
        array $originalValue,
        array $sourceNode,
        int $depth,
        array $path,
    ): string {
        if ($currentValue === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth);
        $childIndent = str_repeat('    ', $depth + 1);
        $isList = array_is_list($currentValue);
        $lines = [];

        if ($isList) {
            $listItemMappings = $this->buildListItemMappings($currentValue, $originalValue);

            foreach (array_keys($currentValue) as $index) {
                $originalIndex = $listItemMappings[$index] ?? null;
                $itemNode = is_int($originalIndex)
                    ? ($sourceNode['items'][$originalIndex] ?? null)
                    : null;

                if (
                    is_array($itemNode)
                    && isset($itemNode['node'])
                    && is_int($originalIndex)
                    && array_key_exists($originalIndex, $originalValue)
                ) {
                    $lines[] = $childIndent
                        . $this->renderMergedValue(
                            $currentValue[$index],
                            $originalValue[$originalIndex],
                            $itemNode['node'],
                            $depth + 1,
                            [...$path, (string) $index]
                        )
                        . ',';

                    continue;
                }

                $lines[] = $childIndent
                    . $this->exportValue($currentValue[$index], $depth + 1)
                    . ',';
            }

            return "[\n" . implode("\n", $lines) . "\n" . $indent . "]";
        }

        $renderedKeys = [];

        foreach (($sourceNode['items'] ?? []) as $itemNode) {
            if (!is_array($itemNode) || !isset($itemNode['node'])) {
                return $this->exportArray($currentValue, $depth);
            }

            $resolvedKey = $this->resolveSourceArrayKey($itemNode['keySource'] ?? null);

            if (!is_int($resolvedKey) && !is_string($resolvedKey)) {
                return $this->exportArray($currentValue, $depth);
            }

            $valuePrefix = $this->renderArrayKeyPrefix($resolvedKey, $itemNode['keySource'] ?? null);

            if (array_key_exists($resolvedKey, $currentValue)) {
                $renderedKeys[$resolvedKey] = true;
                $value = $currentValue[$resolvedKey];
                $originalItemValue = array_key_exists($resolvedKey, $originalValue)
                    ? $originalValue[$resolvedKey]
                    : null;

                $renderedValue = array_key_exists($resolvedKey, $originalValue)
                    ? $this->renderMergedValue($value, $originalItemValue, $itemNode['node'], $depth + 1, [...$path, (string) $resolvedKey])
                    : $this->exportValue($value, $depth + 1);

                $lines[] = $childIndent . $valuePrefix . $renderedValue . ',';
                continue;
            }

            if ($this->shouldPreserveMissingAssociativeKey($path)) {
                $lines[] = $childIndent . $valuePrefix . $itemNode['node']['source'] . ',';
            }
        }

        foreach ($currentValue as $key => $value) {
            if (isset($renderedKeys[$key])) {
                continue;
            }

            $lines[] = $childIndent
                . $this->renderArrayKeyPrefix($key, null)
                . $this->exportValue($value, $depth + 1, is_string($key) ? $key : null)
                . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . $indent . "]";
    }

    private function shouldPreserveMissingAssociativeKey(array $path): bool
    {
        // Serialized component data is authoritative. If a key disappears there,
        // it should be removed from the saved metadata instead of being revived
        // from the original source snapshot.
        return !in_array('data', $path, true);
    }

    private function buildListItemMappings(array $currentValue, array $originalValue): array
    {
        $mappings = [];
        $availableOriginalIndexes = array_keys($originalValue);

        foreach ($currentValue as $currentIndex => $currentItem) {
            foreach ($availableOriginalIndexes as $availablePosition => $originalIndex) {
                if ($currentItem !== $originalValue[$originalIndex]) {
                    continue;
                }

                $mappings[$currentIndex] = $originalIndex;
                unset($availableOriginalIndexes[$availablePosition]);
                continue 2;
            }
        }

        foreach ($currentValue as $currentIndex => $currentItem) {
            if (array_key_exists($currentIndex, $mappings)) {
                continue;
            }

            $currentIdentity = $this->resolveListItemIdentity($currentItem);

            if ($currentIdentity === null) {
                continue;
            }

            $matchingOriginalIndexes = [];

            foreach ($availableOriginalIndexes as $originalIndex) {
                if ($this->resolveListItemIdentity($originalValue[$originalIndex]) === $currentIdentity) {
                    $matchingOriginalIndexes[] = $originalIndex;
                }
            }

            if (count($matchingOriginalIndexes) !== 1) {
                continue;
            }

            $matchedOriginalIndex = $matchingOriginalIndexes[0];
            $mappings[$currentIndex] = $matchedOriginalIndex;
            $availablePosition = array_search($matchedOriginalIndex, $availableOriginalIndexes, true);

            if ($availablePosition !== false) {
                unset($availableOriginalIndexes[$availablePosition]);
            }
        }

        return $mappings;
    }

    private function stripEditorOnlyMetadata(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $sanitizedValue = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && str_starts_with($key, '__editor')) {
                continue;
            }

            $sanitizedValue[$key] = $this->stripEditorOnlyMetadata($item);
        }

        return $sanitizedValue;
    }

    private function resolveListItemIdentity(mixed $value): ?string
    {
        if (is_scalar($value) || $value === null) {
            return get_debug_type($value) . ':' . var_export($value, true);
        }

        if (!is_array($value) || array_is_list($value)) {
            return null;
        }

        $identity = [];

        foreach (['type', 'class', 'name', 'path', 'relativePath', 'environmentTileMapPath', 'text', 'tag'] as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }

            $identityValue = $value[$key];

            if (!is_scalar($identityValue) && $identityValue !== null) {
                continue;
            }

            $identity[$key] = $identityValue;
        }

        if ($identity === []) {
            return null;
        }

        return json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }

    private function renderArrayKeyPrefix(int|string $key, ?string $keySource): string
    {
        if (is_string($keySource) && trim($keySource) !== '') {
            return rtrim($keySource) . ' => ';
        }

        return var_export($key, true) . ' => ';
    }

    private function resolveSourceArrayKey(?string $keySource): int|string|null
    {
        if (!is_string($keySource)) {
            return null;
        }

        $trimmedKey = trim($keySource);

        if ($trimmedKey === '') {
            return null;
        }

        if (preg_match('/^([\'"])(.*)\\1$/s', $trimmedKey, $matches) === 1) {
            return stripcslashes($matches[2]);
        }

        if (preg_match('/^-?\\d+$/', $trimmedKey) === 1) {
            return (int) $trimmedKey;
        }

        return $trimmedKey;
    }

    private function exportValue(mixed $value, int $depth = 0, ?string $contextKey = null): string
    {
        if (is_array($value)) {
            return $this->exportArray($value, $depth);
        }

        if (is_string($value) && in_array($contextKey, ['type', 'class'], true)) {
            return $this->exportClassReference($value);
        }

        return var_export($value, true);
    }

    private function exportArray(array $value, int $depth): string
    {
        if ($value === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth);
        $childIndent = str_repeat('    ', $depth + 1);
        $lines = [];

        foreach ($value as $key => $item) {
            $prefix = array_is_list($value)
                ? ''
                : var_export($key, true) . ' => ';

            $lines[] = $childIndent
                . $prefix
                . $this->exportValue($item, $depth + 1, is_string($key) ? $key : null)
                . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . $indent . "]";
    }

    private function exportClassReference(string $value): string
    {
        $normalizedValue = trim($value);

        if ($normalizedValue === '') {
            return var_export($value, true);
        }

        if (preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*::class$/', $normalizedValue) === 1) {
            return $normalizedValue;
        }

        if (preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $normalizedValue) === 1) {
            return '\\' . ltrim($normalizedValue, '\\') . '::class';
        }

        return var_export($value, true);
    }
}

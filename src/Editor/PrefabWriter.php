<?php

namespace Sendama\Console\Editor;

final class PrefabWriter
{
    public function save(string $prefabPath, array $prefabData): bool
    {
        $serializedPrefab = $this->serialize($prefabData);

        return file_put_contents($prefabPath, $serializedPrefab) !== false;
    }

    public function serialize(array $prefabData): string
    {
        return "<?php\n\nreturn " . $this->exportValue($this->stripEditorOnlyMetadata($prefabData)) . ";\n";
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
}

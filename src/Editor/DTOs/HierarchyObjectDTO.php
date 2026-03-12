<?php

namespace Sendama\Console\Editor\DTOs;

/**
 * HierarchyObjectDTO class.
 */
class HierarchyObjectDTO
{
    public function __construct(
        public string $type,
        public string $name,
        public ?string $tag = null,
        public array $position = ['x' => 0, 'y' => 0],
        public ?array $rotation = null,
        public ?array $scale = null,
        public ?array $size = null,
        public ?array $sprite = null,
        public ?string $text = null,
        public array $components = [],
        public array $children = [],
    )
    {
    }

    public function __serialize(): array
    {
        $data = [
            'type' => $this->type,
            'name' => $this->name,
            'position' => $this->position,
        ];

        if ($this->tag !== null && $this->tag !== '') {
            $data['tag'] = $this->tag;
        }

        if (is_array($this->rotation)) {
            $data['rotation'] = $this->rotation;
        }

        if (is_array($this->scale)) {
            $data['scale'] = $this->scale;
        }

        if (is_array($this->size)) {
            $data['size'] = $this->size;
        }

        if (is_array($this->sprite)) {
            $data['sprite'] = $this->sprite;
        }

        if ($this->text !== null) {
            $data['text'] = $this->text;
        }

        if ($this->components !== []) {
            $data['components'] = $this->components;
        }

        if ($this->children !== []) {
            $data['children'] = array_map(
                fn (mixed $child) => $child instanceof self ? $child->__serialize() : $child,
                $this->children,
            );
        }

        return $data;
    }

    public function __unserialize(array $data): void
    {
        $dto = self::fromArray($data);
        $this->type = $dto->type;
        $this->name = $dto->name;
        $this->tag = $dto->tag;
        $this->position = $dto->position;
        $this->rotation = $dto->rotation;
        $this->scale = $dto->scale;
        $this->size = $dto->size;
        $this->sprite = $dto->sprite;
        $this->text = $dto->text;
        $this->components = $dto->components;
        $this->children = $dto->children;
    }

    public static function fromArray(array $data): self
    {
        $isUiElement = self::isUiElementData($data);

        return new self(
            type: is_string($data['type'] ?? null) ? $data['type'] : '',
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            tag: is_string($data['tag'] ?? null) ? $data['tag'] : null,
            position: self::normalizeVector($data['position'] ?? null),
            rotation: $isUiElement ? null : self::normalizeVector($data['rotation'] ?? null),
            scale: $isUiElement ? null : self::normalizeVector($data['scale'] ?? ['x' => 1, 'y' => 1], ['x' => 1, 'y' => 1]),
            size: $isUiElement || array_key_exists('size', $data)
                ? self::normalizeVector($data['size'] ?? null)
                : null,
            sprite: is_array($data['sprite'] ?? null) ? $data['sprite'] : null,
            text: is_string($data['text'] ?? null) ? $data['text'] : null,
            components: is_array($data['components'] ?? null) ? array_values($data['components']) : [],
            children: self::normalizeChildren($data['children'] ?? []),
        );
    }

    private static function isUiElementData(array $data): bool
    {
        $type = is_string($data['type'] ?? null) ? ltrim($data['type'], '\\') : '';
        $type = preg_replace('/::class$/', '', $type) ?? $type;

        if ($type !== '' && str_starts_with($type, 'Sendama\\Engine\\UI\\')) {
            return true;
        }

        return array_key_exists('size', $data) || array_key_exists('text', $data);
    }

    private static function normalizeVector(mixed $value, array $default = ['x' => 0, 'y' => 0]): array
    {
        if (!is_array($value)) {
            return $default;
        }

        return [
            'x' => self::normalizeNumeric($value['x'] ?? $default['x']),
            'y' => self::normalizeNumeric($value['y'] ?? $default['y']),
        ];
    }

    private static function normalizeNumeric(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        return 0;
    }

    private static function normalizeChildren(mixed $children): array
    {
        if (!is_array($children)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $child) => $child instanceof self
                ? $child
                : (is_array($child) ? self::fromArray($child) : $child),
            $children,
        ));
    }
}

<?php

namespace Sendama\Console\Editor\DTOs;

use Serializable;

/**
 * HierarchyObjectDTO class.
 */
class HierarchyObjectDTO
{
    public function __construct(
        public string $name,
        public string $tag = '',
        public array $position = [1, 1],
        public array $rotation = [0, 0],
        public array $scale = [1, 1]
    )
    {
    }

    public function __serialize(): array
    {
        return [
            'name' => $this->name,
            'tag' => $this->tag,
            'position' => $this->position,
            'rotation' => $this->rotation,
            'scale' => $this->scale
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->name = $data['name'] ?? '';
        $this->tag = $data['tag'] ?? '';
        $this->position = $data['position'] ?? [1, 1];
        $this->rotation = $data['rotation'] ?? [0, 0];
        $this->scale = $data['scale'] ?? [1, 1];
    }

    public static function fromArray(array $data): self
    {

    }
}
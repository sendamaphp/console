<?php

namespace Sendama\Console\Editor\DTOs;

/**
 * SceneDTO class. Data Transfer Object for scene data in the editor.
 */
class SceneDTO
{
    public function __construct(
        public string $name,
        public int $width = DEFAULT_TERMINAL_WIDTH,
        public int $height = DEFAULT_TERMINAL_HEIGHT,
        public string $environmentTileMapPath = "Maps/example",
        public array $hierarchy = [],
    )
    {
    }

    public function __serialize(): array
    {
        return [
            "name" => $this->name,
            "width" => $this->width,
            "height" => $this->height,
            "environmentTileMapPath" => $this->environmentTileMapPath,
            "hierarchy" => $this->hierarchy,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->name = $data['name'] ?? '';
        $this->width = $data['width'] ?? DEFAULT_TERMINAL_WIDTH;
        $this->height = $data['height'] ?? DEFAULT_TERMINAL_HEIGHT;
        $this->environmentTileMapPath = $data['environmentTileMapPath'] ?? "Maps/example";
        $this->hierarchy = $data['hierarchy'] ?? [];
    }
}
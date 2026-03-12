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
        public bool $isDirty = false,
        public array $hierarchy = [],
        public ?string $sourcePath = null,
        public array $rawData = [],
        public array $sourceData = [],
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
            "isDirty" => $this->isDirty,
            "hierarchy" => $this->hierarchy,
            "sourcePath" => $this->sourcePath,
            "rawData" => $this->rawData,
            "sourceData" => $this->sourceData,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->name = $data['name'] ?? '';
        $this->width = $data['width'] ?? DEFAULT_TERMINAL_WIDTH;
        $this->height = $data['height'] ?? DEFAULT_TERMINAL_HEIGHT;
        $this->environmentTileMapPath = $data['environmentTileMapPath'] ?? "Maps/example";
        $this->isDirty = $data['isDirty'] ?? false;
        $this->hierarchy = $data['hierarchy'] ?? [];
        $this->sourcePath = $data['sourcePath'] ?? null;
        $this->rawData = $data['rawData'] ?? [];
        $this->sourceData = $data['sourceData'] ?? [];
    }
}

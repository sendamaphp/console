<?php

namespace Sendama\Console\Editor;

/**
 * Defines scene setup settings for the editor.
 *
 * @package Sendama\Console\Editor
 */
class EditorSceneSettings
{
    /**
     * @param int $active
     * @param string[] $loaded
     */
    public function __construct(
        public int $active = 0,
        public array $loaded = []
    )
    {
    }

    /**
     * @param array{active: int, loaded: string[]} $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            active: $data["active"] ?? 0,
            loaded: $data["loaded"] ?? []
        );
    }
}
<?php

namespace Sendama\Console\Editor;

readonly class GameSettings
{
    public const string DEFAULT_MAIN_FILE = 'main.php';

    public function __construct(
        public string $name,
        public string $description = '',
        public string $version = '1.0.0',
        public string $mainFile = self::DEFAULT_MAIN_FILE,
        public bool   $isDebugMode = false,
        public bool   $showDebugInfo = false
    )
    {
    }

    public static function loadFromDirectory(string $directory): self
    {
        $settingsFile = $directory . '/sendama.json';
        if (!file_exists($settingsFile)) {
            return new self(name: 'Untitled Game');
        }

        $data = json_decode(file_get_contents($settingsFile), true);
        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? 'Untitled Game',
            description: $data['description'] ?? '',
            version: $data['version'] ?? '1.0.0',
            mainFile: $data['main'] ?? self::DEFAULT_MAIN_FILE
        );
    }
}
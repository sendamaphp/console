<?php

namespace Sendama\Console\Editor;

use Sendama\Console\Debug\Debug;
use Sendama\Console\Exceptions\SendamaConsoleException;

readonly class GameSettings
{
    public const string DEFAULT_MAIN_FILE = 'main.php';
    public int $width;
    public int $height;

    /**
     * @param string $name
     * @param string $description
     * @param string $version
     * @param string $mainFile
     * @param bool $isDebugMode
     * @param bool $showDebugInfo
     */
    public function __construct(
        public string $name,
        public string $description = '',
        public string $version = '1.0.0',
        public string $mainFile = self::DEFAULT_MAIN_FILE,
        public bool   $isDebugMode = false,
        public bool   $showDebugInfo = false,
    )
    {
        $terminalSize = get_max_terminal_size();
        $this->width = $terminalSize['width'] ?? DEFAULT_TERMINAL_WIDTH;
        $this->height = $terminalSize['height'] ?? DEFAULT_TERMINAL_HEIGHT;
    }

    /**
     * @param string $directory
     * @return self
     * @throws SendamaConsoleException
     */
    public static function loadFromDirectory(string $directory): self
    {
        $settingsFile = $directory . '/sendama.json';

        if (!file_exists($settingsFile)) {
            Debug::warn("$settingsFile not found.");
            return new self(name: 'Untitled Game');
        }

        $settingsJsonFileContents = file_get_contents($settingsFile);

        if (false === $settingsJsonFileContents) {
            throw new SendamaConsoleException("Failed to load contents of $settingsFile");
        }

        $data = json_decode($settingsJsonFileContents, true);

        return self::fromArray($data);
    }

    /**
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? 'Untitled Game',
            description: $data['description'] ?? '',
            version: $data['version'] ?? '1.0.0',
            mainFile: $data['main'] ?? self::DEFAULT_MAIN_FILE,
            isDebugMode: $data['debug'] ?? false,
            showDebugInfo: $data['showDebugInfo'] ?? false,
        );
    }
}
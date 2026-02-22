<?php

namespace Sendama\Console\Editor;

use Sendama\Console\Exceptions\SendamaConsoleException;
use Sendama\Console\Util\Path;

class EditorSettings
{
    protected(set) int $width {
        get {
            return $this->width;
        }
    }

    protected(set) int $height {
        get {
            return $this->height;
        }
    }

    /**
     * @param EditorSceneSettings $scenes
     */
    public function __construct(
        public readonly EditorSceneSettings $scenes
    )
    {
        $terminalSize = get_max_terminal_size();
        $this->width = $terminalSize['width'] ?? DEFAULT_TERMINAL_WIDTH;
        $this->height = $terminalSize['height'] ?? DEFAULT_TERMINAL_HEIGHT;
    }

    /**
     * @param string $workingDirectory
     * @return self
     * @throws SendamaConsoleException
     */
    public static function loadFromDirectory(string $workingDirectory): self
    {
        $filename = Path::join($workingDirectory, 'sendama.json');

        if (!file_exists($filename)) {
            throw new SendamaConsoleException("$filename does not exist!");
        }

        $settingsJsonFileContents = file_get_contents($filename);

        if (false === $settingsJsonFileContents) {
            throw new SendamaConsoleException("Failed to load contents of $filename");
        }

        $data = json_decode($settingsJsonFileContents, true);

        return self::fromArray($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(scenes: EditorSceneSettings::fromArray($data["scenes"] ?? []));
    }
}
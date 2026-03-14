<?php

namespace Sendama\Console\Editor;

use Sendama\Console\Exceptions\SendamaConsoleException;
use Sendama\Console\Util\Path;

class EditorSettings
{
    public const float DEFAULT_CONSOLE_REFRESH_INTERVAL_SECONDS = 5.0;
    public const float DEFAULT_NOTIFICATION_DURATION_SECONDS = 4.0;

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
        public readonly EditorSceneSettings $scenes,
        public readonly float $consoleRefreshIntervalSeconds = self::DEFAULT_CONSOLE_REFRESH_INTERVAL_SECONDS,
        public readonly float $notificationDurationSeconds = self::DEFAULT_NOTIFICATION_DURATION_SECONDS,
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
            return self::fromArray([]);
        }

        $settingsJsonFileContents = file_get_contents($filename);

        if (false === $settingsJsonFileContents) {
            throw new SendamaConsoleException("Failed to load contents of $filename");
        }

        $data = json_decode($settingsJsonFileContents, true);

        if (!is_array($data)) {
            return self::fromArray([]);
        }

        return self::fromArray($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $editorData = is_array($data['editor'] ?? null) ? $data['editor'] : $data;
        $scenesData = $editorData['scenes'] ?? $data['scenes'] ?? [];
        $consoleData = is_array($editorData['console'] ?? null) ? $editorData['console'] : [];
        $notificationData = is_array($editorData['notifications'] ?? null) ? $editorData['notifications'] : [];
        $refreshInterval = $consoleData['refreshInterval']
            ?? $editorData['consoleRefreshInterval']
            ?? self::DEFAULT_CONSOLE_REFRESH_INTERVAL_SECONDS;
        $notificationDuration = $notificationData['duration']
            ?? $editorData['notificationDuration']
            ?? self::DEFAULT_NOTIFICATION_DURATION_SECONDS;

        return new self(
            scenes: EditorSceneSettings::fromArray(is_array($scenesData) ? $scenesData : []),
            consoleRefreshIntervalSeconds: self::normalizePositiveFloat($refreshInterval, self::DEFAULT_CONSOLE_REFRESH_INTERVAL_SECONDS),
            notificationDurationSeconds: self::normalizePositiveFloat($notificationDuration, self::DEFAULT_NOTIFICATION_DURATION_SECONDS),
        );
    }

    private static function normalizePositiveFloat(mixed $value, float $fallback): float
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        $normalizedValue = (float) $value;

        if ($normalizedValue <= 0) {
            return $fallback;
        }

        return $normalizedValue;
    }
}

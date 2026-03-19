<?php

namespace Sendama\Console\Editor;

use Sendama\Console\Exceptions\SendamaConsoleException;
use Sendama\Console\Util\Path;

class EditorSettings
{
    public const float DEFAULT_CONSOLE_REFRESH_INTERVAL_SECONDS = 5.0;
    public const float DEFAULT_NOTIFICATION_DURATION_SECONDS = 4.0;
    public const string DEFAULT_EXTERNAL_EDITOR_MODE = 'auto';

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
        public readonly ?string $externalEditorCommand = null,
        public readonly string $externalEditorMode = self::DEFAULT_EXTERNAL_EDITOR_MODE,
        public readonly ?bool $externalEditorBlocking = null,
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
        $externalEditorData = self::normalizeExternalEditorData(
            $editorData['externalEditor']
                ?? $editorData['defaultEditor']
                ?? $data['externalEditor']
                ?? $data['defaultEditor']
                ?? null
        );
        $refreshInterval = $consoleData['refreshInterval']
            ?? $editorData['consoleRefreshInterval']
            ?? self::DEFAULT_CONSOLE_REFRESH_INTERVAL_SECONDS;
        $notificationDuration = $notificationData['duration']
            ?? $editorData['notificationDuration']
            ?? self::DEFAULT_NOTIFICATION_DURATION_SECONDS;
        $externalEditorCommand = $externalEditorData['command']
            ?? self::normalizeOptionalString(
                $editorData['externalEditorCommand']
                    ?? $editorData['defaultEditorCommand']
                    ?? $data['externalEditorCommand']
                    ?? $data['defaultEditorCommand']
                    ?? null
            );
        $externalEditorMode = self::normalizeExternalEditorMode(
            $externalEditorData['mode']
                ?? $editorData['externalEditorMode']
                ?? $editorData['defaultEditorMode']
                ?? $data['externalEditorMode']
                ?? $data['defaultEditorMode']
                ?? self::DEFAULT_EXTERNAL_EDITOR_MODE
        );
        $externalEditorBlocking = self::normalizeNullableBool(
            $externalEditorData['blocking']
                ?? $editorData['externalEditorBlocking']
                ?? $editorData['defaultEditorBlocking']
                ?? $data['externalEditorBlocking']
                ?? $data['defaultEditorBlocking']
                ?? null
        );

        return new self(
            scenes: EditorSceneSettings::fromArray(is_array($scenesData) ? $scenesData : []),
            consoleRefreshIntervalSeconds: self::normalizePositiveFloat($refreshInterval, self::DEFAULT_CONSOLE_REFRESH_INTERVAL_SECONDS),
            notificationDurationSeconds: self::normalizePositiveFloat($notificationDuration, self::DEFAULT_NOTIFICATION_DURATION_SECONDS),
            externalEditorCommand: $externalEditorCommand,
            externalEditorMode: $externalEditorMode,
            externalEditorBlocking: $externalEditorBlocking,
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

    /**
     * @return array{command:?string, mode:?string, blocking:?bool}
     */
    private static function normalizeExternalEditorData(mixed $value): array
    {
        if (is_string($value)) {
            return [
                'command' => self::normalizeOptionalString($value),
                'mode' => null,
                'blocking' => null,
            ];
        }

        if (!is_array($value)) {
            return [
                'command' => null,
                'mode' => null,
                'blocking' => null,
            ];
        }

        return [
            'command' => self::normalizeOptionalString($value['command'] ?? $value['cmd'] ?? null),
            'mode' => is_string($value['mode'] ?? null) ? $value['mode'] : null,
            'blocking' => self::normalizeNullableBool($value['blocking'] ?? $value['wait'] ?? null),
        ];
    }

    private static function normalizeOptionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalizedValue = trim($value);

        return $normalizedValue !== '' ? $normalizedValue : null;
    }

    private static function normalizeExternalEditorMode(mixed $value): string
    {
        $normalizedValue = strtolower(trim((string) $value));

        return in_array($normalizedValue, ['auto', 'terminal', 'gui'], true)
            ? $normalizedValue
            : self::DEFAULT_EXTERNAL_EDITOR_MODE;
    }

    private static function normalizeNullableBool(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_string($value) => match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => null,
            },
            is_int($value) => match ($value) {
                1 => true,
                0 => false,
                default => null,
            },
            default => null,
        };
    }
}

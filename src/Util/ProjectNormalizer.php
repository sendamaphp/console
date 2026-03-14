<?php

namespace Sendama\Console\Util;

final class ProjectNormalizer
{
    private const array REQUIRED_ASSET_DIRECTORIES = [
        'Scenes',
        'Scripts',
        'Maps',
        'Prefabs',
        'Textures',
    ];

    private const array REQUIRED_LOG_FILES = [
        'debug.log',
        'error.log',
    ];

    public function __construct(
        private readonly string $projectRoot,
    )
    {
    }

    /**
     * @return string[]
     */
    public function inspect(): array
    {
        if (!is_dir($this->projectRoot)) {
            return ['Project directory is missing.'];
        }

        $issues = [];
        $configDirectory = Path::join($this->projectRoot, 'config');
        $logsDirectory = Path::join($this->projectRoot, 'logs');
        $assetsDirectory = Path::resolveAssetsDirectory($this->projectRoot);
        $assetsLabel = basename($assetsDirectory) ?: 'Assets';

        if (!is_file(Path::join($this->projectRoot, 'sendama.json'))) {
            $issues[] = 'Missing sendama.json.';
        }

        if (!is_file(Path::join($this->projectRoot, 'preferences..json'))) {
            $issues[] = 'Missing preferences..json.';
        }

        if (!is_dir($configDirectory)) {
            $issues[] = 'Missing config directory.';
        }

        if (!is_file(Path::join($configDirectory, 'input.php'))) {
            $issues[] = 'Missing config/input.php.';
        }

        if (!is_dir($logsDirectory)) {
            $issues[] = 'Missing logs directory.';
        }

        foreach (self::REQUIRED_LOG_FILES as $logFilename) {
            if (!is_file(Path::join($logsDirectory, $logFilename))) {
                $issues[] = sprintf('Missing logs/%s.', $logFilename);
            }
        }

        if (!is_dir($assetsDirectory)) {
            $issues[] = sprintf('Missing %s directory.', $assetsLabel);
        }

        foreach (self::REQUIRED_ASSET_DIRECTORIES as $directory) {
            if (!is_dir(Path::join($assetsDirectory, $directory))) {
                $issues[] = sprintf('Missing %s/%s directory.', $assetsLabel, $directory);
            }
        }

        return $issues;
    }

    /**
     * @return string[]
     */
    public function normalize(): array
    {
        if (!is_dir($this->projectRoot)) {
            return [];
        }

        $changes = [];
        $configDirectory = Path::join($this->projectRoot, 'config');
        $logsDirectory = Path::join($this->projectRoot, 'logs');
        $assetsDirectory = Path::resolveAssetsDirectory($this->projectRoot);
        $assetsLabel = basename($assetsDirectory) ?: 'Assets';
        $projectMetadata = $this->resolveProjectMetadata();

        $this->ensureDirectory($configDirectory, 'Created config directory.', $changes);
        $this->ensureDirectory($logsDirectory, 'Created logs directory.', $changes);
        $this->ensureDirectory($assetsDirectory, sprintf('Created %s directory.', $assetsLabel), $changes);

        foreach (self::REQUIRED_ASSET_DIRECTORIES as $directory) {
            $this->ensureDirectory(
                Path::join($assetsDirectory, $directory),
                sprintf('Created %s/%s directory.', $assetsLabel, $directory),
                $changes,
            );
        }

        $loadedScenes = $this->resolveLoadedScenes($assetsDirectory);

        $this->ensureFile(
            Path::join($this->projectRoot, 'sendama.json'),
            self::buildSendamaConfiguration(
                projectName: $projectMetadata['name'],
                description: $projectMetadata['description'],
                version: $projectMetadata['version'],
                mainFile: $projectMetadata['main'],
                loadedScenes: $loadedScenes,
            ),
            'Created sendama.json.',
            $changes,
        );

        $this->ensureFile(
            Path::join($this->projectRoot, 'preferences..json'),
            self::buildPreferencesJson(),
            'Created preferences..json.',
            $changes,
        );

        $this->ensureFile(
            Path::join($configDirectory, 'input.php'),
            self::buildInputConfiguration(),
            'Created config/input.php.',
            $changes,
        );

        foreach (self::REQUIRED_LOG_FILES as $logFilename) {
            $this->ensureFile(
                Path::join($logsDirectory, $logFilename),
                '',
                sprintf('Created logs/%s.', $logFilename),
                $changes,
            );
        }

        return $changes;
    }

    public static function buildSendamaConfiguration(
        string $projectName,
        string $description = 'A 2D ASCII terminal game.',
        string $version = '0.0.1',
        string $mainFile = 'main.php',
        array $loadedScenes = [],
        float $consoleRefreshInterval = 5.0,
        float $notificationDuration = 4.0,
    ): string {
        return json_encode([
            'name' => $projectName,
            'description' => $description,
            'version' => $version,
            'main' => $mainFile,
            'editor' => [
                'scenes' => [
                    'active' => 0,
                    'loaded' => array_values($loadedScenes),
                ],
                'console' => [
                    'refreshInterval' => $consoleRefreshInterval,
                ],
                'notifications' => [
                    'duration' => $notificationDuration,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    public static function buildPreferencesJson(): string {
        return json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    public static function buildInputConfiguration(): string
    {
        $templatePath = Path::join(dirname(__DIR__, 2), 'templates', 'config', 'input.php');
        $templateContents = file_get_contents($templatePath);

        return $templateContents === false ? "<?php\n\nreturn [];\n" : $templateContents;
    }

    /**
     * @return array{name: string, description: string, version: string, main: string}
     */
    private function resolveProjectMetadata(): array
    {
        $defaultMetadata = [
            'name' => $this->guessProjectName(),
            'description' => 'A 2D ASCII terminal game.',
            'version' => '0.0.1',
            'main' => $this->guessMainFile(),
        ];

        $sendamaPath = Path::join($this->projectRoot, 'sendama.json');

        if (is_file($sendamaPath)) {
            $sendamaContents = file_get_contents($sendamaPath);
            $sendamaData = $sendamaContents !== false ? json_decode($sendamaContents, true) : null;

            if (is_array($sendamaData)) {
                return [
                    'name' => is_string($sendamaData['name'] ?? null) && trim($sendamaData['name']) !== ''
                        ? trim($sendamaData['name'])
                        : $defaultMetadata['name'],
                    'description' => is_string($sendamaData['description'] ?? null)
                        ? $sendamaData['description']
                        : $defaultMetadata['description'],
                    'version' => is_string($sendamaData['version'] ?? null)
                        ? $sendamaData['version']
                        : $defaultMetadata['version'],
                    'main' => is_string($sendamaData['main'] ?? null) && trim($sendamaData['main']) !== ''
                        ? trim($sendamaData['main'])
                        : $defaultMetadata['main'],
                ];
            }
        }

        $preferencesPath = Path::join($this->projectRoot, 'preferences.json');

        if (is_file($preferencesPath)) {
            $configurationContents = file_get_contents($preferencesPath);
            $configurationData = $configurationContents !== false ? json_decode($configurationContents, true) : null;
            $projectData = is_array($configurationData['project'] ?? null) ? $configurationData['project'] : null;

            if (is_array($projectData)) {
                return [
                    'name' => is_string($projectData['name'] ?? null) && trim($projectData['name']) !== ''
                        ? trim($projectData['name'])
                        : $defaultMetadata['name'],
                    'description' => is_string($projectData['description'] ?? null)
                        ? $projectData['description']
                        : $defaultMetadata['description'],
                    'version' => is_string($projectData['version'] ?? null)
                        ? $projectData['version']
                        : $defaultMetadata['version'],
                    'main' => is_string($projectData['main'] ?? null) && trim($projectData['main']) !== ''
                        ? trim($projectData['main'])
                        : $defaultMetadata['main'],
                ];
            }
        }

        return $defaultMetadata;
    }

    /**
     * @param string $assetsDirectory
     * @return string[]
     */
    private function resolveLoadedScenes(string $assetsDirectory): array
    {
        $sceneDirectory = Path::join($assetsDirectory, 'Scenes');

        if (!is_dir($sceneDirectory)) {
            return [];
        }

        $sceneFiles = glob(Path::join($sceneDirectory, '*.scene.php')) ?: [];
        sort($sceneFiles);

        return array_map(
            static fn(string $sceneFile) => 'Scenes/' . basename($sceneFile),
            $sceneFiles,
        );
    }

    private function guessProjectName(): string
    {
        $directoryName = basename($this->projectRoot);
        $normalizedName = trim((string) preg_replace('/[-_]+/', ' ', $directoryName));

        if ($normalizedName === '') {
            return 'Untitled Game';
        }

        return ucwords($normalizedName);
    }

    private function guessMainFile(): string
    {
        $directoryName = basename($this->projectRoot);
        $normalizedName = strtolower(function_exists('filter_string')
            ? filter_string($directoryName)
            : (string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $directoryName));
        $normalizedName = trim($normalizedName, '-_');

        if ($normalizedName === '') {
            return 'main.php';
        }

        return $normalizedName . '.php';
    }

    /**
     * @param string[] $changes
     */
    private function ensureDirectory(string $directory, string $message, array &$changes): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            return;
        }

        $changes[] = $message;
    }

    /**
     * @param string[] $changes
     */
    private function ensureFile(string $filename, string $contents, string $message, array &$changes): void
    {
        if (is_file($filename)) {
            return;
        }

        $directory = dirname($filename);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            return;
        }

        if (file_put_contents($filename, $contents) === false) {
            return;
        }

        $changes[] = $message;
    }
}

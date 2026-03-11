<?php

namespace Sendama\Console\Editor\Widgets;

use Sendama\Console\Debug\Debug;
use Sendama\Console\Util\Path;

/**
 * AssetsPanel class.
 *
 * This panel is responsible for displaying the list of assets available in the game, such as scripts, textures, and
 * other resources. It allows users to browse and manage their assets within the editor.
 *
 * @package Sendama\Console\Editor\Widgets
 */
class AssetsPanel extends Widget
{
    protected array $assetEntries = [];
    protected ?int $selectedIndex = null;

    /**
     * AssetsPanel constructor.
     *
     * @param array $position The position of the panel in the editor (default: ['x' => 1, 'y' => 15]).
     * @param int $width The width of the panel (default: 35).
     * @param int $height The height of the panel (default: 14).
     * @param string|null $assetsDirectoryPath The path to the assets directory (optional).
     */
    public function __construct(
        array $position = ['x' => 1, 'y' => 15],
        int $width = 35,
        int $height = 14,
        protected ?string $assetsDirectoryPath = null
    )
    {
        parent::__construct('Assets', '', $position, $width, $height);
        $this->loadAssetEntries();
        $this->refreshContent();
    }

    public function handleMouseClick(int $x, int $y): void
    {
        if (!$this->containsPoint($x, $y)) {
            return;
        }

        $index = $y - $this->getContentAreaTop();

        if (!isset($this->assetEntries[$index])) {
            return;
        }

        $this->selectedIndex = $index;
        $this->refreshContent();
    }

    /**
     * @inheritDoc
     */
    public function update(): void
    {
        // TODO: Implement update() method.
    }

    private function loadAssetEntries(): void
    {
        if (!$this->assetsDirectoryPath) {
            $this->assetsDirectoryPath = Path::getWorkingDirectoryAssetsPath();
        }

        if (! file_exists($this->assetsDirectoryPath) ) {
            Debug::warn("Assets directory not found at {$this->assetsDirectoryPath}. Please create the directory and add your assets.");
            $this->assetEntries = [];
        } else {
            $rootAssets = scandir($this->assetsDirectoryPath);

            if (false === $rootAssets) {
                Debug::error("Failed to read contents of assets directory at {$this->assetsDirectoryPath}.");
                $this->assetEntries = [];
            } else {
                $rootAssets = array_slice($rootAssets, 2);
                $entries = [];
                foreach ($rootAssets as $asset) {
                    $entries[] = [
                        'name' => $asset,
                        'isDirectory' => is_dir(Path::join($this->assetsDirectoryPath, $asset)),
                    ];
                }
                $this->assetEntries = $entries;
            }
        }
    }

    private function refreshContent(): void
    {
        $this->content = array_map(function (array $assetEntry, int $index) {
            $name = $assetEntry['name'] ?? 'Unnamed Asset';
            $icon = $index === $this->selectedIndex
                ? '>'
                : ($assetEntry['isDirectory'] ?? false ? '►' : ' ');

            return "$icon $name";
        }, $this->assetEntries, array_keys($this->assetEntries));
    }
}

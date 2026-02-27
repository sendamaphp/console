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

        if (!$this->assetsDirectoryPath) {
            $this->assetsDirectoryPath = Path::getWorkingDirectoryAssetsPath();
        }

        if (! file_exists($this->assetsDirectoryPath) ) {
            Debug::warn("Assets directory not found at {$this->assetsDirectoryPath}. Please create the directory and add your assets.");
        } else {
            $rootAssets = scandir($this->assetsDirectoryPath);

            if (false === $rootAssets) {
                Debug::error("Failed to read contents of assets directory at {$this->assetsDirectoryPath}.");
            } else {
                $rootAssets = array_slice($rootAssets, 2);
                $content = [];
                foreach ($rootAssets as $asset) {
                    $contentLine = "  $asset";
                    if (is_dir(Path::join($this->assetsDirectoryPath, $asset))) {
                        $contentLine = "► $asset";
                    }

                    $content[] = $contentLine;
                }
                $this->content = $content;
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function update(): void
    {
        // TODO: Implement update() method.
    }
}
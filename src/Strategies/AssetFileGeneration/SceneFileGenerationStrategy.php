<?php

namespace Sendama\Console\Strategies\AssetFileGeneration;

use Sendama\Console\Util\Path;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SceneFileGenerationStrategy extends AbstractAssetFileGenerationStrategy
{
    public static function buildMetaSceneContents(
        string $environmentTileMapPath = 'Maps/example',
        string $managerName = 'Level Manager',
        string $objectName = 'GameObject',
    ): string {
        return <<<PHP
<?php

use Sendama\Engine\Core\Behaviours\SimpleQuitListener;
use Sendama\Engine\Core\GameObject;

return [
    "width" => DEFAULT_SCREEN_WIDTH,
    "height" => DEFAULT_SCREEN_HEIGHT,
    "environmentTileMapPath" => "{$environmentTileMapPath}",
    "hierarchy" => [
        [
            "type" => GameObject::class,
            "name" => "{$managerName}",
            "tag" => "None",
            "position" => ["x" => 0, "y" => 0],
            "rotation" => ["x" => 0, "y" => 0],
            "scale" => ["x" => 1, "y" => 1],
            "components" => [
                [ "class" => SimpleQuitListener::class ],
            ]
        ],
        [
            "type" => GameObject::class,
            "name" => "{$objectName}",
            "tag" => "None",
            "position" => ["x" => 1, "y" => 1],
            "rotation" => ["x" => 0, "y" => 0],
            "scale" => ["x" => 1, "y" => 1],
            "components" => []
        ]
    ],
];

PHP;
    }

    public function __construct(
        InputInterface  $input,
        OutputInterface $output,
        string          $filename,
        string          $directory,
        ?string         $fileExtension = null,
        protected bool  $asMetaFile = true
    )
    {
        if ($this->asMetaFile) {
            $fileExtension = '.scene.php';
        }

        parent::__construct($input, $output, $filename, $directory, $fileExtension);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        if ($this->asMetaFile) {
            $filename = Path::join(dirname($this->classPath), to_kebab_case($this->className));
            $this->relativeFilename = Path::join($this->assetsDirectoryName, $filename . ($this->fileExtension ?? '.php'));
            $this->content = self::buildMetaSceneContents();
        } else {
            $this->content = <<<PHP
<?php

namespace {$this->composerConfig->getNamespace()}Scenes;

use Sendama\Engine\Core\Behaviours\SimpleQuitListener;
use Sendama\Engine\Core\Scenes\AbstractScene;
use Sendama\Engine\Core\GameObject;
use Sendama\Engine\Core\Vector2;

class $this->className extends AbstractScene
{
  public function awake(): void
  {
    // awake is called when the scene is loaded
    \$this->environmentTileMapPath = 'Maps/example';

    // create your game objects here
    \$levelManager = new GameObject('Level Manager');
    \$myGameObject = new GameObject('GameObject', position: Vector2::one());

    // TODO: Add components to the GameObject
    \$levelManager->addComponent(SimpleQuitListener::class);

    // add the game objects to the scene
    \$this->add(\$levelManager);
    \$this->add(\$myGameObject);
  }
}

PHP;
        }
    }
}

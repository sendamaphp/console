<?php

namespace Sendama\Console\Strategies\AssetFileGeneration;

use Sendama\Console\Util\Path;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SceneFileGenerationStrategy extends AbstractAssetFileGenerationStrategy
{
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
            $this->relativeFilename = Path::join('assets', $filename . ($this->fileExtension ?? '.php'));
            $this->content = <<<PHP
<?php

use Sendama\Engine\Core\Behaviours\SimpleQuitListener;
use Sendama\Engine\Core\GameObject;

return [
    "width" => DEFAULT_SCREEN_WIDTH,
    "height" => DEFAULT_SCREEN_HEIGHT,
    "environmentTileMapPath" => "Maps/example",
    "hierarchy" => [
        [
            "type" => GameObject::class,
            "name" => "Level Manager",
            "position" => [0, 0],
            "rotation" => [0, 0],
            "scale" => [1, 1],
            "components" => [
                [ "class" => SimpleQuitListener::class ],
            ]
        ],
        [
            "type" => GameObject::class,
            "name" => "GameObject",
            "position" => [1, 1],
            "rotation" => [0, 0],
            "scale" => [1, 1],
            "components" => []
        ]
    ],
];

PHP;
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
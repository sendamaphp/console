<?php

require __DIR__ . '/vendor/autoload.php';

use Sendama\Engine\Game;
use Sendama\Engine\Core\Scenes\TitleScene;

function bootstrap(): void
{
  $gameName = '%GAME_NAME%'; // This will be overwritten by the .env file if GAME_NAME is set
  $game = new Game($gameName);

  $titleScene = new TitleScene('Title Screen');
  $titleScene->setMenuTitle($gameName);

  $game
    ->addScenes($titleScene)
    ->loadScenes('Scenes/Level')
    ->loadSettings()
    ->run();
}

bootstrap();

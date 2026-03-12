<?php

namespace Sendama\Console\Strategies\AssetFileGeneration;

use Sendama\Console\Strategies\AssetFileGeneration;
use Sendama\Console\Util\Path;

class TextureFileGenerationStrategy extends AssetFileGeneration\AbstractAssetFileGenerationStrategy
{

  /**
   * @inheritDoc
   */
  protected function configure(): void
  {
    if (! $this->fileExtension ) {
      $this->fileExtension = '.texture';
    }
    $this->content = 'x';


    $nameTokens = explode('/', $this->filename);

    $this->classPath = to_pascal_case($this->directory);

    foreach ($nameTokens as $token) {
      $this->classPath = Path::join($this->classPath, to_kebab_case($token));
    }

    $this->className = basename($this->classPath);

    $this->relativeFilename = Path::join($this->assetsDirectoryName, $this->classPath . ($this->fileExtension ?? '.php'));

  }
}

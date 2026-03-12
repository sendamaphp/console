<?php

namespace Sendama\Console\Strategies\AssetFileGeneration;

use Sendama\Console\Util\Path;

class ScriptFileGenerationStrategy extends AbstractAssetFileGenerationStrategy
{

  /**
   * @inheritDoc
   */
  protected function configure(): void
  {
    $namespace = $this->composerConfig->getNamespace() . 'Scripts';
    $namespaceTail = preg_replace('/^[Aa]ssets\/Scripts\/?/', '', dirname($this->relativeFilename));

    if ($namespaceTail) {
      $namespace .= '\\' . to_pascal_case(str_replace('/', '\\', $namespaceTail));
    }

    $this->content = <<<PHP
<?php

namespace $namespace;

use Sendama\Engine\Core\Behaviours\Behaviour;

class $this->className extends Behaviour
{
  public function onStart(): void
  {
    // onStart is useful for initializing variables
  }

  public function onUpdate(): void
  {
    // onUpdate is called once per frame
  }
}

PHP;
  }
}

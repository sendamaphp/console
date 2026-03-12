<?php

namespace Sendama\Console\Util\Config;

/**
 * Class ComposerConfig represents a composer configuration.
 *
 * @package Sendama\Console\Util\Config
 */
class ComposerConfig extends AbstractConfig
{
  protected string $filename = 'composer.json';

  /**
   * Gets the namespace.
   *
   * @return string|false The namespace, or false if not found.
   */
  public function getNamespace(): string|false
  {
    $namespaces = $this->get('autoload.psr-4') ?? [];

    foreach ($namespaces as $namespace => $path) {
      if ($path === 'Assets/' || $path === 'assets/') {
        return $namespace;
      }
    }

    return false;
  }
}

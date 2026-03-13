<?php

namespace Sendama\Console\Strategies\AssetFileGeneration;

use Sendama\Console\Util\Path;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PrefabFileGenerationStrategy extends AbstractAssetFileGenerationStrategy
{
    public static function buildPrefabContents(
        string $displayName = 'GameObject',
        string $kind = 'gameobject',
    ): string {
        return match (strtolower($kind)) {
            'label' => <<<PHP
<?php

use Sendama\Engine\UI\Label\Label;

return [
    'type' => Label::class,
    'name' => '{$displayName}',
    'tag' => 'UI',
    'position' => [
        'x' => 0,
        'y' => 0,
    ],
    'size' => [
        'x' => 10,
        'y' => 1,
    ],
    'text' => '{$displayName}',
];

PHP,
            'text' => <<<PHP
<?php

use Sendama\Engine\UI\Text\Text;

return [
    'type' => Text::class,
    'name' => '{$displayName}',
    'tag' => 'UI',
    'position' => [
        'x' => 0,
        'y' => 0,
    ],
    'size' => [
        'x' => 10,
        'y' => 1,
    ],
    'text' => '{$displayName}',
];

PHP,
            default => <<<PHP
<?php

use Sendama\Engine\Core\GameObject;

return [
    'type' => GameObject::class,
    'name' => '{$displayName}',
    'tag' => 'None',
    'position' => [
        'x' => 0,
        'y' => 0,
    ],
    'rotation' => [
        'x' => 0,
        'y' => 0,
    ],
    'scale' => [
        'x' => 1,
        'y' => 1,
    ],
    'components' => [],
];

PHP,
        };
    }

    public function __construct(
        InputInterface $input,
        OutputInterface $output,
        string $filename,
        string $directory,
        private readonly string $kind = 'gameobject',
    )
    {
        parent::__construct($input, $output, $filename, $directory, '.prefab.php');
    }

    protected function configure(): void
    {
        $filename = Path::join(dirname($this->classPath), to_kebab_case($this->className));
        $this->relativeFilename = Path::join($this->assetsDirectoryName, $filename . ($this->fileExtension ?? '.php'));
        $this->content = self::buildPrefabContents($this->buildDisplayName(), $this->kind);
    }

    private function buildDisplayName(): string
    {
        $kebabName = to_kebab_case($this->className);
        $spacedName = str_replace('-', ' ', $kebabName);

        return ucwords($spacedName);
    }
}

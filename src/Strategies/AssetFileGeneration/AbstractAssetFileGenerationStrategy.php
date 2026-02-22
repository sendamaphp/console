<?php

namespace Sendama\Console\Strategies\AssetFileGeneration;

use Sendama\Console\Interfaces\AssetFileGenerationStrategyInterface;
use Sendama\Console\Util\Config\ComposerConfig;
use Sendama\Console\Util\Config\ProjectConfig;
use Sendama\Console\Util\Inspector;
use Sendama\Console\Util\Path;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class AssetFileGenerationStrategy
 *
 * @package Sendama\Console\Strategies\AssetFileGeneration
 */
abstract class AbstractAssetFileGenerationStrategy implements AssetFileGenerationStrategyInterface
{
    /**
     * The class path.
     *
     * @var string
     */
    protected string $content = '';
    /**
     * @var ProjectConfig
     */
    protected ProjectConfig $projectConfig;
    /**
     * @var ComposerConfig
     */
    protected ComposerConfig $composerConfig;
    /**
     * @var Inspector
     */
    protected Inspector $inspector;
    protected string $classPath = '';
    protected string $className = '';
    protected string $relativeFilename = '';
    protected string $suffix = '';

    /**
     * AssetFileGenerationStrategy constructor.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param string $filename
     * @param string $directory
     * @param string|null $fileExtension
     */
    public function __construct(
        protected InputInterface  $input,
        protected OutputInterface $output,
        protected string          $filename,
        protected string          $directory,
        protected ?string         $fileExtension = null
    )
    {
        $this->projectConfig = new ProjectConfig($input, $output);
        $this->composerConfig = new ComposerConfig($input, $output);
        $this->inspector = new Inspector($input, $output);

        $nameTokens = explode('/', $this->filename);

        $this->classPath = to_pascal_case($this->directory);

        foreach ($nameTokens as $token) {
            $this->classPath = Path::join($this->classPath, to_pascal_case($token));
        }

        if ($this->suffix) {
            $this->suffix = to_pascal_case($this->suffix);
            $this->classPath = $this->classPath . $this->suffix;
        }

        $this->className = basename($this->classPath);

        $this->relativeFilename = Path::join('assets', $this->classPath . ($this->fileExtension ?? '.php'));

        $this->configure();
    }

    /**
     * Configure the asset file generation strategy.
     */
    protected abstract function configure(): void;

    /**
     * @inheritDoc
     */
    public function generate(): int
    {
        $this->inspector->validateProjectDirectory();

        $filename = Path::join(getcwd() ?: '', $this->relativeFilename);

        if (!file_exists(dirname($filename))) {
            if (false === mkdir(dirname($filename), 0777, true)) {
                $this->output->writeln("<error>Failed to create directory " . dirname($filename) . ".</error>");
                return Command::FAILURE;
            }
        }

        if (file_exists($filename)) {
            $this->output->writeln("<error>$this->relativeFilename already exists.</error>");
            return Command::FAILURE;
        }

        $bytes = file_put_contents($filename, $this->content);

        if (false === $bytes) {
            $this->output->writeln("<error>Failed to generate script $filename.</error>");
            return Command::FAILURE;
        }

        $this->output->writeln("<info>CREATE</info> $this->relativeFilename ($bytes bytes)");
        return Command::SUCCESS;
    }
}
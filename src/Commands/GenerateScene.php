<?php

namespace Sendama\Console\Commands;

use Sendama\Console\Strategies\AssetFileGeneration\SceneFileGenerationStrategy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'generate:scene',
    description: 'Generate a new scene',
)]
class GenerateScene extends Command
{
    public function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the scene')
            ->addOption('type', 't', InputArgument::OPTIONAL | InputOption::VALUE_REQUIRED, 'The type of the scene file to generate (class, meta)', 'meta')
            ->addOption('as-class', null, InputArgument::OPTIONAL | InputOption::VALUE_NONE, 'Whether to generate the scene as a class file instead of a meta file');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $asClass = $input->getOption('as-class') || $input->getOption('type') === 'class';
        $sceneGenerationStrategy = new SceneFileGenerationStrategy($input, $output, $input->getArgument('name') ?? 'scene', 'scenes', asMetaFile: !$asClass);
        return $sceneGenerationStrategy->generate();
    }
}
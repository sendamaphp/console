<?php

namespace Sendama\Console\Commands;

use Sendama\Console\Strategies\AssetFileGeneration\PrefabFileGenerationStrategy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
  name: 'generate:prefab',
  description: 'Generate a new prefab',
)]
class GeneratePrefab extends Command
{
    public function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the prefab')
            ->addOption(
                'kind',
                'k',
                InputOption::VALUE_REQUIRED,
                'The prefab shape to generate (gameobject, label, text)',
                'gameobject',
            );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $prefabGenerationStrategy = new PrefabFileGenerationStrategy(
            $input,
            $output,
            $input->getArgument('name') ?? 'prefab',
            'prefabs',
            strtolower((string) $input->getOption('kind')),
        );

        return $prefabGenerationStrategy->generate();
    }
}

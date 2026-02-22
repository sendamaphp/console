<?php

namespace Sendama\Console\Commands;

use Sendama\Console\Editor\Editor;
use Sendama\Console\Exceptions\IOException;
use Sendama\Console\Util\Config\ProjectConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'edit',
    description: 'Edit the game configuration.',
    aliases: ['e', 'edit:game']
)]
class EditGame extends Command
{
    public function configure(): void
    {
        $this
            ->addOption('directory', 'd', InputArgument::OPTIONAL, 'The directory of the game');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws IOException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("Opening game configuration for editing...", OutputInterface::VERBOSITY_VERBOSE);

        $directory = $input->getOption('directory') ?? '.';

        $projectConfig = new ProjectConfig($input, $output);
        $projectConfig->load();

        $editor = new Editor(name: $projectConfig->get("name"), workingDirectory: $directory);
        $editor->run();

        $output->writeln("Finished editing game configuration.", OutputInterface::VERBOSITY_VERBOSE);
        return Command::SUCCESS;
    }
}
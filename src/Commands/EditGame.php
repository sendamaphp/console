<?php

namespace Sendama\Console\Commands;

use Sendama\Console\Editor\Editor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'edit:game',
    description: 'Edit the game configuration.',
    aliases: ['edit']
)]
class EditGame extends Command
{
    public function configure(): void
    {
        $this->addOption('directory', 'd', InputArgument::OPTIONAL, 'The directory of the game');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("Opening game configuration for editing...");

        $directory = $input->getOption('directory');

        $editor = new Editor();



        return Command::SUCCESS;
    }
}
<?php

namespace Sendama\Console\Commands;

use Sendama\Console\Enumerations\LogOption;
use Sendama\Console\Util\Path;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'view:log',
    description: 'View the log file',
)]
class ViewLog extends Command
{
  public function configure(): void
  {
    $this->addArgument(
      'type',
      InputArgument::OPTIONAL,
      'The type of log file',
      LogOption::ALL->value,
      LogOption::toArray()
    );

    $this->addOption('directory', 'd', InputArgument::OPTIONAL, 'The directory of the game', '.');
  }

  /**
   * Execute the command.
   *
   * @param InputInterface $input The input interface.
   * @param OutputInterface $output The output interface.
   * @return int The status code.
   */
  public function execute(InputInterface $input, OutputInterface $output): int
  {
    $type = $input->getArgument('type') ?? LogOption::ALL->value;
    $directory = $input->getOption('directory') ?? '.';

    if (! is_dir($directory) ) {
      $output->writeln("<error>Directory $directory not found.</error>");
      return Command::FAILURE;
    }

    $logFilename = Path::join($directory, 'logs', $type . '.log');

    $logFilename = str_replace('all.log', '*', $logFilename);

    if (! file_exists($logFilename) && $type !== LogOption::ALL->value) {
      $output->writeln("<error>Log file $logFilename not found.</error>");
      return Command::FAILURE;
    }

    $logCommand = "tail ";

    if (shell_exec("which multitail")) {
      $logCommand = "multitail ";
    }

    if (false === shell_exec($logCommand . escapeshellarg($logFilename))) {
      $output->writeln("<error>Failed to open log file $logFilename.</error>");
      return Command::FAILURE;
    }

    return Command::SUCCESS;
  }
}
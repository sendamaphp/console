<?php

namespace Sendama\Console\Commands;

use Sendama\Console\Util\Inspector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'update',
    description: 'Update the game',
)]
class Update extends Command
{
  public function configure(): void
  {
    $this->addOption('directory', ['d', 'dir'], InputOption::VALUE_REQUIRED, 'The directory of the game', '.');
  }

  public function execute(InputInterface $input, OutputInterface $output): int
  {
    write_console_info('Updating the game...', $output);
    $directory = $input->getOption('directory') ?? '.';

    $inspector = new Inspector($input, $output);
    $inspector->validateProjectDirectory($directory);

    $exitCode = $this->runComposerUpdate((string) $directory);

    if ($exitCode !== 0) {
      write_console_error('Update failed.', $output);
      return Command::FAILURE;
    }

    write_console_info('Update completed.', $output);

    return Command::SUCCESS;
  }

  protected function runComposerUpdate(string $directory): int
  {
    $command = $this->buildComposerUpdateCommand($directory);
    passthru($command, $exitCode);

    return $exitCode;
  }

  protected function buildComposerUpdateCommand(string $directory): string
  {
    $composerPhar = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.phar';

    if (is_file($composerPhar)) {
      return sprintf(
        '%s %s update --working-dir=%s --ansi',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($composerPhar),
        escapeshellarg($directory),
      );
    }

    return sprintf(
      'composer update --working-dir=%s --ansi',
      escapeshellarg($directory),
    );
  }

  /**
   * Get a header.
   *
   * @param string $text The text to display.
   * @param string $color The color of the header.
   * @return string The header.
   */
  private function getHeader(string $text, string $color = "\e[0;44m"): string
  {
    return sprintf("%s    %s    \e[0m\n", $color, $text);
  }
}

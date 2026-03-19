<?php

namespace Sendama\Console\Commands;

use Dotenv\Dotenv;
use Sendama\Console\Util\Path;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'play',
    description: 'Start playing the game',
    aliases: ['p'],
)]
class PlayGame extends Command
{
  public function configure(): void
  {
    $this->addOption('directory', 'd', InputArgument::OPTIONAL, 'The directory of the game', '.');
  }

  public function execute(InputInterface $input, OutputInterface $output): int
  {
    $directory = $this->resolveAbsoluteDirectory((string) ($input->getOption('directory') ?? '.'));
    $sendamaConfigFilename = 'sendama.json';
    $sendamaDotEnvFilename = '.env';

    if (! $this->isValidDirectory($directory) ) {
      $output->writeln('Invalid Sendama game directory.');
      return Command::FAILURE;
    }

    // Load the .env file
    if (file_exists($directory . '/' . $sendamaDotEnvFilename) ) {
      $dotenv = Dotenv::createImmutable($directory);
      $dotenv->load();
    }

    // Open the project config and retrieve the main file
    $config = json_decode(file_get_contents($directory . '/' . $sendamaConfigFilename));

    if (! file_exists($directory . '/' . $config->main) ) {
      $output->writeln('Main file not found.');
      return Command::FAILURE;
    }

    if ( env('DEBUG_MODE', false) ) {
      $gameName = $config->name ?? env('GAME_NAME', 'Sendama Game');
      $output->writeln([
        "<question> INFO </question> Debug mode enabled",
        "",
        "<question> LOG </question> Starting Sendama project...",
        "<question> LOG </question> GAME NAME: <fg=gray>$gameName</>",
        "<question> LOG </question> GAME DIRECTORY: <fg=gray>$directory</>"
      ]);
      usleep(2500000);
    }

    // Start the game using the main file
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg((string) $config->main);
    $descriptors = [
      0 => ['file', 'php://stdin', 'r'],
      1 => ['file', 'php://stdout', 'w'],
      2 => ['file', 'php://stderr', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $directory);

    if (!is_resource($process)) {
      $output->writeln('Failed to start the game.');
      return Command::FAILURE;
    }

    $exitCode = proc_close($process);

    return $exitCode === 0 ? Command::SUCCESS : Command::FAILURE;
  }

  /**
   * Checks if the given directory is a valid Sendama game directory.
   *
   * @param string $directory The directory to check.
   * @return bool True if the directory is a valid Sendama game directory; otherwise, false.
   */
  private function isValidDirectory(string $directory): bool
  {
    return file_exists($directory . '/sendama.json');
  }

  private function resolveAbsoluteDirectory(string $directory): string
  {
    $normalizedDirectory = Path::normalize(trim($directory));

    if ($normalizedDirectory === '' || $normalizedDirectory === '.') {
      $normalizedDirectory = getcwd() ?: '.';
    } elseif (!str_starts_with($normalizedDirectory, '/')) {
      $normalizedDirectory = Path::join(getcwd() ?: '.', $normalizedDirectory);
    }

    $resolvedDirectory = realpath($normalizedDirectory);

    if (is_string($resolvedDirectory) && $resolvedDirectory !== '') {
      return Path::normalize($resolvedDirectory);
    }

    return Path::normalize($normalizedDirectory);
  }
}

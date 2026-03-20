<?php

namespace Sendama\Console\Commands;

use Sendama\Console\Enumerations\LogOption;
use Sendama\Console\Util\Inspector;
use Sendama\Console\Util\Path;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

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

    $this->addOption('directory', ['d', 'dir'], InputOption::VALUE_REQUIRED, 'The directory of the game', '.');
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
    $typeValue = strtolower(trim((string) ($input->getArgument('type') ?? LogOption::ALL->value)));
    $type = LogOption::tryFrom($typeValue);

    if (!$type instanceof LogOption) {
      $output->writeln('<error>Invalid log type. Use one of: ' . implode(', ', LogOption::toArray()) . '.</error>');
      return Command::FAILURE;
    }

    $directory = $this->resolveAbsoluteDirectory((string) ($input->getOption('directory') ?? '.'));

    try {
      $inspector = new Inspector($input, $output);
      $inspector->validateProjectDirectory($directory);

      $logFiles = $this->resolveLogFiles($directory, $type);

      if ($logFiles === []) {
        $output->writeln('<error>No log files found.</error>');
        return Command::FAILURE;
      }

      $exitCode = $this->runLogViewer($logFiles, $type);

      if ($exitCode !== 0) {
        $output->writeln('<error>Failed to open log viewer.</error>');
        return Command::FAILURE;
      }
    } catch (Throwable $exception) {
      $output->writeln('<error>' . $exception->getMessage() . '</error>');
      return Command::FAILURE;
    }

    return Command::SUCCESS;
  }

  protected function runLogViewer(array $logFiles, LogOption $type): int
  {
    $command = $this->buildLogViewerCommand($logFiles, $type);
    passthru($command, $exitCode);

    return $exitCode;
  }

  protected function buildLogViewerCommand(array $logFiles, LogOption $type): string
  {
    $escapedLogFiles = implode(' ', array_map(
      static fn (string $logFile): string => escapeshellarg($logFile),
      $logFiles,
    ));

    if ($type === LogOption::ALL && count($logFiles) > 1 && $this->hasMultitail()) {
      return 'multitail ' . $escapedLogFiles;
    }

    $tailOptions = $type === LogOption::ALL && count($logFiles) > 1
      ? '-q -n 50 -f -- '
      : '-n 50 -f -- ';

    return 'tail ' . $tailOptions . $escapedLogFiles;
  }

  protected function hasMultitail(): bool
  {
    $command = shell_exec('command -v multitail 2>/dev/null');

    return is_string($command) && trim($command) !== '';
  }

  /**
   * @return array<string>
   */
  private function resolveLogFiles(string $directory, LogOption $type): array
  {
    $logsDirectory = Path::join($directory, 'logs');

    if (!is_dir($logsDirectory)) {
      throw new \RuntimeException("Logs directory $logsDirectory not found.");
    }

    if ($type === LogOption::ALL) {
      $logFiles = array_values(array_filter([
        Path::join($logsDirectory, LogOption::DEBUG->value . '.log'),
        Path::join($logsDirectory, LogOption::ERROR->value . '.log'),
      ], 'is_file'));

      if ($logFiles === []) {
        throw new \RuntimeException("No log files found in $logsDirectory.");
      }

      return $logFiles;
    }

    $logFile = Path::join($logsDirectory, $type->value . '.log');

    if (!is_file($logFile)) {
      throw new \RuntimeException("Log file $logFile not found.");
    }

    return [$logFile];
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

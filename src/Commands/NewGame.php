<?php

namespace Sendama\Console\Commands;

use RuntimeException;
use Sendama\Console\Util\Path;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

#[AsCommand(
    name: 'new:game',
    description: 'Create a new game',
    aliases: ['new']
)]
class NewGame extends Command
{
  /**
   * @var OutputInterface|null The output interface.
   */
  private ?OutputInterface $output = null;
  /**
   * @var InputInterface|null The input interface.
   */
  private ?InputInterface $input = null;

  // Directories
  /**
   * @var string The target directory.
   */
  private string $targetDirectory = '';
  /**
   * @var string The maps' directory.
   */
  private string $mapsDirectory = '';

  /**
   * @inheritDoc
   */
  public function configure(): void
  {
    $this
      ->addArgument('name', InputArgument::REQUIRED, 'The name of the game')
      ->addOption('directory', ['d', 'dir'], InputArgument::OPTIONAL, 'The directory to create the game in', getcwd());
    $this->output = new ConsoleOutput();
  }

  /**
   * @inheritDoc
   */
  public function execute(InputInterface $input, OutputInterface $output): int
  {
    $this->input = $input;
    $this->output = $output;

    // Configure the target directory
    $projectName = $input->getArgument('name');
    $output->writeln("<info>Creating $projectName...</info>", OutputInterface::VERBOSITY_VERBOSE);
    $this->targetDirectory = Path::join(
      $this->targetDirectory,
      $input->getOption('directory'),
      strtolower(filter_string($projectName))
    );

    // Create project directory
    $this->createProjectDirectory();

    // Create project structure
    $this->output->writeln('<info>Creating project structure...</info>', OutputInterface::VERBOSITY_VERBOSE);

    $this->createLogsDirectory();
    $assetsDirectory = $this->createAssetsDirectory();
    $this->createAssetsScenesDirectory($assetsDirectory);
    $this->createAssetsScriptsDirectory($assetsDirectory);
    $this->createAssetsMapsDirectory($assetsDirectory);
    $this->createAssetsPrefabsDirectory($assetsDirectory);
    $this->createAssetsTexturesDirectory($assetsDirectory);

    // Create project files
    $this->output->writeln('<info>Creating project files...</info>', OutputInterface::VERBOSITY_VERBOSE);

    $this->createMainFile($projectName);
    $this->createDotEnvFile($this->targetDirectory);
    $this->createGitIgnoreFile($this->targetDirectory);
    $this->createSplashScreenTextureFile($assetsDirectory);
    $this->createPlayerTextureFile($assetsDirectory);
    $this->createTheExampleMapFile($this->mapsDirectory);
    $this->createDocsDirectory($this->targetDirectory);
    $this->createReadmeFile($this->targetDirectory);

    // Create project configuration
    $this->createProjectConfiguration($projectName);

    // Done
    $this->output->writeln("\nDone! 🎮🎮🎮");

    // Tell user cd into the project directory
    $this->output->writeln("\nTo get started:");
    $targetDirectory = basename($this->targetDirectory);

    $this->output->writeln("\n\t<fg=gray>cd $targetDirectory</>");
    $this->output->writeln("\t<fg=gray>php $targetDirectory.php</>\n");

    return Command::SUCCESS;
  }

  /**
   * Ask the user to confirm an action.
   *
   * @param string $question The question to ask the user.
   * @param bool $default The default response. Default is false.
   * @return bool The user's response.
   */
  private function confirm(string $question, bool $default = false): bool
  {
    /** @var QuestionHelper $helper */
    $helper = $this->getHelper('question');
    $question = new ConfirmationQuestion($question, $default);

    return $helper->ask($this->input, $this->output, $question);
  }

  /**
   * Get the project configuration.
   *
   * @param string $projectName The project name.
   * @return string The project configuration.
   */
  private function getProjectConfiguration(string $projectName): string
  {
    $mainFilename = strtolower(filter_string($projectName)) . '.php';

    return json_encode([
      'name' => $projectName,
      'description' => 'A 2D ASCII terminal game.',
      'version' => '0.0.1',
      'main' => $mainFilename,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  /**
   * Get the project configuration.
   *
   * @param string $packageName The package name.
   * @return string The project configuration.
   */
  private function getComposerConfiguration(string $packageName): string
  {
    [$organization, $projectName] = explode('/', $packageName);
    $namespace = to_title_case($organization) . '\\' . to_title_case($projectName) . '\\';

    return json_encode([
      'name' => $packageName,
      'version' => '1.0.0',
      'description' => 'A new 2D ASCII terminal game.',
      'type' => 'project',
      'require' => [
        'php' => '^8.3',
        'sendamaphp/engine' => '*'
      ],
      'require-dev' => [
        'pestphp/pest' => '^2.34',
        'phpstan/phpstan' => '^1.10',
      ],
      'autoload' => [
        'psr-4' => [
          $namespace => 'assets/'
        ]
      ],
      'config' => [
        'allow-plugins' => [
          'pestphp/pest-plugin' => true
        ]
      ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  /**
   * Get the package name.
   *
   * @param string $default The default package name.
   * @return string The package name.
   */
  private function getPackageName(string $default): string
  {
    /** @var QuestionHelper $helper */
    $helper = $this->getHelper('question');
    $question = new Question("<info>?</info> Package name: <fg=gray>($default)</> ", $default);

    $packageName = $helper->ask($this->input, $this->output, $question);

    $validPackageNamePattern = '/[a-zA-Z0-9_]+(-*[a-zA-Z0-9_]*)*\/[a-zA-Z0-9_]+(-*[a-zA-Z0-9_]*)*/';
    if (! preg_match($validPackageNamePattern, $packageName) ) {
      throw new RuntimeException('Invalid package name');
    }

    return $packageName;
  }

  /**
   * Install the dependencies.
   *
   * @param string $targetDirectory The target directory.
   */
  private function installDependencies(string $targetDirectory): void
  {
    // Install dependencies
    $this->output->writeln('<comment>Installing dependencies...</comment>', OutputInterface::VERBOSITY_VERBOSE);
    $installCommand = "composer install --working-dir=" . escapeshellarg($targetDirectory) . " --ansi";
    if (false === shell_exec($installCommand)) {
      throw new RuntimeException('Unable to install dependencies');
    }
  }

  /**
   * Create the project configuration.
   *
   * @param string $projectName The project name.
   */
  private function createProjectConfiguration(string $projectName): void
  {
    $this->output->writeln('<comment>Creating project configuration...</comment>', OutputInterface::VERBOSITY_VERBOSE);

    $targetConfigFilename = Path::join($this->targetDirectory, 'sendama.json');
    if (false === file_put_contents($targetConfigFilename, $this->getProjectConfiguration($projectName))) {
      throw new RuntimeException(sprintf('Unable to write to file "%s"', $targetConfigFilename));
    }

    $this->output->writeln('<comment>Creating package configuration</comment>', OutputInterface::VERBOSITY_VERBOSE);
    $projectName = strtolower(filter_string($projectName));
    $packageName = $this->getPackageName("sendama-engine/$projectName");

    $targetConfigFilename = Path::join($this->targetDirectory, 'composer.json');
    if (false === file_put_contents($targetConfigFilename, $this->getComposerConfiguration($packageName))) {
      throw new RuntimeException(sprintf('Unable to write to file "%s"', $targetConfigFilename));
    }

    if ($this->confirm('<info>?</info> Would you like to install the dependencies? <fg=gray>(Y/n)</> ', 'y') ) {
      $this->installDependencies($this->targetDirectory);
    }
  }

  /**
   * Create the main file.
   *
   * @param mixed $projectName The project name.
   */
  private function createMainFile(string $projectName): void
  {
    $targetMainFilename = Path::join(
      $this->targetDirectory,
      basename($this->targetDirectory) . '.php'
    );
    $sourceMainFilename = Path::join(dirname(__DIR__, 2), 'templates', 'game.php');
    if (! copy($sourceMainFilename, $targetMainFilename) ) {
      throw new RuntimeException(sprintf('File "%s" was not copied to "%s"', $sourceMainFilename, $targetMainFilename));
    }

    ## Replace the game name in the main file
    $mainFileContents = file_get_contents($targetMainFilename);
    $mainFileContents = str_replace('%GAME_NAME%', $projectName, $mainFileContents);
    if (false === file_put_contents($targetMainFilename, $mainFileContents)) {
      throw new RuntimeException(sprintf('Unable to write to file "%s"', $targetMainFilename));
    }

  }

  /**
   * Create the splash screen texture file.
   *
   * @param string $assetsDirectory The assets' directory.
   */
  private function createSplashScreenTextureFile(string $assetsDirectory): void
  {
    $this->output->writeln('<comment>Creating splash screen texture file...</comment>', OutputInterface::VERBOSITY_VERBOSE);
    $targetSplashScreenTextureFilename = Path::join($assetsDirectory, 'splash.texture');

    ## Load the splash screen texture from assets/splash.texture
    $sourceSplashScreenTextureFilename = Path::join(dirname(__DIR__, 2), 'templates', 'assets', 'splash.texture');
    if (! copy($sourceSplashScreenTextureFilename, $targetSplashScreenTextureFilename) ) {
      throw new RuntimeException(sprintf('File "%s" was not copied to "%s"', $sourceSplashScreenTextureFilename, $targetSplashScreenTextureFilename));
    }
  }

  /**
   * Create the example map file.
   *
   * @param string $mapsDirectory The maps' directory.
   */
  private function createTheExampleMapFile(string $mapsDirectory): void
  {
    $this->output->writeln('<comment>Creating example map file...</comment>', OutputInterface::VERBOSITY_VERBOSE);
    $targetExampleMapFilename = Path::join($mapsDirectory, 'example.tmap');
    $sourceExampleMapFilename = Path::join(dirname(__DIR__, 2), 'templates', 'assets', 'Maps', 'example.tmap');

    if (! copy($sourceExampleMapFilename, $targetExampleMapFilename) ) {
      throw new RuntimeException(sprintf('File "%s" was not copied to "%s"', $sourceExampleMapFilename, $targetExampleMapFilename));
    }

  }

  /**
   * Create the assets' textures directory.
   *
   * @param string $assetsDirectory The assets' directory.
   */
  private function createAssetsTexturesDirectory(string $assetsDirectory): void
  {
    $texturesDirectory = Path::join($assetsDirectory, 'Textures');
    if (file_exists($texturesDirectory)) {
      $this->output->writeln('<comment>Textures directory already exists...</comment>', OutputInterface::VERBOSITY_VERBOSE);
      return;
    }

    if (! mkdir($texturesDirectory) && ! is_dir($texturesDirectory)) {
      throw new RuntimeException(sprintf('Directory "%s" was not created', $texturesDirectory));
    }
  }

  /**
   * Create the assets' prefabs directory.
   *
   * @param string $assetsDirectory The assets' directory.
   */
  private function createAssetsPrefabsDirectory(string $assetsDirectory): void
  {
    $prefabsDirectory = Path::join($assetsDirectory, 'Prefabs');
    if (file_exists($prefabsDirectory)) {
      $this->output->writeln('<comment>Prefabs directory already exists...</comment>', OutputInterface::VERBOSITY_VERBOSE);
      return;
    }

    if (! mkdir($prefabsDirectory) && ! is_dir($prefabsDirectory)) {
      throw new RuntimeException(sprintf('Directory "%s" was not created', $prefabsDirectory));
    }
  }

  /**
   * Create the assets' maps directory.
   *
   * @param string $assetsDirectory The assets' directory.
   */
  private function createAssetsMapsDirectory(string $assetsDirectory): void
  {
    $this->mapsDirectory = Path::join($assetsDirectory, 'Maps');
    if (file_exists($this->mapsDirectory)) {
      $this->output->writeln('<comment>Maps directory already exists...</comment>', OutputInterface::VERBOSITY_VERBOSE);
      return;
    }

    if (! mkdir($this->mapsDirectory) && ! is_dir($this->mapsDirectory)) {
      throw new RuntimeException(sprintf('Directory "%s" was not created', $this->mapsDirectory));
    }
  }

  /**
   * Create the assets' scripts directory.
   *
   * @param string $assetsDirectory The assets' directory.
   */
  private function createAssetsScriptsDirectory(string $assetsDirectory): void
  {
    $scriptsDirectory = Path::join($assetsDirectory, 'Scripts');
    if (file_exists($scriptsDirectory)) {
      $this->output->writeln('<comment>Scripts directory already exists...</comment>', OutputInterface::VERBOSITY_VERBOSE);
      return;
    }

    if (! mkdir($scriptsDirectory) && ! is_dir($scriptsDirectory)) {
      throw new RuntimeException(sprintf('Directory "%s" was not created', $scriptsDirectory));
    }
  }

  /**
   * Create the assets' scenes directory.
   *
   * @param string $assetsDirectory The assets' directory.
   */
  private function createAssetsScenesDirectory(string $assetsDirectory): void
  {
    $scenesDirectory = Path::join($assetsDirectory, 'Scenes');
    if (file_exists($scenesDirectory)) {
      $this->output->writeln('<comment>Scenes directory already exists...</comment>', OutputInterface::VERBOSITY_VERBOSE);
      return;
    }

    if (! mkdir($scenesDirectory) && ! is_dir($scenesDirectory)) {
      throw new RuntimeException(sprintf('Directory "%s" was not created', $scenesDirectory));
    }
  }

  /**
   * Create the assets' directory.
   *
   * @return string The assets' directory.
   */
  private function createAssetsDirectory(): string
  {
    $assetsDirectory = Path::join($this->targetDirectory, 'assets');
    if (file_exists($assetsDirectory)) {
      return $assetsDirectory;
    }

    if (! mkdir($assetsDirectory) && ! is_dir($assetsDirectory)) {
      throw new RuntimeException(sprintf('Directory "%s" was not created', $assetsDirectory));
    }

    return $assetsDirectory;
  }

  /**
   * Create the logs' directory.
   */
  private function createLogsDirectory(): void
  {
    $logsDirectory = Path::join($this->targetDirectory, 'logs');
    if (file_exists($logsDirectory)) {
      $this->output->writeln('<comment>Logs directory already exists...</comment>', OutputInterface::VERBOSITY_VERBOSE);
      return;
    }

    if (! mkdir($logsDirectory) && ! is_dir($logsDirectory)) {
      throw new RuntimeException(sprintf('Directory "%s" was not created', $logsDirectory));
    }
  }

  /**
   * Create the project directory.
   */
  private function createProjectDirectory(): void
  {
    $this->output->writeln('<comment>Creating project directory...</comment>', OutputInterface::VERBOSITY_VERBOSE);
    if (file_exists($this->targetDirectory)) {
      $this->output->writeln('<error>Project directory already exists...</error>', OutputInterface::VERBOSITY_VERBOSE);
      return;
    }

    if (! mkdir($this->targetDirectory) && ! is_dir($this->targetDirectory)) {
      throw new RuntimeException(sprintf('Directory "%s" was not created', $this->targetDirectory));
    }
  }

  /**
   * Create the player texture file.
   *
   * @param string $assetsDirectory The assets' directory.
   */
  private function createPlayerTextureFile(string $assetsDirectory): void
  {
    $this->output->writeln('<comment>Creating player texture file...</comment>', OutputInterface::VERBOSITY_VERBOSE);
    $targetPlayerTextureFilename = Path::join($assetsDirectory, 'Textures', 'player.texture');
    $sourcePlayerTextureFilename = Path::join(dirname(__DIR__, 2), 'templates', 'assets', 'Textures', 'player.texture');

    if (! copy($sourcePlayerTextureFilename, $targetPlayerTextureFilename) ) {
      throw new RuntimeException(sprintf('File "%s" was not copied to "%s"', $sourcePlayerTextureFilename, $targetPlayerTextureFilename));
    }
  }

  /**
   * Create the .gitignore file.
   *
   * @param string $targetDirectory The target directory.
   */
  private function createDotEnvFile(string $targetDirectory): void
  {
    $this->output->writeln('<comment>Creating .env file...</comment>', OutputInterface::VERBOSITY_VERBOSE);
    $targetDotEnvFilename = Path::join($targetDirectory, '.env');
    $sourceDotEnvFilename = Path::join(dirname(__DIR__, 2), 'templates', '.env');

    if (! copy($sourceDotEnvFilename, $targetDotEnvFilename) ) {
      throw new RuntimeException(sprintf('File "%s" was not copied to "%s"', $sourceDotEnvFilename, $targetDotEnvFilename));
    }
  }

  /**
   * Create the .gitignore file.
   *
   * @param string $targetDirectory The target directory.
   * @return void
   */
  private function createGitIgnoreFile(string $targetDirectory): void
  {
    $this->output->writeln('<comment>Creating .gitignore file...</comment>', OutputInterface::VERBOSITY_VERBOSE);
    $targetGitIgnoreFilename = Path::join($targetDirectory, '.gitignore');
    $sourceGitIgnoreFilename = Path::join(dirname(__DIR__, 2), 'templates', '.gitignore');

    if (! copy($sourceGitIgnoreFilename, $targetGitIgnoreFilename) ) {
      throw new RuntimeException(sprintf('File "%s" was not copied to "%s"', $sourceGitIgnoreFilename, $targetGitIgnoreFilename));
    }
  }

  /**
   * Create the docs' directory.
   *
   * @param string $targetDirectory The target directory.
   * @return void
   */
  private function createDocsDirectory(string $targetDirectory): void
  {
    $this->output->writeln('<comment>Creating docs directory...</comment>', OutputInterface::VERBOSITY_VERBOSE);
    $docsDirectory = Path::join($targetDirectory, 'docs');
    $sourceDocsDirectory = Path::join(dirname(__DIR__, 2), 'templates', 'docs');

    if (file_exists($docsDirectory)) {
      $this->output->writeln('<comment>Docs directory already exists...</comment>', OutputInterface::VERBOSITY_VERBOSE);
      return;
    }

    # Copy the docs directory
    if (false === passthru("cp -r $sourceDocsDirectory $docsDirectory") ) {
      throw new RuntimeException(sprintf('Directory "%s" was not copied to "%s"', $sourceDocsDirectory, $docsDirectory));
    }
  }

  /**
   * Create the README file.
   *
   * @param string $targetDirectory The target directory.
   * @return void
   */
  private function createReadmeFile(string $targetDirectory): void
  {
    $this->output->writeln('<comment>Creating README file...</comment>', OutputInterface::VERBOSITY_VERBOSE);
    $targetReadmeFilename = Path::join($targetDirectory, 'README.md');
    $sourceReadmeFilename = Path::join(dirname(__DIR__, 2), 'templates', 'README.md');

    if (! copy($sourceReadmeFilename, $targetReadmeFilename) ) {
      throw new RuntimeException(sprintf('File "%s" was not copied to "%s"', $sourceReadmeFilename, $targetReadmeFilename));
    }
  }
}
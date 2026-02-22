<?php

use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

if (! function_exists('filter_string') ) {
  /**
   * Filter a string to remove any characters that are not alphanumeric or an underscore
   *
   * @param string $string The string to filter.
   * @return string The filtered string.
   */
  function filter_string(
    string $string,
    string $pattern = '^a-zA-Z0-9_-',
    string $separator = '-'
  ): string
  {
    $pattern = $pattern ?: '^a-zA-Z0-9_-';
    return preg_replace("/[$pattern]/", $separator, $string);
  }
}

if (! function_exists('to_pascal_case') ) {
  /**
   * Convert a string to pascal case.
   *
   * @param string $string The string to convert.
   * @return string The pascal case string.
   */
  function to_pascal_case(string $string): string
  {
    $chunks = preg_split('/[^a-zA-Z0-9]/', $string);

    $output = '';

    foreach ($chunks as $chunk) {
      $output .= ucfirst(strtolower($chunk));
    }

    return $output;
  }
}

if (! function_exists('to_camel_case') ) {
  /**
   * Convert a string to camel case.
   *
   * @param string $string The string to convert.
   * @return string The camel case string.
   */
  function to_camel_case(string $string): string
  {
    return lcfirst(to_pascal_case($string));
  }
}

if (! function_exists('to_snake_case') ) {
  /**
   * Convert a string to snake case.
   *
   * @param string $string The string to convert.
   * @return string The snake case string.
   */
  function to_snake_case(string $string): string
  {
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $string));
  }
}

if (! function_exists('to_kebab_case') ) {
  /**
   * Convert a string to kebab case.
   *
   * @param string $string The string to convert.
   * @return string The kebab case string.
   */
  function to_kebab_case(string $string): string
  {
    return str_replace('_', '-', to_snake_case($string));
  }
}

if (! function_exists('to_title_case') ) {
  /**
   * Convert a string to kebab case.
   *
   * @param string $string The string to convert.
   * @return string The kebab case string.
   */
  function to_title_case(string $string): string
  {
    $tokens = preg_split('/[^a-zA-Z0-9]/', $string);
    $output = '';

    foreach ($tokens as $i => $token) {
      $output .= ucfirst(strtolower($token));
    }

    return $output;
  }
}

if (! function_exists('env') ) {
  /**
   * Get an environment variable.
   *
   * @param string $key The key of the environment variable.
   * @param mixed|null $default The default value to return if the environment variable is not set.
   * @return mixed The value of the environment variable or the default value.
   */
  function env(string $key, mixed $default = null): mixed
  {
    if (isset($_ENV[$key]) ) {
      return $_ENV[$key];
    }

    return $default;
  }
}

if (! function_exists('write_console_log') ) {
  function write_console_log(string|array $messages, OutputInterface $output = new ConsoleOutput(), string $type = 'info'): void
  {
    $formatter = new FormatterHelper();

    $prefix = match($type) {
      'debug' => $formatter->formatBlock(' DEBUG ', '<fg=white;bg=gray'),
      'success' => $formatter->formatBlock(' SUCCESS ', 'info'),
      'warn' => $formatter->formatBlock(' ERROR ', 'comment'),
      'error' => $formatter->formatBlock(' ERROR ', 'error'),
      default => "<question> INFO </question>",
    };

    $message = is_array($messages) ? implode("\n", $messages) : $messages;

    $output->writeln("$prefix $message");
  }
}

if (! function_exists('write_console_debug') ) {
  /**
   * Write a debug message.
   *
   * @param string|array $messages The message to write.
   * @param OutputInterface $output The output interface to use.
   * @return void
   */
  function write_console_debug(string|array $messages, OutputInterface $output = new ConsoleOutput()): void
  {
    write_console_log($messages, $output, 'debug');
  }
}

if (! function_exists('write_console_success') ) {
  /**
   * Write a success message.
   *
   * @param string|array $messages The message to write.
   * @param OutputInterface $output The output interface to use.
   * @return void
   */
  function write_console_success(string|array $messages, OutputInterface $output = new ConsoleOutput()): void
  {
    write_console_log($messages, $output, 'success');
  }
}

if (! function_exists('write_console_warn') ) {
  /**
   * Write a warning message.
   *
   * @param string|array $messages The message to write.
   * @param OutputInterface $output The output interface to use.
   * @return void
   */
  function write_console_warn(string|array $messages, OutputInterface $output = new ConsoleOutput()): void
  {
    write_console_log($messages, $output, 'warn');
  }
}

if (! function_exists('write_console_info') ) {
  /**
   * Display an info message.
   *
   * @param string|array $messages The message to display.
   * @param OutputInterface $output The output interface to use.
   * @return void
   */
  function write_console_info(string|array $messages, OutputInterface $output = new ConsoleOutput()): void
  {
    write_console_log($messages, $output);
  }
}

if (! function_exists('write_console_error') ) {
  /**
   * Display an error message.
   *
   * @param string|array $messages The message to display.
   * @param OutputInterface $output The output interface to use.
   * @return void
   */
  function write_console_error(string|array $messages, OutputInterface $output = new ConsoleOutput()): void
  {
    write_console_log($messages, $output, 'error');
  }
}

if (! function_exists('clamp') ) {
    function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
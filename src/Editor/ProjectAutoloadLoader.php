<?php

namespace Sendama\Console\Editor;

final class ProjectAutoloadLoader
{
    public static function load(string $autoloadPath): void
    {
        if ($autoloadPath === '' || !is_file($autoloadPath)) {
            return;
        }

        $normalizedAutoloadPath = realpath($autoloadPath) ?: $autoloadPath;
        $autoloadDirectory = dirname($normalizedAutoloadPath);
        $previousHandler = null;

        $previousHandler = set_error_handler(
            static function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$previousHandler, $autoloadDirectory): bool {
                if (self::shouldIgnoreDuplicateConstantWarning($errno, $errstr, $errfile, $autoloadDirectory)) {
                    return true;
                }

                if (is_callable($previousHandler)) {
                    return (bool) $previousHandler($errno, $errstr, $errfile, $errline);
                }

                return false;
            }
        );

        try {
            require_once $normalizedAutoloadPath;
        } finally {
            restore_error_handler();
        }
    }

    private static function shouldIgnoreDuplicateConstantWarning(
        int $errno,
        string $errstr,
        string $errfile,
        string $autoloadDirectory,
    ): bool
    {
        if ($errno !== E_WARNING) {
            return false;
        }

        if (preg_match('/^Constant [A-Z0-9_]+ already defined/', $errstr) !== 1) {
            return false;
        }

        $normalizedErrorFile = realpath($errfile) ?: $errfile;
        $normalizedAutoloadDirectory = rtrim($autoloadDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($normalizedErrorFile, $normalizedAutoloadDirectory);
    }
}

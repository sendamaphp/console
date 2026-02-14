<?php

namespace Sendama\Console\Debug\Enumerations;

/**
 * The class LogLevel.
 *
 * @package Sendama\Console\Debug\Enumerations
 */
enum LogLevel: string
{
    case DEBUG = 'debug';
    case INFO = 'info';
    case WARN = 'warn';
    case ERROR = 'error';
    case FATAL = 'fatal';

    /**
     * Returns the priority of the log level.
     *
     * @return int The priority of the log level.
     */
    public function getPriority(): int
    {
        return match ($this) {
            self::FATAL => 0,
            self::ERROR => 1,
            self::WARN  => 2,
            self::INFO  => 3,
            self::DEBUG => 4,
        };
    }
}

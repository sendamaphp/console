<?php

namespace Sendama\Console\Editor;

use Atatusoft\Termutil\IO\Enumerations\Color;

final class EditorColorScheme
{
    public const PRIMARY_FOCUS_COLOR = Color::LIGHT_RED;
    public const PLAY_MODE_FOCUS_COLOR = Color::LIGHT_GREEN;
    public const ACTIVE_INDICATOR_COLOR = Color::LIGHT_RED;
    public const SUCCESS_COLOR = Color::LIGHT_GREEN;
    public const ERROR_COLOR = Color::LIGHT_RED;
    public const FATAL_COLOR = Color::RED;
    public const WARNING_COLOR = Color::YELLOW;
    public const INFO_COLOR = Color::WHITE;
    public const DEBUG_COLOR = Color::DARK_GRAY;
    public const MUTED_COLOR = Color::DARK_GRAY;

    public const SELECTED_ROW_SEQUENCE = "\033[30;101m";
    public const SELECTED_ROW_FOCUSED_SEQUENCE = "\033[5;30;101m";
    public const EDITING_SEQUENCE = "\033[30;43m";
    public const EDITING_FOCUSED_SEQUENCE = "\033[5;30;43m";
    public const SURFACE_SEQUENCE = "\033[97;100m";
    public const SURFACE_FOCUSED_SEQUENCE = "\033[5;97;100m";
    public const SUCCESS_SEQUENCE = "\033[30;42m";
    public const ERROR_SEQUENCE = "\033[30;41m";
    public const WARNING_SEQUENCE = "\033[30;43m";
    public const INFO_SEQUENCE = "\033[97;100m";

    private function __construct()
    {
    }
}

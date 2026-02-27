<?php

namespace Sendama\Console\Editor\Events\Enumerations;

enum EventType: string
{
    case EDITOR_STARTED = 'editor_started';
    case EDITOR_STOPPED = 'editor_stopped';
    case EDITOR_FINISHED = 'editor_finished';
    case EDITOR_STATE_CHANGED = 'editor_state_changed';
    case EDITOR_UPDATED = 'editor_updated';
    case EDITOR_RENDERED = 'frame_rendered';
    case EDITOR_INPUT_HANDLED = 'editor_input_handled';
    case KEYBOARD_INPUT = 'keyboard_input';
    case HIERARCHY_CHANGED = 'hierarchy_changed';
}

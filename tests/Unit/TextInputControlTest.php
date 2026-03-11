<?php

use Sendama\Console\Editor\Widgets\Controls\TextInputControl;

test('text input control edits text with cursor movement and backspace', function () {
    $control = new TextInputControl('Name', 'Player');

    expect($control->enterEditMode())->toBeTrue();
    expect($control->handleInput('1'))->toBeTrue();
    expect($control->moveCursorLeft())->toBeTrue();
    expect($control->handleInput('X'))->toBeTrue();
    expect($control->deleteBackward())->toBeTrue();
    expect($control->commitEdit())->toBeTrue();
    expect($control->getValue())->toBe('Player1');
});

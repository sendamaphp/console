<?php

use Atatusoft\Termutil\Events\MouseEvent;
use Sendama\Console\Editor\IO\Enumerations\KeyCode;
use Sendama\Console\Editor\IO\InputManager;

test('input manager normalizes shift tab sequences', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');

    expect($getKey->invoke(null, "\033[Z"))->toBe(KeyCode::SHIFT_TAB->value)
        ->and($getKey->invoke(null, "\033[1;2Z"))->toBe(KeyCode::SHIFT_TAB->value);
});

test('input manager normalizes shift up sequences', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');

    expect($getKey->invoke(null, "\033[1;2A"))->toBe(KeyCode::SHIFT_UP->value)
        ->and($getKey->invoke(null, "\033[a"))->toBe(KeyCode::SHIFT_UP->value);
});

test('input manager normalizes shift down sequences', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');

    expect($getKey->invoke(null, "\033[1;2B"))->toBe(KeyCode::SHIFT_DOWN->value)
        ->and($getKey->invoke(null, "\033[b"))->toBe(KeyCode::SHIFT_DOWN->value);
});

test('input manager normalizes shift right sequences', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');

    expect($getKey->invoke(null, "\033[1;2C"))->toBe(KeyCode::SHIFT_RIGHT->value)
        ->and($getKey->invoke(null, "\033[c"))->toBe(KeyCode::SHIFT_RIGHT->value);
});

test('input manager normalizes shift left sequences', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');

    expect($getKey->invoke(null, "\033[1;2D"))->toBe(KeyCode::SHIFT_LEFT->value)
        ->and($getKey->invoke(null, "\033[d"))->toBe(KeyCode::SHIFT_LEFT->value);
});

test('input manager preserves the shift+5 play toggle input', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');

    expect($getKey->invoke(null, '%'))->toBe(KeyCode::PLAY_TOGGLE->value);
});

test('input manager normalizes ctrl+c to the editor quit shortcut', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');

    expect($getKey->invoke(null, "\x03"))->toBe(KeyCode::CTRL_C->value);
});

test('input manager normalizes ctrl+s to the save shortcut', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');

    expect($getKey->invoke(null, "\x13"))->toBe(KeyCode::CTRL_S->value);
});

test('input manager normalizes ctrl+z to undo', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');

    expect($getKey->invoke(null, "\x1A"))->toBe(KeyCode::CTRL_Z->value);
});

test('input manager normalizes ctrl+y to redo', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');

    expect($getKey->invoke(null, "\x19"))->toBe(KeyCode::CTRL_Y->value);
});

test('input manager tokenizes multi-character printable input without dropping earlier characters', function () {
    $tokenizeInput = new ReflectionMethod(InputManager::class, 'tokenizeInput');

    expect($tokenizeInput->invoke(null, '02'))->toBe(['0', '2'])
        ->and($tokenizeInput->invoke(null, 'level02'))->toBe(['l', 'e', 'v', 'e', 'l', '0', '2']);
});

test('input manager preserves buffered 0 input instead of treating it as empty', function () {
    $normalizeBufferedInput = new ReflectionMethod(InputManager::class, 'normalizeBufferedInput');

    expect($normalizeBufferedInput->invoke(null, false))->toBe('')
        ->and($normalizeBufferedInput->invoke(null, '0'))->toBe('0')
        ->and($normalizeBufferedInput->invoke(null, 'level02'))->toBe('level02');
});

test('input manager tokenizes mixed escape sequences and printable characters', function () {
    $tokenizeInput = new ReflectionMethod(InputManager::class, 'tokenizeInput');

    expect($tokenizeInput->invoke(null, "\033[B0"))->toBe(["\033[B", '0']);
});

test('input manager coalesces repeated arrow tokens to avoid held-key drift', function () {
    $coalesceRepeatableTokens = new ReflectionMethod(InputManager::class, 'coalesceRepeatableTokens');

    expect($coalesceRepeatableTokens->invoke(null, ["\033[C", "\033[C", "\033[C"]))->toBe([
        "\033[C",
    ])
        ->and($coalesceRepeatableTokens->invoke(null, ["\033[C", "\033[D", "\033[D"]))->toBe([
            "\033[C",
            "\033[D",
        ])
        ->and($coalesceRepeatableTokens->invoke(null, ['0', '0']))->toBe(['0', '0']);
});

test('input manager treats repeated arrow input as both pressed and down', function () {
    $keyPress = new ReflectionProperty(InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(InputManager::class, 'previousKeyPress');
    $currentKeyPressWasBuffered = new ReflectionProperty(InputManager::class, 'currentKeyPressWasBuffered');
    $previousKeyPress->setValue("\033[C");
    $keyPress->setValue("\033[C");
    $currentKeyPressWasBuffered->setValue(true);

    expect(InputManager::isKeyPressed(KeyCode::RIGHT))->toBeTrue()
        ->and(InputManager::isKeyDown(KeyCode::RIGHT))->toBeTrue();
});

test('input manager still treats repeated non-repeatable input as not down', function () {
    $keyPress = new ReflectionProperty(InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(InputManager::class, 'previousKeyPress');
    $previousKeyPress->setValue("\n");
    $keyPress->setValue("\n");

    expect(InputManager::isKeyPressed(KeyCode::ENTER))->toBeTrue()
        ->and(InputManager::isKeyDown(KeyCode::ENTER))->toBeFalse();
});

test('input manager briefly holds repeatable arrows between raw repeat events', function () {
    $resolveCurrentKeyPress = new ReflectionMethod(InputManager::class, 'resolveCurrentKeyPress');
    $heldRepeatableKeyPress = new ReflectionProperty(InputManager::class, 'heldRepeatableKeyPress');
    $heldRepeatableKeySeenAt = new ReflectionProperty(InputManager::class, 'heldRepeatableKeySeenAt');

    $heldRepeatableKeyPress->setValue("\033[C");
    $heldRepeatableKeySeenAt->setValue(10.0);

    expect($resolveCurrentKeyPress->invoke(null, '', 10.04))->toBe("\033[C")
        ->and($resolveCurrentKeyPress->invoke(null, '', 10.06))->toBe('');
});

test('input manager does not treat held repeatable fallback frames as pressed or down', function () {
    $keyPress = new ReflectionProperty(InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(InputManager::class, 'previousKeyPress');
    $currentKeyPressWasBuffered = new ReflectionProperty(InputManager::class, 'currentKeyPressWasBuffered');
    $previousKeyPress->setValue("\033[C");
    $keyPress->setValue("\033[C");
    $currentKeyPressWasBuffered->setValue(false);

    expect(InputManager::isKeyPressed(KeyCode::RIGHT))->toBeFalse()
        ->and(InputManager::isKeyDown(KeyCode::RIGHT))->toBeFalse();
});

test('input manager distinguishes left button press from release for mouse focus workflows', function () {
    $mouseEvent = new ReflectionProperty(InputManager::class, 'mouseEvent');

    $mouseEvent->setValue(new MouseEvent("\033[<0;12;8M"));
    expect(InputManager::isLeftMouseButtonPressed())->toBeTrue();

    $mouseEvent->setValue(new MouseEvent("\033[<0;12;8m"));
    expect(InputManager::isLeftMouseButtonPressed())->toBeFalse();
});

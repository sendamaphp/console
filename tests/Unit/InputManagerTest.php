<?php

use Sendama\Console\Editor\IO\Enumerations\KeyCode;
use Sendama\Console\Editor\IO\InputManager;

test('input manager normalizes shift tab sequences', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');
    $getKey->setAccessible(true);

    expect($getKey->invoke(null, "\033[Z"))->toBe(KeyCode::SHIFT_TAB->value);
    expect($getKey->invoke(null, "\033[1;2Z"))->toBe(KeyCode::SHIFT_TAB->value);
});

test('input manager normalizes shift up sequences', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');
    $getKey->setAccessible(true);

    expect($getKey->invoke(null, "\033[1;2A"))->toBe(KeyCode::SHIFT_UP->value);
    expect($getKey->invoke(null, "\033[a"))->toBe(KeyCode::SHIFT_UP->value);
});

test('input manager normalizes shift down sequences', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');
    $getKey->setAccessible(true);

    expect($getKey->invoke(null, "\033[1;2B"))->toBe(KeyCode::SHIFT_DOWN->value);
    expect($getKey->invoke(null, "\033[b"))->toBe(KeyCode::SHIFT_DOWN->value);
});

test('input manager normalizes shift right sequences', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');
    $getKey->setAccessible(true);

    expect($getKey->invoke(null, "\033[1;2C"))->toBe(KeyCode::SHIFT_RIGHT->value);
    expect($getKey->invoke(null, "\033[c"))->toBe(KeyCode::SHIFT_RIGHT->value);
});

test('input manager normalizes shift left sequences', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');
    $getKey->setAccessible(true);

    expect($getKey->invoke(null, "\033[1;2D"))->toBe(KeyCode::SHIFT_LEFT->value);
    expect($getKey->invoke(null, "\033[d"))->toBe(KeyCode::SHIFT_LEFT->value);
});

test('input manager preserves the shift+5 play toggle input', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');
    $getKey->setAccessible(true);

    expect($getKey->invoke(null, '%'))->toBe(KeyCode::PLAY_TOGGLE->value);
});

test('input manager normalizes ctrl+c to the editor quit shortcut', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');
    $getKey->setAccessible(true);

    expect($getKey->invoke(null, "\x03"))->toBe(KeyCode::CTRL_C->value);
});

test('input manager normalizes ctrl+s to the save shortcut', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');
    $getKey->setAccessible(true);

    expect($getKey->invoke(null, "\x13"))->toBe(KeyCode::CTRL_S->value);
});

test('input manager normalizes ctrl+z to undo', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');
    $getKey->setAccessible(true);

    expect($getKey->invoke(null, "\x1A"))->toBe(KeyCode::CTRL_Z->value);
});

test('input manager normalizes ctrl+y to redo', function () {
    $getKey = new ReflectionMethod(InputManager::class, 'getKey');
    $getKey->setAccessible(true);

    expect($getKey->invoke(null, "\x19"))->toBe(KeyCode::CTRL_Y->value);
});

test('input manager tokenizes multi-character printable input without dropping earlier characters', function () {
    $tokenizeInput = new ReflectionMethod(InputManager::class, 'tokenizeInput');
    $tokenizeInput->setAccessible(true);

    expect($tokenizeInput->invoke(null, '02'))->toBe(['0', '2']);
    expect($tokenizeInput->invoke(null, 'level02'))->toBe(['l', 'e', 'v', 'e', 'l', '0', '2']);
});

test('input manager tokenizes mixed escape sequences and printable characters', function () {
    $tokenizeInput = new ReflectionMethod(InputManager::class, 'tokenizeInput');
    $tokenizeInput->setAccessible(true);

    expect($tokenizeInput->invoke(null, "\033[B0"))->toBe(["\033[B", '0']);
});

test('input manager coalesces repeated arrow tokens to avoid held-key drift', function () {
    $coalesceRepeatableTokens = new ReflectionMethod(InputManager::class, 'coalesceRepeatableTokens');
    $coalesceRepeatableTokens->setAccessible(true);

    expect($coalesceRepeatableTokens->invoke(null, ["\033[C", "\033[C", "\033[C"]))->toBe([
        "\033[C",
    ]);
    expect($coalesceRepeatableTokens->invoke(null, ["\033[C", "\033[D", "\033[D"]))->toBe([
        "\033[C",
        "\033[D",
    ]);
    expect($coalesceRepeatableTokens->invoke(null, ['0', '0']))->toBe(['0', '0']);
});

test('input manager treats repeated arrow input as pressed', function () {
    $keyPress = new ReflectionProperty(InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(InputManager::class, 'previousKeyPress');
    $keyPress->setAccessible(true);
    $previousKeyPress->setAccessible(true);
    $previousKeyPress->setValue("\033[C");
    $keyPress->setValue("\033[C");

    expect(InputManager::isKeyPressed(KeyCode::RIGHT))->toBeTrue();
    expect(InputManager::isKeyDown(KeyCode::RIGHT))->toBeFalse();
});

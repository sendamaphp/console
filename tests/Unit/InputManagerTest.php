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

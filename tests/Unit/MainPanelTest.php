<?php

use Atatusoft\Termutil\IO\Enumerations\Color;
use Sendama\Console\Editor\Widgets\MainPanel;
use Sendama\Console\Editor\Widgets\Widget;

test('main panel cycles forward through tabs', function () {
    $panel = new MainPanel(width: 60, height: 12);

    expect($panel->getActiveTab())->toBe('Scene');

    $panel->activateNextTab();
    expect($panel->getActiveTab())->toBe('Game');

    $panel->activateNextTab();
    expect($panel->getActiveTab())->toBe('Sprite');

    $panel->activateNextTab();
    expect($panel->getActiveTab())->toBe('Scene');
});

test('main panel cycles backward through tabs', function () {
    $panel = new MainPanel(width: 60, height: 12);

    $panel->activatePreviousTab();

    expect($panel->getActiveTab())->toBe('Sprite');
});

test('main panel uses focus cycling to move between tabs', function () {
    $panel = new MainPanel(width: 60, height: 12);

    expect($panel->cycleFocusForward())->toBeTrue();
    expect($panel->getActiveTab())->toBe('Game');

    expect($panel->cycleFocusBackward())->toBeTrue();
    expect($panel->getActiveTab())->toBe('Scene');
});

test('main panel shows a play prompt on the game tab while not in play mode', function () {
    $panel = new MainPanel(width: 60, height: 12);

    $panel->selectTab('Game');

    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'Shift+5 to Play')))->toBeTrue();
});

test('main panel hides the play prompt while play mode is active', function () {
    $panel = new MainPanel(width: 60, height: 12);

    $panel->selectTab('Game');
    $panel->setPlayModeActive(true);

    expect(array_any($panel->content, fn(string $line) => str_contains($line, 'Shift+5 to Play')))->toBeFalse();
    expect($panel->content)->toHaveCount(2);
});

test('main panel uses a warm focus color while in play mode', function () {
    $panel = new MainPanel(width: 60, height: 12);
    $focusBorderColor = new ReflectionProperty(Widget::class, 'focusBorderColor');
    $focusBorderColor->setAccessible(true);

    expect($focusBorderColor->getValue($panel))->toBe(Color::LIGHT_CYAN);

    $panel->setPlayModeActive(true);

    expect($focusBorderColor->getValue($panel))->toBe(Color::BROWN);
});

test('main panel highlights the active tab in the divider', function () {
    $panel = new MainPanel(width: 60, height: 12);

    $panel->selectTab('Sprite');

    expect($panel->content[0])->toContain('Scene  Game  Sprite');
    expect($panel->content[1])->toContain('■■■■■■');
    expect(mb_strlen($panel->content[1]))->toBe($panel->innerWidth - 2);
});

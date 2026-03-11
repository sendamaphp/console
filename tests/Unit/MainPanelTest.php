<?php

use Sendama\Console\Editor\Widgets\MainPanel;

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

test('main panel highlights the active tab in the divider', function () {
    $panel = new MainPanel(width: 60, height: 12);

    $panel->selectTab('Sprite');

    expect($panel->content[0])->toContain('Scene  Game  Sprite');
    expect($panel->content[1])->toContain('■■■■■■');
    expect(mb_strlen($panel->content[1]))->toBe($panel->innerWidth - 2);
});

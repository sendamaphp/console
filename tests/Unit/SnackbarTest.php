<?php

use Sendama\Console\Editor\Widgets\Snackbar;

test('snackbar slides in, stays visible, and slides out', function () {
    $snackbar = new Snackbar();
    $snackbar->syncLayout(80, 24);
    $snackbar->enqueue('Saved scene level01.scene.php', 'success', 0.5);

    expect($snackbar->hasActiveNotice())->toBeTrue();
    expect($snackbar->y)->toBe(-2);

    $initialY = $snackbar->y;
    $initialX = $snackbar->x;
    $snackbar->update();

    expect($snackbar->y)->toBeGreaterThan($initialY);

    for ($index = 0; $index < 20; $index++) {
        $snackbar->update();
    }

    $width = new ReflectionProperty(Snackbar::class, 'width');
    $expectedCenteredX = intdiv(80 - $width->getValue($snackbar), 2) + 1;

    expect($snackbar->x)->toBe($expectedCenteredX)
        ->and($snackbar->y)->toBe(1);

    $visibleUntil = new ReflectionProperty(Snackbar::class, 'visibleUntil');
    $phase = new ReflectionProperty(Snackbar::class, 'phase');
    $visibleUntil->setValue($snackbar, microtime(true) - 1);

    $snackbar->update();

    expect($phase->getValue($snackbar))->toBe('exiting');

    for ($index = 0; $index < 30; $index++) {
        $snackbar->update();
    }

    expect($snackbar->hasActiveNotice())->toBeFalse();
});

test('snackbar renders status titles and colorized content', function () {
    $snackbar = new Snackbar();
    $snackbar->syncLayout(80, 24);
    $snackbar->enqueue('Failed to save scene.', 'error', 1.0);

    for ($index = 0; $index < 20; $index++) {
        $snackbar->update();
    }

    ob_start();
    $snackbar->renderAt();
    $output = ob_get_clean();

    expect($output)->toContain('Error')
        ->and($output)->toContain('Failed to save scene.')
        ->and($output)->toContain("\033[30;41m");
});

test('snackbar renders partially while sliding into view', function () {
    $snackbar = new Snackbar();
    $snackbar->syncLayout(80, 24);
    $snackbar->enqueue('Saved scene level01.scene.php', 'success', 1.0);

    $snackbar->update();
    $snackbar->update();

    expect($snackbar->y)->toBe(0);

    ob_start();
    $snackbar->renderAt();
    $output = ob_get_clean();

    expect($output)->toContain('Saved scene level01.scene.php')
        ->toContain("\033[30;42m");
});

test('snackbar does not render while fully off-screen above the viewport', function () {
    $snackbar = new Snackbar();
    $snackbar->syncLayout(80, 24);
    $snackbar->enqueue('Saved scene level01.scene.php', 'success', 1.0);

    ob_start();
    $snackbar->renderAt();
    $output = ob_get_clean();

    expect($output)->toBe('');
});

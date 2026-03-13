<?php

use Sendama\Console\Editor\Widgets\OptionListModal;

test('option list modal scrolls long option lists to keep the selected item visible', function () {
    $modal = new OptionListModal();
    $scrollOffset = new ReflectionProperty(OptionListModal::class, 'scrollOffset');
    $scrollOffset->setAccessible(true);

    $modal->show(array_map(
        static fn(int $index): string => 'Option ' . $index,
        range(1, 12),
    ));
    $modal->syncLayout(28, 8);
    $modal->moveSelection(5);

    expect($modal->getSelectedOption())->toBe('Option 6');
    expect($scrollOffset->getValue($modal))->toBeGreaterThan(0);
    expect(array_any($modal->content, fn(string $line) => str_contains($line, '> Option 6')))->toBeTrue();
    expect(array_any($modal->content, fn(string $line) => str_contains($line, 'Option 1')))->toBeFalse();
});

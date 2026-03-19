<?php

use Atatusoft\Termutil\Events\MouseEvent;
use Sendama\Console\Editor\Widgets\OptionListModal;

function getOptionListModalContentAreaPosition(OptionListModal $modal): array
{
    $getContentAreaLeft = new ReflectionMethod($modal, 'getContentAreaLeft');
    $getContentAreaTop = new ReflectionMethod($modal, 'getContentAreaTop');

    return [
        'x' => $getContentAreaLeft->invoke($modal),
        'y' => $getContentAreaTop->invoke($modal),
    ];
}

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

test('option list modal selects an option when it is clicked', function () {
    $modal = new OptionListModal();
    $modal->show(['Alpha', 'Beta', 'Gamma']);
    $modal->syncLayout(28, 8);
    $contentArea = getOptionListModalContentAreaPosition($modal);

    $selection = $modal->clickOptionAtPoint($contentArea['x'] + 1, $contentArea['y'] + 1);

    expect($selection)->toBe('Beta');
    expect($modal->getSelectedOption())->toBe('Beta');
});

test('option list modal renders a scrollbar when the list overflows', function () {
    $modal = new OptionListModal();
    $modal->show(array_map(
        static fn (int $index): string => 'Option ' . $index,
        range(1, 12),
    ));
    $modal->syncLayout(28, 8);

    $buildRenderedContentLines = new ReflectionMethod($modal, 'buildRenderedContentLines');
    $buildRenderedContentLines->setAccessible(true);
    $lines = $buildRenderedContentLines->invoke($modal);

    expect(array_any($lines, static fn (string $line): bool => str_contains($line, '█') || str_contains($line, '░')))->toBeTrue();
});

test('option list modal scrollbars are draggable without changing the selected option', function () {
    $modal = new OptionListModal();
    $modal->show(array_map(
        static fn (int $index): string => 'Option ' . $index,
        range(1, 20),
    ));
    $modal->syncLayout(28, 8);

    $contentArea = getOptionListModalContentAreaPosition($modal);
    $scrollbarX = $modal->x + $modal->innerWidth;
    $dragStartY = $contentArea['y'];
    $dragEndY = $contentArea['y'] + 4;

    $modal->handleScrollbarMouseEvent(new MouseEvent("\033[<0;{$scrollbarX};{$dragStartY}M"));
    $modal->handleScrollbarMouseEvent(new MouseEvent("\033[<32;{$scrollbarX};{$dragEndY}M"));
    $modal->handleScrollbarMouseEvent(new MouseEvent("\033[<0;{$scrollbarX};{$dragEndY}m"));

    $scrollOffset = new ReflectionProperty(OptionListModal::class, 'scrollOffset');
    $scrollOffset->setAccessible(true);

    expect($modal->getSelectedOption())->toBe('Option 1')
        ->and($scrollOffset->getValue($modal))->toBeGreaterThan(0);
});

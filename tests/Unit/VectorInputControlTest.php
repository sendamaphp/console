<?php

use Sendama\Console\Editor\Widgets\Controls\VectorInputControl;

test('vector input control selects and edits nested numeric properties', function () {
    $control = new VectorInputControl('Position', ['x' => 4, 'y' => 12]);

    expect($control->beginPropertySelection())->toBeTrue();
    expect($control->enterSelectedPropertyEdit())->toBeTrue();
    expect($control->handleInput('6'))->toBeTrue();
    expect($control->increment())->toBeTrue();
    expect($control->commitActiveEdit())->toBeTrue();
    expect($control->movePropertySelection(1))->toBeTrue();
    expect($control->enterSelectedPropertyEdit())->toBeTrue();
    expect($control->decrement())->toBeTrue();
    expect($control->commitActiveEdit())->toBeTrue();
    expect($control->renderLines())->toBe([
        '  Position:',
        '    X: 47',
        '    Y: 11',
    ]);
});

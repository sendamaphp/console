<?php

use Sendama\Console\Editor\Widgets\Controls\SliderInputControl;

it('wraps long slider labels onto a second line so the track stays visible', function () {
    $control = new SliderInputControl('Max Spawn Distance', 100, 0, 100);
    $control->setAvailableWidth(28);

    expect($control->renderLines())->toBe([
        '  Max Spawn Distance: 100',
        '    [############]',
    ]);
});

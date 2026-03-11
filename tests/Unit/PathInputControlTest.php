<?php

use Sendama\Console\Editor\Widgets\Controls\PathInputControl;

test('path input control keeps paths relative to its working directory', function () {
    $control = new PathInputControl('Texture', 'Textures/player', '/tmp/project/Assets');

    $control->setValueFromRelativePath('Textures\\enemy.texture');

    expect($control->getWorkingDirectory())->toBe('/tmp/project/Assets');
    expect($control->getValue())->toBe('Textures/enemy.texture');
});

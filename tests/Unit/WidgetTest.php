<?php

use Sendama\Console\Editor\Widgets\Widget;

test('widget can store and resolve directional siblings', function () {
    $hierarchy = new class extends Widget {
        public function __construct()
        {
            parent::__construct('Hierarchy', '', ['x' => 1, 'y' => 1], 20, 10);
        }

        public function update(): void
        {
        }
    };
    $main = new class extends Widget {
        public function __construct()
        {
            parent::__construct('Main', '', ['x' => 22, 'y' => 1], 40, 10);
        }

        public function update(): void
        {
        }
    };
    $assets = new class extends Widget {
        public function __construct()
        {
            parent::__construct('Assets', '', ['x' => 1, 'y' => 12], 20, 10);
        }

        public function update(): void
        {
        }
    };

    $hierarchy->setSiblings(
        top: null,
        right: $main,
        bottom: $assets,
        left: null,
    );

    expect($hierarchy->getSibling('top'))->toBeNull();
    expect($hierarchy->getSibling('right'))->toBe($main);
    expect($hierarchy->getSibling('bottom'))->toBe($assets);
    expect($hierarchy->getSibling('left'))->toBeNull();
});

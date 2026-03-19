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

test('widget safely renders long border labels in narrow windows', function () {
    $widget = new class extends Widget {
        public function __construct()
        {
            parent::__construct(
                'Very Long Title',
                'This help text is far too long for the window',
                ['x' => 1, 'y' => 1],
                12,
                5,
            );
            $this->content = ['content'];
        }

        public function update(): void
        {
        }
    };

    ob_start();
    $widget->renderAt();
    $output = ob_get_clean();

    expect($output)->not->toBeFalse();
    expect($output)->toBeString();
    expect($output)->not->toBe('');
});

test('widget clips long content lines to the available window width', function () {
    $widget = new class extends Widget {
        public function __construct()
        {
            parent::__construct('Inspector', '', ['x' => 1, 'y' => 1], 20, 6);
            $this->content = ['Texture: Textures/bullet.texture'];
        }

        public function update(): void
        {
        }
    };

    $buildRenderedContentLines = new ReflectionMethod($widget, 'buildRenderedContentLines');
    $lines = $buildRenderedContentLines->invoke($widget);

    expect($lines)->toHaveCount(4)
        ->and($lines[0])->toBe('│ Texture: Texture │')
        ->and(mb_strlen($lines[0]))->toBe(20)
        ->and(mb_substr($lines[0], -1))->toBe('│');
});

test('widget keeps borders intact when content contains wide multibyte glyphs', function () {
    $widget = new class extends Widget {
        public function __construct()
        {
            parent::__construct('Scene', '', ['x' => 1, 'y' => 1], 12, 6);
            $this->content = ['  👾   x'];
        }

        public function update(): void
        {
        }
    };

    $buildRenderedContentLines = new ReflectionMethod($widget, 'buildRenderedContentLines');
    $lines = $buildRenderedContentLines->invoke($widget);

    expect($lines)->toHaveCount(4)
        ->and(mb_strwidth($lines[0], 'UTF-8'))->toBe(12)
        ->and(mb_substr($lines[0], -1))->toBe('│');
});

test('widget scrolls overflowing content and renders a scrollbar thumb', function () {
    $widget = new class extends Widget {
        public function __construct()
        {
            parent::__construct('Inspector', '', ['x' => 1, 'y' => 1], 20, 6);
            $this->content = ['Line 1', 'Line 2', 'Line 3', 'Line 4', 'Line 5', 'Line 6'];
        }

        public function revealContentLine(int $contentIndex): void
        {
            $this->ensureContentLineVisible($contentIndex);
        }

        public function update(): void
        {
        }
    };

    $buildRenderedContentLines = new ReflectionMethod($widget, 'buildRenderedContentLines');
    $widget->revealContentLine(5);
    $lines = $buildRenderedContentLines->invoke($widget);

    expect($lines)->toHaveCount(4)
        ->and(array_any($lines, static fn (string $line): bool => str_contains($line, 'Line 6')))->toBeTrue()
        ->and(array_any($lines, static fn (string $line): bool => str_contains($line, '█') || str_contains($line, '░')))->toBeTrue();
});

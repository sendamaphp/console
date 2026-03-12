<?php

use Sendama\Console\Editor\DTOs\HierarchyObjectDTO;

test('hierarchy object dto builds game objects from arrays', function () {
    $dto = HierarchyObjectDTO::fromArray([
        'type' => 'Sendama\\Engine\\Core\\GameObject',
        'name' => 'Player',
        'tag' => 'Player',
        'position' => ['x' => 4, 'y' => 12],
        'rotation' => ['x' => 0, 'y' => 0],
        'scale' => ['x' => 1, 'y' => 1],
        'sprite' => [
            'texture' => [
                'path' => 'Textures/player',
            ],
        ],
        'components' => [
            ['class' => 'PlayerController'],
        ],
    ]);

    expect($dto)->toBeInstanceOf(HierarchyObjectDTO::class);
    expect($dto->type)->toBe('Sendama\\Engine\\Core\\GameObject');
    expect($dto->name)->toBe('Player');
    expect($dto->rotation)->toBe(['x' => 0, 'y' => 0]);
    expect($dto->scale)->toBe(['x' => 1, 'y' => 1]);
    expect($dto->size)->toBeNull();
    expect($dto->components)->toBe([
        ['class' => 'PlayerController'],
    ]);
});

test('hierarchy object dto builds ui elements from arrays', function () {
    $dto = HierarchyObjectDTO::fromArray([
        'type' => 'Sendama\\Engine\\UI\\Label\\Label',
        'name' => 'Score',
        'position' => ['x' => 4, 'y' => 1],
        'size' => ['x' => 10, 'y' => 1],
        'text' => 'Score: 000',
    ]);

    expect($dto)->toBeInstanceOf(HierarchyObjectDTO::class);
    expect($dto->type)->toBe('Sendama\\Engine\\UI\\Label\\Label');
    expect($dto->name)->toBe('Score');
    expect($dto->position)->toBe(['x' => 4, 'y' => 1]);
    expect($dto->size)->toBe(['x' => 10, 'y' => 1]);
    expect($dto->rotation)->toBeNull();
    expect($dto->scale)->toBeNull();
    expect($dto->text)->toBe('Score: 000');
    expect($dto->__serialize())->not->toHaveKey('rotation');
    expect($dto->__serialize())->not->toHaveKey('scale');
});

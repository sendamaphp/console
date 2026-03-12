<?php

use Sendama\Console\Editor\Widgets\FileDialogModal;

test('file dialog modal returns a relative path for the selected file', function () {
    $workspace = sys_get_temp_dir() . '/sendama-file-dialog-' . uniqid();
    mkdir($workspace . '/Assets/Textures', 0777, true);
    file_put_contents($workspace . '/Assets/Textures/player.texture', 'texture');

    $modal = new FileDialogModal();
    $modal->show($workspace . '/Assets');

    expect($modal->content[0])->toBe('► Textures');

    $modal->expandSelection();
    $modal->moveSelection(1);

    expect($modal->content[1])->toBe('  • player.texture');
    expect($modal->submitSelection())->toBe('Textures/player.texture');
});

test('file dialog modal can preselect the current relative path', function () {
    $workspace = sys_get_temp_dir() . '/sendama-file-dialog-' . uniqid();
    mkdir($workspace . '/Assets/Textures', 0777, true);
    file_put_contents($workspace . '/Assets/Textures/player.texture', 'texture');

    $modal = new FileDialogModal();
    $modal->show($workspace . '/Assets', 'Textures/player.texture');

    expect($modal->content[0])->toBe('▼ Textures');
    expect($modal->content[1])->toBe('  • player.texture');
    expect($modal->submitSelection())->toBe('Textures/player.texture');
});

test('file dialog modal does not expand directories when submitting', function () {
    $workspace = sys_get_temp_dir() . '/sendama-file-dialog-' . uniqid();
    mkdir($workspace . '/Assets/Textures', 0777, true);
    file_put_contents($workspace . '/Assets/Textures/player.texture', 'texture');

    $modal = new FileDialogModal();
    $modal->show($workspace . '/Assets');

    expect($modal->content)->toBe(['► Textures']);
    expect($modal->submitSelection())->toBeNull();
    expect($modal->content)->toBe(['► Textures']);
});

test('file dialog modal tracks dirty state across changes', function () {
    $workspace = sys_get_temp_dir() . '/sendama-file-dialog-' . uniqid();
    mkdir($workspace . '/Assets/Textures', 0777, true);
    file_put_contents($workspace . '/Assets/Textures/player.texture', 'texture');

    $modal = new FileDialogModal();
    $modal->show($workspace . '/Assets');

    expect($modal->isDirty())->toBeTrue();

    $modal->markClean();

    expect($modal->isDirty())->toBeFalse();

    $modal->expandSelection();

    expect($modal->isDirty())->toBeTrue();
});

test('file dialog modal filters files and directories by allowed extensions', function () {
    $workspace = sys_get_temp_dir() . '/sendama-file-dialog-' . uniqid();
    mkdir($workspace . '/Assets/Textures', 0777, true);
    mkdir($workspace . '/Assets/Maps', 0777, true);
    file_put_contents($workspace . '/Assets/Textures/player.texture', 'texture');
    file_put_contents($workspace . '/Assets/Maps/level.tmap', 'tile map');
    file_put_contents($workspace . '/Assets/notes.txt', 'notes');

    $modal = new FileDialogModal();
    $modal->show($workspace . '/Assets', allowedExtensions: ['texture']);

    expect($modal->content)->toBe(['► Textures']);

    $modal->expandSelection();

    expect($modal->content)->toBe([
        '▼ Textures',
        '  • player.texture',
    ]);
});

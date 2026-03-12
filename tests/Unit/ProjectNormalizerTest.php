<?php

use Sendama\Console\Util\ProjectNormalizer;

test('project normalizer reports missing project structure discrepancies', function () {
    $workspace = sys_get_temp_dir() . '/sendama-project-normalizer-' . uniqid();
    mkdir($workspace, 0777, true);

    $issues = (new ProjectNormalizer($workspace))->inspect();

    expect($issues)->toContain('Missing sendama.json.');
    expect($issues)->toContain('Missing configuration.json.');
    expect($issues)->toContain('Missing config/input.php.');
    expect($issues)->toContain('Missing logs/debug.log.');
    expect($issues)->toContain('Missing logs/error.log.');
    expect($issues)->toContain('Missing Assets/Scenes directory.');
    expect($issues)->toContain('Missing Assets/Scripts directory.');
});

test('project normalizer creates missing structure while respecting legacy lowercase assets roots', function () {
    $workspace = sys_get_temp_dir() . '/sendama-project-normalizer-legacy-' . uniqid();
    mkdir($workspace, 0777, true);
    mkdir($workspace . '/assets', 0777, true);

    file_put_contents($workspace . '/sendama.json', json_encode([
        'name' => 'Legacy Test Game',
        'description' => 'Legacy description',
        'version' => '1.2.3',
        'main' => 'legacy.php',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $changes = (new ProjectNormalizer($workspace))->normalize();

    expect($changes)->toContain('Created configuration.json.');
    expect($changes)->toContain('Created config/input.php.');
    expect($changes)->toContain('Created logs/debug.log.');
    expect($changes)->toContain('Created logs/error.log.');
    expect($changes)->toContain('Created assets/Scenes directory.');
    expect(is_file($workspace . '/configuration.json'))->toBeTrue();
    expect(is_file($workspace . '/config/input.php'))->toBeTrue();
    expect(is_file($workspace . '/logs/debug.log'))->toBeTrue();
    expect(is_file($workspace . '/logs/error.log'))->toBeTrue();
    expect(is_dir($workspace . '/assets/Scenes'))->toBeTrue();
    expect(is_dir($workspace . '/assets/Scripts'))->toBeTrue();
    expect(is_dir($workspace . '/Assets'))->toBeFalse();

    $configuration = json_decode(file_get_contents($workspace . '/configuration.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($configuration['project']['name'])->toBe('Legacy Test Game');
    expect($configuration['project']['main'])->toBe('legacy.php');
});

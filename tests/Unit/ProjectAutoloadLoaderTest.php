<?php

use Sendama\Console\Editor\ProjectAutoloadLoader;

it('suppresses duplicate constant warnings while loading a project autoloader', function () {
    expect(defined('DEFAULT_DIALOG_WIDTH'))->toBeTrue()
        ->and(defined('DEFAULT_DIALOG_HEIGHT'))->toBeTrue();

    $autoloadPath = tempnam(sys_get_temp_dir(), 'sendama-autoload-');

    expect($autoloadPath)->not->toBeFalse();

    $fixtureClassName = 'ProjectAutoloadLoaderFixture' . bin2hex(random_bytes(4));

    file_put_contents(
        $autoloadPath,
        "<?php\nconst DEFAULT_DIALOG_WIDTH = 50;\nconst DEFAULT_DIALOG_HEIGHT = 3;\nclass {$fixtureClassName} {}\n"
    );

    set_error_handler(static function (int $errno, string $errstr, string $errfile = '', int $errline = 0): never {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    });

    try {
        ProjectAutoloadLoader::load($autoloadPath);
    } finally {
        restore_error_handler();
        @unlink($autoloadPath);
    }

    expect(class_exists($fixtureClassName, false))->toBeTrue();
});

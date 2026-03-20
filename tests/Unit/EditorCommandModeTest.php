<?php

use Sendama\Console\Editor\Editor;
use Sendama\Console\Editor\Widgets\CommandHelpModal;
use Sendama\Console\Editor\Widgets\CommandLineModal;
use Sendama\Console\Editor\Widgets\PanelListModal;
use Sendama\Console\Editor\Widgets\Snackbar;

function setEditorInput(string $current, string $previous = ''): void
{
    $keyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'keyPress');
    $previousKeyPress = new ReflectionProperty(\Sendama\Console\Editor\IO\InputManager::class, 'previousKeyPress');
    $keyPress->setAccessible(true);
    $previousKeyPress->setAccessible(true);
    $previousKeyPress->setValue($previous);
    $keyPress->setValue($current);
}

function createBareEditorForCommandMode(): array
{
    $reflection = new ReflectionClass(Editor::class);
    $editor = $reflection->newInstanceWithoutConstructor();

    $reflection->getProperty('panelListModal')->setValue($editor, new PanelListModal());
    $reflection->getProperty('commandLineModal')->setValue($editor, new CommandLineModal());
    $reflection->getProperty('commandHelpModal')->setValue($editor, new CommandHelpModal());
    $reflection->getProperty('snackbar')->setValue($editor, new Snackbar());
    $reflection->getProperty('terminalWidth')->setValue($editor, 120);
    $reflection->getProperty('terminalHeight')->setValue($editor, 40);
    $reflection->getProperty('shouldRefreshBackgroundUnderModal')->setValue($editor, false);

    return [$reflection, $editor];
}

test('editor opens command mode when colon is pressed', function () {
    [$reflection, $editor] = createBareEditorForCommandMode();
    $commandLineModal = $reflection->getProperty('commandLineModal');
    $commandLineModal->setAccessible(true);
    $handlePanelKeyboardWorkflow = $reflection->getMethod('handlePanelKeyboardWorkflow');
    $handlePanelKeyboardWorkflow->setAccessible(true);

    setEditorInput(':');
    $handlePanelKeyboardWorkflow->invoke($editor);

    /** @var CommandLineModal $modal */
    $modal = $commandLineModal->getValue($editor);

    expect($modal->isVisible())->toBeTrue()
        ->and($modal->getInput())->toBe('');
});

test('editor command mode opens the help cheatsheet when help is entered', function () {
    [$reflection, $editor] = createBareEditorForCommandMode();
    $commandLineModal = $reflection->getProperty('commandLineModal');
    $commandHelpModal = $reflection->getProperty('commandHelpModal');
    $commandLineModal->setAccessible(true);
    $commandHelpModal->setAccessible(true);
    $handlePanelKeyboardWorkflow = $reflection->getMethod('handlePanelKeyboardWorkflow');
    $handlePanelKeyboardWorkflow->setAccessible(true);

    setEditorInput(':');
    $handlePanelKeyboardWorkflow->invoke($editor);

    foreach (str_split('help') as $character) {
        setEditorInput($character);
        $handlePanelKeyboardWorkflow->invoke($editor);
    }

    setEditorInput('enter');
    $handlePanelKeyboardWorkflow->invoke($editor);

    /** @var CommandLineModal $lineModal */
    $lineModal = $commandLineModal->getValue($editor);
    /** @var CommandHelpModal $helpModal */
    $helpModal = $commandHelpModal->getValue($editor);

    expect($lineModal->isVisible())->toBeFalse()
        ->and($helpModal->isVisible())->toBeTrue()
        ->and($helpModal->content)->toContain('Type :help to open this cheatsheet.')
        ->and($helpModal->content)->toContain('  Ctrl+S save the current scene');
});

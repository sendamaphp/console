<?php

use Sendama\Console\Editor\Editor;
use Sendama\Console\Editor\Widgets\ConsolePanel;

test('renaming a script asset updates its class declaration', function () {
    $workspace = sys_get_temp_dir() . '/sendama-editor-script-rename-' . uniqid();
    mkdir($workspace . '/Assets/Scripts', 0777, true);
    $scriptPath = $workspace . '/Assets/Scripts/PlayerController.php';

    file_put_contents($scriptPath, <<<'PHP'
<?php

namespace Tmp\Game\Scripts;

use Sendama\Engine\Core\Behaviours\Behaviour;

class PlayerController extends Behaviour
{
}
PHP);

    $editorReflection = new ReflectionClass(Editor::class);
    $editor = $editorReflection->newInstanceWithoutConstructor();

    $workingDirectory = $editorReflection->getProperty('workingDirectory');
    $assetsDirectoryPath = $editorReflection->getProperty('assetsDirectoryPath');
    $consolePanel = $editorReflection->getProperty('consolePanel');
    $workingDirectory->setAccessible(true);
    $assetsDirectoryPath->setAccessible(true);
    $consolePanel->setAccessible(true);
    $workingDirectory->setValue($editor, $workspace);
    $assetsDirectoryPath->setValue($editor, $workspace . '/Assets');
    $consolePanel->setValue($editor, new ConsolePanel());

    $renameMethod = $editorReflection->getMethod('renameAssetAndCascadeReferences');
    $renameMethod->setAccessible(true);

    $renamedAsset = $renameMethod->invoke(
        $editor,
        $scriptPath,
        'Scripts/PlayerController.php',
        'EnemyController.php',
    );

    expect($renamedAsset)->toBe([
        'name' => 'EnemyController.php',
        'path' => $workspace . '/Assets/Scripts/EnemyController.php',
        'relativePath' => 'Scripts/EnemyController.php',
        'isDirectory' => false,
        'children' => [],
    ]);
    expect(file_exists($scriptPath))->toBeFalse();
    expect(is_file($workspace . '/Assets/Scripts/EnemyController.php'))->toBeTrue();
    expect(file_get_contents($workspace . '/Assets/Scripts/EnemyController.php'))
        ->toContain('class EnemyController extends Behaviour');
});

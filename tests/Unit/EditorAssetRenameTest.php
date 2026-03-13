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

test('renaming an event asset updates its class declaration', function () {
    $workspace = sys_get_temp_dir() . '/sendama-editor-event-rename-' . uniqid();
    mkdir($workspace . '/Assets/Events', 0777, true);
    $eventPath = $workspace . '/Assets/Events/PlayerDiedEvent.php';

    file_put_contents($eventPath, <<<'PHP'
<?php

namespace Tmp\Game\Events;

use Sendama\Engine\Events\Event;

readonly class PlayerDiedEvent extends Event
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
        $eventPath,
        'Events/PlayerDiedEvent.php',
        'EnemyDiedEvent.php',
    );

    expect($renamedAsset)->toBe([
        'name' => 'EnemyDiedEvent.php',
        'path' => $workspace . '/Assets/Events/EnemyDiedEvent.php',
        'relativePath' => 'Events/EnemyDiedEvent.php',
        'isDirectory' => false,
        'children' => [],
    ]);
    expect(file_exists($eventPath))->toBeFalse();
    expect(is_file($workspace . '/Assets/Events/EnemyDiedEvent.php'))->toBeTrue();
    expect(file_get_contents($workspace . '/Assets/Events/EnemyDiedEvent.php'))
        ->toContain('class EnemyDiedEvent extends Event');
});

test('renaming a prefab asset preserves the .prefab.php suffix', function () {
    $workspace = sys_get_temp_dir() . '/sendama-editor-prefab-rename-' . uniqid();
    mkdir($workspace . '/Assets/Prefabs', 0777, true);
    $prefabPath = $workspace . '/Assets/Prefabs/enemy.prefab.php';

    file_put_contents($prefabPath, <<<'PHP'
<?php

return [
    'type' => \Sendama\Engine\Core\GameObject::class,
    'name' => 'Enemy',
];
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
        $prefabPath,
        'Prefabs/enemy.prefab.php',
        'boss',
    );

    expect($renamedAsset)->toBe([
        'name' => 'boss.prefab.php',
        'path' => $workspace . '/Assets/Prefabs/boss.prefab.php',
        'relativePath' => 'Prefabs/boss.prefab.php',
        'isDirectory' => false,
        'children' => [],
    ]);
    expect(file_exists($prefabPath))->toBeFalse();
    expect(is_file($workspace . '/Assets/Prefabs/boss.prefab.php'))->toBeTrue();
});

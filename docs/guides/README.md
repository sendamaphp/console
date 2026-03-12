# Sendama Editor Guides

This guide set explains how the current Sendama editor works in practice so you can use it to build scenes, draw assets, tune properties, and iterate on your game quickly.

## Start Here

Read these guides in order if you are learning the editor for the first time:

1. [Getting Started](getting-started.md)
2. [Layout and Navigation](layout-and-navigation.md)
3. [Building Scenes](building-scenes.md)
4. [Working with Assets](working-with-assets.md)
5. [Inspector and Properties](inspector-and-properties.md)
6. [Playtest and Debug](playtest-and-debug.md)
7. [Reference](reference.md)

## What The Editor Can Do Today

- Open a Sendama project and load its active scene.
- Browse the `Assets` tree.
- Inspect scene roots, scene objects, and file assets.
- Add top-level `GameObject`, `Text`, and `Label` entries to the active scene.
- Select visible objects directly in the Scene tab and move them with the keyboard.
- Edit scene, transform, renderer, text, and serialized component fields in the Inspector.
- Add components to hierarchy objects from the Inspector.
- Create scripts, scenes, textures, tile maps, and events from the Assets panel.
- Create, edit, rename, and delete `.texture` and `.tmap` assets.
- Save the active scene back to its `.scene.php` source file.
- Watch project logs from inside the editor.

## What Still Happens Outside The Editor

The editor is best used as part of a hybrid workflow. You will still use the CLI or code for some tasks:

- Writing the PHP logic inside generated scripts, events, and engine classes.
- Removing or reordering components after they have been added.
- Reparenting hierarchy objects or creating new child objects under an existing object.
- Running the full game runtime in a dedicated session.

For those tasks, the usual companion commands are:

```bash
sendama generate:scene level01
sendama generate:script PlayerController
sendama play
```

## Recommended Build Loop

Use the editor in this order when building a level or game screen:

1. Open the project with `sendama edit`.
2. Set the scene size and background tile map.
3. Add scene objects from the Hierarchy panel.
4. Assign textures, text, and components in the Inspector.
5. Draw or update textures and tile maps in the Sprite tab.
6. Save the scene with `Ctrl+S`.
7. Check logs in the Console panel and run the game with `sendama play` when you need a full playthrough.

## A Few Important Rules To Remember

- Scene edits are not written to disk until you press `Ctrl+S`.
- Sprite and tile map edits are written to disk immediately.
- File renames happen immediately, but you should save the scene afterward if that scene references the renamed asset.
- Asset deletions happen immediately and can leave broken references behind if the deleted file was still in use.

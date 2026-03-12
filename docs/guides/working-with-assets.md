# Working with Assets

This guide explains how the editor handles the asset tree, how the `Sprite` tab works, and what to watch out for when renaming or deleting files.

## The Assets Panel

The `Assets` panel is a tree browser rooted at the active project asset root.

By default that root is `Assets`. Legacy lowercase `assets` projects are still supported.

Controls:

| Key | Action |
| --- | --- |
| `Up` / `Down` | Move selection |
| `Right` | Expand a folder or move into its children |
| `Left` | Collapse a folder or move to its parent |
| `Enter` | Inspect the selected folder or file |
| `Shift+A` | Open the asset create modal |
| `Delete` | Open the delete confirmation dialog |

What inspection does:

- folders open in the `Inspector` as `Folder`
- files open in the `Inspector` as `File`
- `.texture` and `.tmap` files also load into `Main -> Sprite`

## Sprite Tab Overview

The `Sprite` tab is the editor's character-grid workspace for:

- `.texture` files
- `.tmap` files

How loading works:

- select a `.texture` or `.tmap` in `Assets`
- press `Enter`
- the file opens in `Inspector`
- the same file loads into `Sprite`

## Creating New Assets

When the `Assets` panel has focus, press `Shift+A`.

Current create options:

- `Script`
- `Scene`
- `Texture`
- `Tile Map`
- `Event`

Behavior:

- the editor runs the matching Sendama generator command in the opened project directory
- files are created in the active project asset root
- the editor picks the next available default name for that asset family
- the new file is selected in `Assets` and loaded into the `Inspector`

Default name families:

- scripts: `new-script-1`, `new-script-2`, and so on
- scenes: `new-scene-1`, `new-scene-2`, and so on
- textures: `new-texture-1`, `new-texture-2`, and so on
- tile maps: `new-map-1`, `new-map-2`, and so on
- events: `new-event-1`, `new-event-2`, and so on

If the created asset is a `.texture` or `.tmap`, the editor also loads it into `Main -> Sprite`.

## Quick Create From The Sprite Tab

There is also a faster create path for art assets.

When `Main` is focused and the `Sprite` tab is active, press `Shift+A` to create:

- `Texture`
- `Tile Map`

This quick-create flow:

- is limited to sprite-editable asset types
- creates the file immediately under the active asset root's `Textures` or `Maps` directory
- opens the new asset directly in the sprite editor

## Sprite Editing Controls

Once a `.texture` or `.tmap` is loaded, these controls are active:

| Key | Action |
| --- | --- |
| `Up` / `Right` / `Down` / `Left` | Move the cursor |
| printable character | Draw that character |
| `Space` | Place a blank character |
| `Backspace` | Erase the current cell |
| `Shift+2` | Open the special-character picker |
| `Ctrl+Z` | Undo |
| `Ctrl+Y` | Redo |
| `Shift+R` | Reset to the state from when the asset was opened |
| `Delete` | Delete the active asset after confirmation |

The help line in the Main panel shows the live cursor position as `Col x Row`.

## Character Picker

Press `Shift+2` to open the curated character list. This is useful for common ASCII art building blocks such as:

- blocks and shades
- triangles and arrows
- corners and line pieces
- circles, squares, hearts, and stars

Use:

- `Up` / `Down` to choose a character
- `Enter` to insert it at the cursor
- `Escape` to cancel

## How Paths Work In The Renderer

Renderer texture paths and environment tile map paths are relative asset paths.

Common examples:

- `Textures/player`
- `Textures/player.texture`
- `Maps/level`
- `Maps/level.tmap`

The editor's renderer accepts both extensionless and extensionful paths. If you choose a file through the Inspector file picker, it writes the relative path it selected.

## Renaming Assets

File assets can be renamed from the `Inspector` by editing their `Name` field.

Behavior to know:

- folder names are read-only in the current Inspector UI
- renaming preserves the current file extension
- if the current scene references that file through `sprite.texture.path` or `environmentTileMapPath`, those in-memory scene references are updated
- renaming a script file also rewrites the PHP class declaration inside that file to match the new filename

Very important:

- the file rename happens immediately
- the scene reference update is only in memory until you save the scene

So the safe rename workflow is:

1. rename the asset in `Inspector`
2. confirm the scene still looks correct
3. press `Ctrl+S`

## Deleting Assets

There are two delete entry points:

- press `Delete` in `Assets`
- press `Delete` in `Sprite` while an asset is loaded

Deletion behavior:

- the delete happens immediately after confirmation
- deleting from `Sprite` also clears the loaded sprite editor view
- folder deletion is recursive
- deleting an asset does not automatically repair broken scene references

That means you should delete carefully, especially from shared folders.

## Best Practices For Asset Work

- Keep textures under `Assets/Textures` and maps under `Assets/Maps`.
- Prefer the canonical uppercase `Assets` root in new projects.
- Save scene changes after renaming assets that are already in use.
- Use small texture crops in renderer settings instead of duplicating large texture files.
- Build backgrounds as tile maps first, then place scene objects over them.

Once your asset exists, the next step is usually wiring it into an object through [Inspector and Properties](inspector-and-properties.md).

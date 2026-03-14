# Sendama Editor Manual

This document is a living guide to the Sendama editor.

It is meant to track the editor as it exists today, including current hotkeys, panel workflows, and known behavior. Update this file whenever the editor gains new tools, panels, controls, or shortcuts.

For the task-oriented guide set, start with [docs/guides/README.md](guides/README.md).

## Starting the Editor

Open the editor from inside a Sendama project:

```bash
sendama edit
```

Or point it at a project directory explicitly:

```bash
sendama edit --directory /path/to/project
```

The editor expects a valid Sendama project workspace. In particular:

- editor settings and project settings must be present
- the project should contain an `Assets` folder, or a legacy lowercase `assets` folder
- the active scene is loaded from the configured scene metadata

When the editor opens a project, it also runs a startup sanity check.
If it finds missing structure such as `config/input.php`, `preferences.json`, log files, or required asset directories, it opens a normalize prompt:

- `Normalize`: create the missing directories and bootstrap files
- `Cancel` or `Escape`: continue without changing the project

## Layout Overview

The editor currently uses five main panels:

- `Hierarchy`: scene tree and scene object management
- `Assets`: project browser rooted at the active asset root, preferring `Assets` but compatible with legacy `assets`
- `Main`: workspace area with `Scene`, `Game`, and `Sprite` tabs
- `Console`: project log view
- `Inspector`: object and asset details, plus property editing

## Global Shortcuts

These shortcuts work regardless of the currently focused panel unless a modal is open.

| Key | Action |
| --- | --- |
| `Shift+Up` | Move focus to the panel above the current one, if a sibling exists |
| `Shift+Right` | Move focus to the panel on the right, if a sibling exists |
| `Shift+Down` | Move focus to the panel below the current one, if a sibling exists |
| `Shift+Left` | Move focus to the panel on the left, if a sibling exists |
| `Shift+1` | Open the panel list modal |
| `Shift+5` | Toggle play mode globally |
| `Ctrl+C` | Close the editor gracefully |
| `Ctrl+S` | Save the loaded scene |

`Shift+A` is panel-local:

- in `Hierarchy`, it opens the add-object workflow
- in `Assets`, it opens the create-asset workflow
- in `Inspector`, it opens the add-component menu when a hierarchy object is loaded

`Shift+E` is also panel-local:

- in `Hierarchy`, it exports the selected object as a prefab into `Assets/Prefabs`, expands that folder, selects the new prefab, and opens it in the `Inspector`
- in `Main > Scene`, it enters Pan Mode

## Panel List Modal

Press `Shift+1` to open a modal listing all panels.

Controls:

- `Up` / `Down`: move selection
- `Enter`: focus the selected panel
- `Escape`: close the modal

## Main Panel

The main panel has three tabs:

- `Scene`
- `Game`
- `Sprite`

Controls:

- `Tab`: cycle to the next tab
- `Shift+Tab`: cycle to the previous tab

### Scene Tab

When the `Scene` tab is active, the main panel renders the current scene graph using each object's stored position.

Current scene rendering behavior:

- objects are drawn at their `position`
- sprite-backed objects render character data from their `.texture` files
- the scene's `environmentTileMapPath` is rendered as a static background layer and is not selectable
- sprite rendering uses:
  - `sprite.texture.path`
  - `sprite.texture.position`
  - `sprite.texture.size`
- texture paths are resolved relative to the editor's configured project directory
- scene coordinates are rendered into a scrollable viewport
- UI text objects render their `text`
- selected objects without a visible representation render as a muted `x`
- the main panel help line shows the current scene controls on the left and the active mode on the right

When the main panel has focus and the `Scene` tab is active, it uses scene-view modes.

#### Scene View Modes

| Key | Mode |
| --- | --- |
| `Shift+Q` | Select Mode |
| `Shift+W` | Move Mode |
| `Shift+E` | Pan Mode |

#### Select Mode

Use Select Mode to move between visible scene objects without changing them.
If the selected object has no renderable sprite or text, Scene View shows a muted `x` at its transform position.

Controls:

- `Up` / `Left`: select the previous visible scene object
- `Down` / `Right`: select the next visible scene object
- changing the selection immediately syncs the Inspector and Hierarchy to the selected object
- `Enter`: reload the selected object into the Inspector
- clicking a visible scene object selects it
- double-clicking a visible scene object activates it like `Enter`

#### Move Mode

Use Move Mode to reposition the currently selected scene object.

Controls:

- `Up`: decrement `transform.position.y`
- `Right`: increment `transform.position.x`
- `Down`: increment `transform.position.y`
- `Left`: decrement `transform.position.x`

Moving an object updates `transform.position` and marks the scene dirty.
If the moved object is loaded in the Inspector, its transform values update immediately as it moves.

#### Pan Mode

Use Pan Mode to scroll the visible scene viewport when the scene is larger than the panel.

Controls:

- `Up`: pan the view upward
- `Right`: pan the view to the right
- `Down`: pan the view downward
- `Left`: pan the view to the left

### Game Tab

When the `Game` tab is selected and the editor is not in play mode:

- the panel shows a shaded idle view
- the centered prompt reads `Shift+5 to Play`

When play mode is entered:

- focus immediately shifts to the `Main` panel
- the `Game` tab becomes active
- the main panel focus border changes to a warmer play-mode color

### Sprite Tab

The `Sprite` tab is the asset grid editor for `.texture` and `.tmap` files.

Current behavior:

- selecting a `.texture` or `.tmap` file in `Assets` loads it into the `Sprite` tab
- the editor works on a character grid backed directly by the selected file
- the visible canvas is only the editable grid itself; asset metadata is shown in the Inspector
- textures load into an editable area that can grow up to `16x16`
- tile maps open and create at the current terminal-size bounds
- the right side of the main-panel help line shows the live cursor position as `Col x Row`
- edits are written to the asset file immediately

Controls:

- `Up` / `Right` / `Down` / `Left`: move the sprite cursor
- type any printable character: draw that character at the cursor
- `Shift+2`: open the character selector modal for special characters
- `Space`: place a blank character
- `Backspace`: erase the current cell
- `Ctrl+Z`: undo the last grid change
- `Ctrl+Y`: redo the last undone grid change
- `Shift+R`: reset the loaded asset back to the state it had when it was opened
- `Delete`: open the delete-asset confirmation modal
- left-clicking a cell paints with the current brush
- left-click-dragging paints continuously across the grid
- right-clicking or right-click-dragging erases without changing the current brush

Delete workflow:

- `Delete` opens a confirmation modal for the currently loaded asset
- confirming deletes the file and clears the Sprite editor view

Character selector workflow:

- `Shift+2` opens a modal of curated special characters useful for sprites and maps
- list-based modals and file pickers support mouse selection
- `Up` / `Down`: move selection
- `Enter`: insert the selected character at the current cursor position
- `Escape`: close the modal without inserting anything

## Hierarchy Panel

The hierarchy shows the loaded scene as a tree.

Current structure:

- the scene name is the root node
- a dirty scene is shown as `<SceneName>*`
- child scene objects appear under the scene root
- selecting the scene root and pressing `Enter` loads the scene details into the Inspector

Controls:

- `Up` / `Down`: move selection
- `Right`: expand a collapsed node, or move into its children
- `Left`: collapse an expanded node, or move to its parent
- `Enter`: load the selected object into the Inspector
- `Shift+A`: open the add-object workflow
- `Shift+E`: create a prefab from the selected object and open it in the Inspector
- `Delete`: open the delete confirmation dialog

Selected rows are highlighted, and when the hierarchy has focus the selected row blinks.

### Add Object Workflow

Press `Shift+A` to add a new scene object while the editor is in edit mode.

Press `Shift+E` on a selected hierarchy object to export that object to a `.prefab.php` file under `Assets/Prefabs`. The editor expands the `Prefabs` folder in `Assets`, selects the new prefab, and moves focus to the `Inspector`.

Flow:

1. Choose `GameObject` or `UIElement`
2. If `UIElement` is selected, choose a concrete type

Currently supported UI element types:

- `Text`
- `Label`

Default names use this format:

- `<Type> #<Instance Count>`

Examples:

- `GameObject #1`
- `Label #2`

### Delete Workflow

Press `Delete` on a selected hierarchy object to open a confirmation modal:

```text
Are you sure you want to delete <object_name>?
```

Controls:

- `Up` / `Down`: choose `Delete` or `Cancel`
- `Enter`: confirm the selection
- `Escape`: cancel

## Assets Panel

The Assets panel is a tree view rooted at the project's `Assets` directory.

Controls:

- `Up` / `Down`: move selection
- `Right`: expand a folder, or move into it
- `Left`: collapse a folder, or move to its parent
- `Enter`: load the selected asset into the Inspector
- `Shift+A`: open the asset create workflow
- `Delete`: open the delete confirmation dialog

Inspector type mapping:

- directories are shown as `Folder`
- files are shown as `File`
- selecting a `.texture` or `.tmap` also opens `Main -> Sprite` and moves focus there

### Asset Delete Workflow

Press `Delete` on a selected asset to open a confirmation modal:

```text
Are you sure you want to delete <asset_name>?
```

Controls:

- `Up` / `Down`: choose `Delete` or `Cancel`
- `Enter`: confirm the selection
- `Escape`: cancel

### Asset Create Workflow

Press `Shift+A` while the Assets panel has focus to open the create modal.

Current create targets:

- `Script`
- `Scene`
- `Prefab`
- `Texture`
- `Tile Map`
- `Event`

Behavior:

- selecting an asset type runs the corresponding Sendama generator command in the opened project directory
- the editor creates the asset with the next available default name for that asset family
- after creation, the Assets tree refreshes, the new asset is selected, and the Inspector loads it
- if the created asset is a texture or tile map, the Sprite tab loads it too
- prefab assets are created as `.prefab.php` metadata files under `Assets/Prefabs`
- prefab metadata returns a single array shaped like one scene `hierarchy` entry, so it can describe either a `GameObject` or a UI element such as `Label` or `Text`
- pressing `Enter` on a selected prefab loads it into the `Inspector` using the same object-style layout as a hierarchy object
- prefab inspection keeps `File Name` separate from `Name`, so the prefab file can be renamed independently from the object name stored in the metadata

## Inspector Panel

The Inspector shows details for the currently inspected target.

Current target sources:

- Hierarchy selection
- Scene tab selection
- Assets selection

For file assets, the Inspector currently shows:

- `Type`
- editable `Name`
- read-only `Path`

Renaming a file asset from the Inspector renames the file on disk. If the current scene references that file through `sprite.texture.path` or `environmentTileMapPath`, those scene references are updated in memory and should be saved with `Ctrl+S`.
If the renamed asset is a PHP class-backed file under `Assets/Scripts` or `Assets/Events`, the editor also rewrites the class declaration inside the source file to match the new filename.

### Inspector Hotkeys

When the Inspector has focus:

- `Tab`: move to the next control
- `Shift+Tab`: move to the previous control
- `Shift+A`: open the add-component menu for the currently inspected hierarchy object
- `Shift+W`: enter or leave component move mode when a component header is selected
- `Delete`: open the remove-component confirmation modal when a component header is selected

The Inspector help line updates dynamically to show the active controls on the left and the current mode on the right.

The Inspector uses a small state machine.

### Inspector States

#### 1. Control Selection

This is the default state when the Inspector gains focus.

Controls:

- `Up` / `Down`: move between controls
- `Enter`: activate the selected control
- double-clicking a control activates it too
- `Shift+A`: open the add-component menu when a hierarchy object is being inspected
- `Shift+W`: toggle component move mode when a component header is focused
- `Delete`: open the remove-component confirmation modal when a component header is focused
- `/`: toggle the focused collapsible section, such as `Transform`, `Renderer`, or a component block

#### 2. Property Selection

Used for compound controls such as vectors.

Examples:

- `Position`
- `Rotation`
- `Scale`
- renderer `Offset`
- renderer `Size`

Controls:

- `Up` / `Down`: move between sub-properties
- `Enter`: edit the selected sub-property
- `Escape`: return to Control Selection

#### 3. Control Edit

Used when editing a concrete value.

##### Text Input

Controls:

- type letters, numbers, and symbols to edit text
- `Backspace`: delete backward
- `Left` / `Right`: move the cursor
- `Enter`: commit the value
- `Escape`: cancel the edit

##### Number Input

Controls:

- type numbers directly
- `Up`: increment
- `Down`: decrement
- `Left` / `Right`: move the cursor when applicable
- `Enter`: commit the value
- `Escape`: cancel the edit

##### Prefab Reference

For exposed component fields typed as `GameObject`, `Enter` opens a prefab picker instead of entering text edit.

Controls:

- `Up` / `Down`: choose a prefab
- `Enter`: assign the selected prefab
- `Escape`: cancel

The stored value is the prefab asset path, for example `Prefabs/enemy.prefab.php`. When the scene metadata is loaded again, the Inspector resolves that path back to the referenced prefab.

### Current Hierarchy Inspection Layout

For hierarchy objects, the Inspector currently renders:

1. Global properties:
   - `Type`
   - `Name`
   - `Tag`
2. Built-in sections:
   - `Transform`
   - `Renderer`
3. Script/component sections from the scene metadata

Component headers are visually marked as collapsible sections.

Exposed `GameObject` component fields are treated as prefab references:

- pressing `Enter` on the field opens the prefab picker
- choosing a prefab stores its relative prefab path in component `data`
- the control displays the referenced prefab name when that metadata is loaded again

### Add Component Workflow

When the Inspector is focused on a hierarchy object other than the scene root, press `Shift+A` to open `Add Component`.

Current component candidates come from:

- built-in engine component defaults
- PHP classes discovered under `Assets/Scripts`
- component classes already present in the loaded scene

Behavior:

- selecting a component appends it to the object's `components` list
- if the editor can discover serializable default data for that component, it adds that data immediately
- the new component section appears in the Inspector right away

### Component Remove Workflow

When a component header is focused, press `Delete` to open the remove confirmation modal.

Behavior:

- the modal asks whether to remove the selected component from the current object
- `Delete`: confirm removal
- `Cancel` or `Escape`: abort removal
- confirming removes that component from the object's `components` list immediately

### Component Reorder Workflow

When a component header is focused, press `Shift+W` to enter component move mode.

Behavior:

- `Up`: move the selected component one slot earlier, wrapping to the end from the first slot
- `Down`: move the selected component one slot later, wrapping to the start from the last slot
- `Escape`: leave move mode

Current limit:

- component reordering is currently driven from the component header itself, not from nested component fields

### Renderer Section

The renderer reads from the object's `sprite` metadata.

Current fields:

- `Texture`
- `Offset`
- `Size`
- `Preview`

The preview is cropped from the texture file using the same texture path, offset, and size information used by the scene tab for sprite-backed scene rendering.
Texture paths are resolved relative to the project working directory that was used to open the editor.

### Path Input Workflow

Path-based controls, such as renderer texture paths, behave differently from normal text inputs.

Pressing `Enter` on a path input first opens a modal with:

- `Choose file`
- `Edit path`

#### Choose File

Opens a file tree dialog rooted at the control's working directory.

When a control specifies allowed extensions, the dialog limits visible files to matching extensions and hides directories that do not contain any matching files.

Controls:

- `Up` / `Down`: move selection
- `Right`: expand a folder, or move into it
- `Left`: collapse a folder, or move to its parent
- `Enter`: select the highlighted file
- `Escape`: cancel or go back

Submitting a file writes the path back as a path relative to the configured working directory.

#### Edit Path

Enters normal text input editing for the path field.

Submitting either path mode returns the Inspector to control-selection state.

## Console Panel

The Console panel currently reads from:

```text
<project_root>/logs/debug.log
<project_root>/logs/error.log
```

Current behavior:

- it has two tabs:
  - `Debug`: reads from `logs/debug.log` if it exists
  - `Error`: reads from `logs/error.log` if it exists
- on editor startup each tab loads the last three lines from its own log file
- log display is clipped to the console viewport
- it only reads a tab's log file if that file exists
- it auto-refreshes the console tabs from disk every `editor.console.refreshInterval` seconds while the editor is in Play Mode
- when the console has focus and the editor is not in play mode, it supports scrolling
- if no refresh interval is configured, the editor uses a default of `5` seconds
- each tab can be filtered by log level with a modal picker
- the `Debug` tab defaults to the `DEBUG` filter on startup

Filter options:

- `Debug`: `ALL`, `DEBUG`, `INFO`, `WARN`, `ERROR`
- `Error`: `ALL`, `ERROR`, `CRITICAL`, `FATAL`

Controls:

- `Tab`: switch to the next console tab
- `Shift+Tab`: switch to the previous console tab
- `Up`: scroll up through older log lines
- `Down`: scroll down through newer log lines
- `Shift+R`: manually refresh the active log tab from disk and jump to the newest visible lines
- `Shift+F`: open the log-level filter modal for the active console tab
- `Shift+C`: open a confirm modal to rotate and clear the active log file

Clear behavior:

- on confirm, the active log file is copied to the next rotated file such as `debug.log.1`
- after rotation, the active log file is cleared
- on cancel, nothing changes and the Console panel returns to its normal state

## Notifications

Current behavior:

- the editor now has a top-right snackbar for transient system notifications
- it slides in from the right, stays visible for the configured duration, then slides back out to the right
- status colors currently map as follows:
  - `success`: green
  - `error`: red
  - `info`: blue
  - `warn`: yellow
- scene save success and failure currently use the snackbar

The scroll stops:

- at the beginning of the file
- at the point where the last log line is at the top of the viewport

Current tag colors:

- `[ERROR]`: red
- `[WARN]`: yellow
- `[INFO]`: blue
- `[DEBUG]`: light gray

Configuration:

```json
{
  "editor": {
    "console": {
      "refreshInterval": 5
    },
    "notifications": {
      "duration": 4
    }
  }
}
```

## Saving

Press `Ctrl+S` to save the loaded scene.

Current save behavior:

- inspector edits are written back to the loaded scene model
- scene-view moves are written back to the loaded scene model
- hierarchy additions and deletions update the loaded scene model
- the dirty marker on the scene root clears after a successful save

## Notes

- the editor is currently keyboard-first
- panels and modals are designed to restore the previous view cleanly when dismissed
- this document should be updated whenever a new shortcut, panel workflow, or modal flow is added

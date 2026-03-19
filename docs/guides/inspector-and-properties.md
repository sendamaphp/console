# Inspector and Properties

The `Inspector` is where most precise editing happens. It turns the current selection into editable controls and pushes the result back into the loaded scene or selected asset.

## What Can Be Inspected

The `Inspector` can load targets from three places:

- `Hierarchy`
- `Scene`
- `Assets`

That gives you three main editing modes:

- scene settings
- object and component settings
- file asset metadata

## Inspector Navigation Model

The Inspector uses three interaction states.

### 1. Control Selection

This is the default state.

Controls:

- `Up` / `Down`: move between controls
- `Enter`: activate the selected control
- `Shift+A`: open the add-component menu when a hierarchy object or prefab is being inspected
- `Shift+W`: enter or leave component move mode when a component header is selected
- `/`: collapse or expand the selected section header
- `Tab` / `Shift+Tab`: move forward or backward through focusable controls

### 2. Property Selection

This state appears for compound controls such as vectors.

Examples:

- `Position`
- `Rotation`
- `Scale`
- renderer `Offset`
- renderer `Size`
- UI `Size`

Controls:

- `Up` / `Down`: move between sub-properties
- `Enter`: edit the selected property
- `Escape`: return to control selection

### 3. Control Edit

This state edits a concrete value.

Common edit rules:

- `Enter`: commit
- `Escape`: cancel
- `Backspace`: delete backward when supported
- `Left` / `Right`: move the cursor when supported
- `Up` / `Down`: increment or decrement when supported

## Scene Controls

When the scene root is inspected, the current editable fields are:

- `Name`
- `Width`
- `Height`
- `Environment Tile Map`

Practical use:

- rename the scene before saving if you want a new scene filename
- resize the scene before you place objects
- point `Environment Tile Map` at the map file you want rendered behind the scene

## Object Controls

When a hierarchy object is inspected, the Inspector renders these groups.

### Global Properties

- `Type`
- `Name`
- `Tag`

### Transform

- `Position`
- `Rotation`
- `Scale`
- `Size` when the object type exposes it

### Renderer

- `Texture`
- `Offset`
- `Size`
- `Preview`
- `Text` when the object includes a text field

### Components

Each serialized component becomes its own collapsible section. The section title is the class name without the namespace.

If the component exposes serialized data, the Inspector renders typed controls for it.

If a serialized component field is typed as `GameObject`, the Inspector treats it as a prefab reference instead of plain text.

- focus the field
- press `Enter` to open the prefab picker
- choose a prefab from `Assets/Prefabs`
- the saved value becomes that prefab's relative path, for example `Prefabs/enemy.prefab.php`

When the scene is loaded again, the Inspector resolves that saved path back to the referenced prefab so the field stays readable and editable.

### Add Component Menu

When the Inspector is showing a hierarchy object or prefab other than the scene root, press `Shift+A` to open `Add Component`.

The menu can pull candidates from:

- built-in engine component defaults
- PHP classes discovered under `Assets/Scripts`
- component classes already present in the loaded scene

When you choose a component:

- it is appended to the object's `components` array
- any serializable default data the editor can discover is added immediately
- the new section appears in the Inspector right away

## Removing And Reordering Components

Component headers are interactive controls.

To remove a component:

- focus the component header
- press `Delete`
- confirm removal in the modal

To reorder components:

- focus the component header you want to move
- press `Shift+W` to enter component move mode
- press `Up` or `Down` to move that component through the list with wraparound
- press `Escape` or `Shift+W` again to leave move mode

## Asset Controls

When a regular file asset is inspected, the Inspector renders:

- `Type`
- editable `Name`
- read-only `Path`

If the file is a PHP class-backed asset under `Assets/Scripts` or `Assets/Events`, renaming it in the Inspector also updates the class declaration inside the source file to match the new filename.

When a prefab asset is activated from `Assets`, the Inspector switches to object-style editing instead of the plain file view.

Prefab inspection keeps these concerns separate:

- `File Name` renames the prefab file on disk
- `Name` changes the object name stored inside the prefab metadata
- other fields and components edit the prefab's serialized object data

Prefab field edits and component changes are written back to the `.prefab.php` file immediately.

When a folder is inspected, the Inspector renders:

- `Type`
- read-only `Name`
- read-only `Path`

## Supported Control Types

The current control factory maps values to controls like this:

- booleans -> checkbox controls such as `[x]`
- integers and floats -> number inputs
- vector-like arrays such as `{x, y}` -> vector inputs
- flat scalar option lists -> select controls
- everything else -> text inputs

That means existing serialized component data can already be quite useful in the Inspector, even if you authored the component in code.

## Path Inputs

Path fields use a two-step workflow.

Press `Enter` on a path field and you will see:

- `Choose file`
- `Edit path`

### Choose File

This opens a file tree rooted at the field's working directory.

Examples:

- texture fields filter to `.texture`
- environment map fields filter to `.tmap`

The dialog hides folders that do not contain matching files, which keeps large projects easier to browse.

Controls:

- `Up` / `Down`
- `Right` / `Left`
- `Enter`
- `Escape`

### Edit Path

This drops into normal text editing so you can type the path yourself.

## Preview Window

Renderer previews use the same texture path, crop offset, and crop size that the Scene tab uses for sprite rendering.

That makes the preview good for verifying:

- you picked the right texture
- the crop rectangle is correct
- the sprite will show what you expect in Scene view

## Editing Tips

These habits make the Inspector much easier to use:

- select visually in `Scene`, then fine-tune numerically in `Inspector`
- collapse sections you are not working on with `/`
- use the file picker for paths when possible to avoid typos
- save after scene-level edits so your in-memory changes become persistent

## Current Limits

The Inspector edits what already exists in the loaded data model. It does not currently provide UI for:

- changing hierarchy parenting
- creating new nested child objects

Use [Reference](reference.md) for the full control map and persistence rules.

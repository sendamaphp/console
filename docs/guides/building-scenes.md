# Building Scenes

This guide covers the main scene-building loop: define the scene, create objects, place them visually, and save the result back to the scene file.

## The Scene Build Loop

A reliable workflow looks like this:

1. Inspect the scene root from `Hierarchy`.
2. Set scene dimensions and the environment tile map.
3. Add scene objects from `Hierarchy`.
4. Edit object properties and add components in `Inspector`.
5. Place visible objects in the `Scene` tab.
6. Save with `Ctrl+S`.

## Editing The Scene Root

In `Hierarchy`, the scene root is the top row. Press `Enter` on it to load the scene into the `Inspector`.

The scene inspector currently exposes:

- `Type`
- `Name`
- `Width`
- `Height`
- `Environment Tile Map`

What these fields do:

- `Name`: becomes the scene file name the next time you save
- `Width` and `Height`: control the canvas size used in Scene view
- `Environment Tile Map`: sets the background map rendered behind scene objects

Important detail:

- changing the scene name does not rename the file immediately
- the rename happens when you press `Ctrl+S`

## Setting The Background Map

The scene background comes from `environmentTileMapPath`.

Best practice:

- create or choose a `.tmap` file in `Assets/Maps`
- assign it through the scene root's `Environment Tile Map` field
- keep the map close to your scene dimensions so panning stays readable

The background map:

- renders behind scene objects
- is not selectable in the Scene tab
- is resolved relative to the project and asset directories

## Adding Objects

Move focus to `Hierarchy` and press `Shift+A`.

The add flow currently supports:

1. `GameObject`
2. `UIElement`

If you choose `UIElement`, you can then choose:

- `Text`
- `Label`

Default objects are created with starter values:

### `GameObject`

- `name`: `GameObject #<n>`
- `tag`: `None`
- `position`: `0,0`
- `rotation`: `0,0`
- `scale`: `1,1`
- `components`: empty

### `Text` and `Label`

- `name`: `<Type> #<n>`
- `tag`: `UI`
- `position`: `0,0`
- `size`: `1,1`
- `text`: same as the object name

Current limitation:

- new objects are added at the scene root
- there is no UI for reparenting or inserting them under an existing object yet

## Selecting Objects

You can select objects in two places:

- `Hierarchy`
- `Main -> Scene`

When you select a visible object in `Scene`, the selection syncs back to the `Hierarchy` and `Inspector`.

This is useful for a two-step workflow:

1. select an object visually
2. edit the exact numbers in `Inspector`

## Scene Tab Modes

The Scene tab has three interaction modes:

| Key | Mode |
| --- | --- |
| `Shift+Q` | Select |
| `Shift+W` | Move |
| `Shift+E` | Pan |

### Select Mode

Use Select mode to move through visible scene objects.

Controls:

- `Up` / `Left`: previous visible object
- `Down` / `Right`: next visible object
- `Enter`: reload the selected object in `Inspector`

Notes:

- selection only cycles through objects that have something visible to render
- UI text objects render their `text`
- sprite-backed objects render the cropped part of their `.texture` file

### Move Mode

Use Move mode to place the currently selected object.

Controls:

- `Up`: decrease `position.y`
- `Right`: increase `position.x`
- `Down`: increase `position.y`
- `Left`: decrease `position.x`

Moving an object:

- updates the loaded scene in memory
- marks the scene dirty
- updates the Inspector immediately if that object is currently loaded there

### Pan Mode

Use Pan mode when the scene is bigger than the visible viewport.

Controls:

- `Up`
- `Right`
- `Down`
- `Left`

Panning changes the viewport only. It does not change object data.

## Editing Object Details

Once an object is selected, use `Inspector` to edit it. The current object workflow supports:

- renaming
- retagging
- changing transform values
- changing `size` for UI elements that expose it
- changing renderer texture path, offset, and size
- editing visible text on text-based UI objects
- editing serialized component data that is already present in the scene metadata
- appending new components from the Inspector add-component menu

### Adding Components

When a hierarchy object is loaded in `Inspector`, press `Shift+A` to open `Add Component`.

Current component candidates come from:

- built-in engine component defaults
- project scripts under `Assets/Scripts`
- component classes already present on the current object or elsewhere in the loaded scene

Selecting a component:

- appends it to the object's `components` list
- loads any serializable default data the editor can discover
- immediately refreshes the Inspector so you can keep editing the new component

Components can also be managed after they are added:

- focus a component header and press `Delete` to open the remove confirmation dialog
- focus a component header and press `Shift+W` to enter component move mode
- while move mode is active, use `Up` / `Down` to reorder the selected component with wraparound
- press `Escape` or `Shift+W` again to leave move mode

For a full breakdown, continue with [Inspector and Properties](inspector-and-properties.md).

## Saving

Press `Ctrl+S` to write the active scene back to disk.

Save includes:

- scene root changes
- object property edits
- scene-view moves
- hierarchy additions
- hierarchy deletions

After a successful save:

- the dirty marker disappears from the scene root
- the scene source file is updated
- a renamed scene is written to its new `.scene.php` filename

## Example Workflow: Build A Small Level

Here is a practical level-building sequence:

1. Open the scene root and set `Width` and `Height`.
2. Assign a background tile map such as `Maps/level`.
3. Add a `GameObject` for the player.
4. Assign a texture to its renderer and set its crop rectangle.
5. In `Inspector`, press `Shift+A` to add a controller or movement component.
6. Add a `Label` for score or health.
7. Switch to `Scene`, enter Move mode, and place the player and UI elements.
8. Press `Ctrl+S`.

If your scene also depends on new textures or a new map, build those next in [Working with Assets](working-with-assets.md).

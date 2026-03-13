# Reference

This page gathers the most useful shortcuts, file locations, persistence rules, and current editor limits in one place.

## Global Shortcuts

| Key | Action |
| --- | --- |
| `Shift+Up` | Focus panel above |
| `Shift+Right` | Focus panel to the right |
| `Shift+Down` | Focus panel below |
| `Shift+Left` | Focus panel to the left |
| `Shift+1` | Open panel list |
| `Shift+5` | Toggle play state |
| `Shift+A` | Panel-local create action in `Hierarchy`, `Assets`, and `Inspector` |
| `Ctrl+S` | Save the loaded scene |
| `Ctrl+C` | Close the editor |

## Hierarchy Panel

| Key | Action |
| --- | --- |
| `Up` / `Down` | Move selection |
| `Right` | Expand node or move into children |
| `Left` | Collapse node or move to parent |
| `Enter` | Inspect selection |
| `Shift+A` | Add object |
| `Delete` | Delete selected object |

Add-object types:

- `GameObject`
- `UIElement -> Text`
- `UIElement -> Label`

## Assets Panel

| Key | Action |
| --- | --- |
| `Up` / `Down` | Move selection |
| `Right` | Expand folder or move into children |
| `Left` | Collapse folder or move to parent |
| `Enter` | Inspect file or folder, or load a prefab into the object-style Inspector view |
| `Shift+A` | Create asset from the Assets create menu |
| `Delete` | Delete selected asset |

Create targets:

- `Script`
- `Scene`
- `Prefab`
- `Texture`
- `Tile Map`
- `Event`

## Main Panel

### Tabs

| Key | Action |
| --- | --- |
| `Tab` | Next tab |
| `Shift+Tab` | Previous tab |

### Scene Tab

Mode shortcuts:

| Key | Action |
| --- | --- |
| `Shift+Q` | Select mode |
| `Shift+W` | Move mode |
| `Shift+E` | Pan mode |

Select mode:

| Key | Action |
| --- | --- |
| `Up` / `Left` | Previous visible object |
| `Down` / `Right` | Next visible object |
| `Enter` | Inspect selected object |

Move mode:

| Key | Action |
| --- | --- |
| `Up` | `position.y - 1` |
| `Right` | `position.x + 1` |
| `Down` | `position.y + 1` |
| `Left` | `position.x - 1` |

Pan mode:

| Key | Action |
| --- | --- |
| `Up` / `Right` / `Down` / `Left` | Move viewport |

### Sprite Tab

| Key | Action |
| --- | --- |
| `Up` / `Right` / `Down` / `Left` | Move cursor |
| printable character | Draw character |
| `Space` | Write a blank |
| `Backspace` | Erase current cell |
| `Shift+2` | Open character picker |
| `Ctrl+Z` | Undo |
| `Ctrl+Y` | Redo |
| `Shift+R` | Reset loaded asset |
| `Delete` | Delete active asset |

## Inspector Panel

Selection state:

| Key | Action |
| --- | --- |
| `Up` / `Down` | Move between controls |
| `Enter` | Activate control |
| `Shift+A` | Add a component to the inspected hierarchy object |
| `Shift+W` | Toggle component move mode when a component header is focused |
| `Delete` | Remove the focused component after confirmation |
| `/` | Collapse or expand section |
| `Tab` / `Shift+Tab` | Move between focusable controls |

Property-selection state:

| Key | Action |
| --- | --- |
| `Up` / `Down` | Move between sub-properties |
| `Enter` | Edit selected sub-property |
| `Escape` | Return to control selection |

Edit state:

| Key | Action |
| --- | --- |
| `Enter` | Commit edit |
| `Escape` | Cancel edit |
| `Backspace` | Delete backward when supported |
| `Left` / `Right` | Move cursor when supported |
| `Up` / `Down` | Increment or decrement when supported |

Path inputs:

| Key | Action |
| --- | --- |
| `Enter` | Open `Choose file` or `Edit path` |
| `Escape` | Close the path action modal or go back |

## Console Panel

| Key | Action |
| --- | --- |
| `Tab` | Next log tab |
| `Shift+Tab` | Previous log tab |
| `Up` | Scroll up when not in play state |
| `Down` | Scroll down when not in play state |
| `Shift+R` | Manual refresh when not in play state |

## Common Modal Controls

| Key | Action |
| --- | --- |
| `Up` / `Down` | Move selection |
| `Enter` | Confirm |
| `Escape` | Cancel or close |

Tree-style modals also use:

| Key | Action |
| --- | --- |
| `Right` | Expand folder |
| `Left` | Collapse folder or move to parent |

## Where Data Is Stored

| Change | Written To | When |
| --- | --- | --- |
| scene root edits | active `.scene.php` file | when you press `Ctrl+S` |
| object edits | active `.scene.php` file | when you press `Ctrl+S` |
| scene-view moves | active `.scene.php` file | when you press `Ctrl+S` |
| hierarchy additions and deletions | active `.scene.php` file | when you press `Ctrl+S` |
| scene rename | renamed `.scene.php` file | when you press `Ctrl+S` |
| asset creation from `Assets` | generated asset file | immediately |
| texture and tile map drawing | selected asset file | immediately |
| file asset rename | selected asset file path | immediately |
| asset delete | selected file or folder | immediately |

Special rename behavior:

- renaming a PHP class-backed asset under `Assets/Scripts` or `Assets/Events` also rewrites its class declaration immediately

## Current Editor Limits

These limits matter when planning your workflow:

- new hierarchy items are added at the scene root only
- there is no UI for reparenting objects
- component removal and reordering operate from focused component headers only
- the dedicated canvas editor only supports `.texture` and `.tmap` files
- deleting an asset does not automatically repair scene references
- play state currently changes editor behavior and console refresh, but does not embed the full runtime in the Game tab

## Practical Workflow Tips

- Save the scene after any asset rename that affects textures or tile maps already used in the scene.
- Treat folder deletion as destructive because it is recursive.
- Use the Inspector file picker for path fields to avoid typos.
- Use `sendama play` for full runtime checks and keep the editor open beside it.

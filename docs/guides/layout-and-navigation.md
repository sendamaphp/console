# Layout and Navigation

The editor is keyboard-first, but it does support a few mouse interactions for focus and selection.

## Default Layout

The editor uses a fixed five-panel layout:

- `Hierarchy`: top-left
- `Assets`: bottom-left
- `Main`: center
- `Console`: bottom-center
- `Inspector`: right side

The layout resizes with the terminal. If the terminal changes size while the editor is open, the panels reflow automatically.

## Focus Model

Only one panel is focused at a time. The focused panel gets the active border color and receives keyboard input.

Global focus controls:

| Key | Action |
| --- | --- |
| `Shift+Up` | Focus the panel above the current panel |
| `Shift+Right` | Focus the panel to the right |
| `Shift+Down` | Focus the panel below |
| `Shift+Left` | Focus the panel to the left |
| `Shift+1` | Open the panel list modal |

Panel list modal controls:

| Key | Action |
| --- | --- |
| `Up` / `Down` | Move selection |
| `Enter` | Focus the selected panel |
| `Escape` | Close the modal |

## Mouse Support

Mouse support is intentionally small and practical:

- click a panel to focus it
- click a row in `Hierarchy` or `Assets` to select it
- click a tab title in the `Main` panel to switch tabs

You should still expect the editor to behave primarily like a keyboard UI.

## Main Panel Tabs

The `Main` panel has three tabs:

- `Scene`
- `Game`
- `Sprite`

Use these controls to move between them:

| Key | Action |
| --- | --- |
| `Tab` | Next Main-panel tab when `Main` is focused |
| `Shift+Tab` | Previous Main-panel tab when `Main` is focused |

The `Inspector` also uses `Tab` and `Shift+Tab`, but there they move between controls instead of tabs.

## Global Editor Shortcuts

These work across the editor unless a modal is open:

| Key | Action |
| --- | --- |
| `Ctrl+C` | Close the editor |
| `Ctrl+S` | Save the loaded scene |
| `Shift+5` | Toggle play state |

## Modal Behavior

The editor uses modals for focused tasks such as:

- panel selection
- add-object flow
- asset creation
- delete confirmations
- sprite quick creation
- add-component selection
- special character selection
- path input actions
- file selection for texture and map paths

Most modals follow the same muscle memory:

| Key | Action |
| --- | --- |
| `Up` / `Down` | Move selection |
| `Enter` | Confirm |
| `Escape` | Cancel or go back |

Tree-style modals such as the file picker also use `Left` and `Right` to collapse and expand folders.

## How Navigation Changes By Panel

Each panel owns its own local navigation model:

- `Hierarchy` and `Assets` use a tree-browser pattern.
- `Shift+A` is panel-local: it adds objects in `Hierarchy`, opens the create menu in `Assets`, and opens the add-component menu in `Inspector` when a hierarchy object is loaded.
- `Main` switches between scene interaction, play view, and sprite editing.
- `Inspector` switches between selecting controls, selecting sub-properties, and editing.
- `Console` switches between tabs and scroll positions.

You will get the fastest results if you think in terms of panel roles:

- use `Hierarchy` to decide what exists
- use `Scene` to place things
- use `Inspector` to edit details
- use `Assets` and `Sprite` to manage ASCII art
- use `Console` to confirm what the game is doing

## Suggested Navigation Habits

These habits keep the editor feeling predictable:

1. Use `Shift+1` whenever you lose track of focus.
2. Do selection in `Hierarchy` or `Scene`, then make changes in `Inspector`.
3. Return to `Main` before using scene move tools or sprite editing tools.
4. Save with `Ctrl+S` any time you finish a scene-level change.

Continue with [Building Scenes](building-scenes.md) for the core level-building workflow.

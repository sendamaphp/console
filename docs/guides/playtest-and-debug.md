# Playtest and Debug

This guide explains what the editor's current play mode does, how the Console panel works, and how to combine the editor with `sendama play` for a full iteration loop.

## The Game Tab

When the `Game` tab is selected and the editor is not in play state, it shows an idle screen with the prompt:

```text
Shift+5 to Play
```

This is the editor's signal that you can enter play state.

## What `Shift+5` Does

Press `Shift+5` anywhere in the editor to toggle play state.

Current behavior:

- focus jumps to `Main`
- the `Game` tab becomes active
- the Main panel border changes to the play-state color
- the Console panel switches into automatic log refresh

What it does not currently do:

- it does not embed a live in-editor runtime surface yet

So treat play state as a lightweight run-and-monitor mode inside the editor, not as a full replacement for launching the game normally.

## When To Use `sendama play`

Use the editor's play state when you want to:

- switch the editor into its play-oriented UI state
- keep the `Game` tab active
- let the Console panel auto-refresh while you inspect logs

Use `sendama play` in a separate terminal when you need to:

- run the full game runtime
- verify input, flow, and timing outside the editor shell
- test a more realistic play session

Typical command:

```bash
sendama play
```

Or:

```bash
sendama play --directory /path/to/project
```

## Console Panel Overview

The `Console` panel reads from:

- `logs/debug.log`
- `logs/error.log`

Tabs:

- `Debug`
- `Error`

On startup, each tab loads the last three lines from its log file if the file exists.

Default filters:

- `Debug` starts on the `DEBUG` filter
- `Error` starts on `ALL`

## Console Controls

| Key | Action |
| --- | --- |
| `Tab` | Next console tab |
| `Shift+Tab` | Previous console tab |
| `Up` | Scroll up when not in play state |
| `Down` | Scroll down when not in play state |
| `Shift+R` | Refresh the active tab manually when not in play state |
| `Shift+F` | Open the log-level filter modal for the active tab |
| `Shift+C` | Rotate and clear the active log file after confirmation |

Tag colors:

- `[ERROR]`: red
- `[WARN]`: yellow
- `[INFO]`: blue
- `[DEBUG]`: light gray

Filter options:

- `Debug`: `ALL`, `DEBUG`, `INFO`, `WARN`, `ERROR`
- `Error`: `ALL`, `ERROR`, `CRITICAL`, `FATAL`

## Filtering And Clearing Logs

Use `Shift+F` when the `Console` has focus to filter the active tab without leaving the editor.

Use `Shift+C` when you want a clean log window for the next run:

- confirm the modal to rotate the active file to the next backup, such as `debug.log.1`
- the active log file is then cleared
- cancel leaves the log file unchanged

## Auto Refresh

While the editor is in play state, the Console panel refreshes itself from disk every `editor.console.refreshInterval` seconds.

Example config:

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

If you do not configure a value, the editor uses `5` seconds.

## Notifications

The editor also shows a top-right snackbar for transient system messages.

Current behavior:

- scene save success and failure use the snackbar
- the display duration comes from `editor.notifications.duration`
- if you do not configure it, the editor uses `4` seconds

## Recommended Debug Loop

For most gameplay iteration, this loop works well:

1. Edit scene data or component values in the editor.
2. Press `Ctrl+S` if you changed the scene.
3. Run `sendama play` in another terminal when you need a full runtime check.
4. Keep the editor open to inspect assets and logs.
5. Use the Console panel to watch `debug.log` and `error.log`.
6. Return to the editor, make the next change, and repeat.

## Practical Expectations

Right now, the editor is strongest as a content authoring and inspection tool. The most effective workflow is:

- author content in the editor
- save scene changes
- run the game with `sendama play`
- use the editor console and inspector to support the next iteration

If you need exact controls at a glance, see [Reference](reference.md).

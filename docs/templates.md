# Pad Templates

Users can pre-fill new `.pad` files with content from a template they prepared once. Templates live in Nextcloud's standard `/Templates` folder; the plugin hooks into NC's existing template flow.

## Creating a template

1. Create or copy a `.pad` file into `/Templates`. Any `.pad` file there counts as a template.
2. Edit the pad and write the boilerplate you want every new copy to start with.

## Using a template

When the user clicks **+ → New pad** in the Files app, Nextcloud's template picker lists every `.pad` in `/Templates`. Selecting one creates a new pad in the current folder with:

- a freshly provisioned Etherpad pad on the server,
- the template body copied across (placeholders resolved — see below),
- a new binding row so the pad and the `.pad` file stay linked.

If the user picks "Blank" instead of a template, the new file is empty and behaves like any normal "+ New pad" creation — frontmatter is initialised on first open.

## Placeholder syntax

Placeholders in the **body** are resolved when the new pad is created. The template's filename is **not** rewritten — see the next section. Syntax: `{{<resolver>[:<arg>][|<format>]}}`.

| Token | Result | Example |
|---|---|---|
| `{{date}}` | today, ISO `Y-m-d` | `2026-05-17` |
| `{{date\|d.m.Y}}` | today, custom PHP date format | `17.05.2026` |
| `{{date:next monday}}` | relative date via `strtotime`, ISO | `2026-05-18` |
| `{{date:next monday\|d.m.Y}}` | relative date with custom format | `18.05.2026` |
| `{{date:+7 days}}` | 7 days from today | `2026-05-24` |
| `{{user}}` | current user's display name | `Jacob Bühler` |
| `{{user.uid}}` | current user's UID | `jaggob` |

Unknown directives stay as literal text (`{{forecast}}` → `{{forecast}}`). Unparseable date expressions also stay as literal so the user can fix the template without losing the file.

## Filename templates (not supported)

Placeholders in the template's filename are **not** rewritten. Nextcloud's `+ New pad` flow asks the user for a filename **before** showing the template picker, and `TemplateManager::createFromTemplate` re-fetches the new file by that user-typed path *after* our event fires. Renaming during the event causes NC's lookup to throw `NotFoundException` and the create call returns 403 to the client.

The new file ends up at the name the user types into NC's filename dialog. Body placeholders still get resolved.

## Caveats

- **External pads (`ext.*`)** can't be used as templates — they hold only a snapshot, not Etherpad-side content. The new file is reset to empty and the user gets a clean blank pad instead.
- **Failed template materialisation falls back to a blank pad.** If anything in the listener throws (binding race, Etherpad unreachable, malformed template), the byte-copy NC made is wiped and the new file behaves like a normal empty `.pad` — the regular missing-frontmatter init kicks in on first open.
- **Placeholder substitution applies to both the plain-text and the HTML snapshot in the body**. If a placeholder ends up inside an HTML attribute (`<a href="{{date}}">`), it gets resolved too — keep placeholders in human-readable locations to avoid surprises.
- **No template registry** — every `.pad` in `/Templates` is a candidate. There's no separate "is a template" flag.

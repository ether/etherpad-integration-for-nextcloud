# Pad Templates

Users can pre-fill new `.pad` files with content from a template they prepared once. Templates live in Nextcloud's standard `/Templates` folder; the plugin hooks into NC's existing template flow.

## Creating a template

1. Create or copy a `.pad` file into `/Templates`. Any `.pad` file there counts as a template.
2. Edit the pad and write the boilerplate you want every new copy to start with.
3. Optionally rename the template file with placeholders in the name (see "Filename templates" below).

## Using a template

When the user clicks **+ → New pad** in the Files app, Nextcloud's template picker lists every `.pad` in `/Templates`. Selecting one creates a new pad in the current folder with:

- a freshly provisioned Etherpad pad on the server,
- the template body copied across (placeholders resolved — see below),
- a new binding row so the pad and the `.pad` file stay linked.

If the user picks "Blank" instead of a template, the new file is empty and behaves like any normal "+ New pad" creation — frontmatter is initialised on first open.

## Placeholder syntax

Placeholders in the **body** and the **filename** are resolved when the new pad is created. Syntax: `{{<resolver>[:<arg>][|<format>]}}`.

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

## Filename templates (not supported in v1)

Placeholders in the template's filename are **not** rewritten today. Nextcloud's `+ New pad` flow asks the user for a filename **before** showing the template picker, and `TemplateManager::createFromTemplate` re-fetches the new file by that user-typed path *after* our event fires. Renaming during the event causes a `NotFoundException` and NC returns 403 to the client.

Realistic workaround for now: type a meaningful filename when prompted. The body still gets its `{{date}}` / `{{user}}` placeholders resolved.

## Caveats

- **External pads (`ext.*`)** can't be used as templates — they hold only a snapshot, not Etherpad-side content. The listener skips them and the new file behaves like an empty pad.
- **Placeholder substitution applies to both the plain-text and the HTML snapshot in the body**. If a placeholder ends up inside an HTML attribute (`<a href="{{date}}">`), it gets resolved too — keep placeholders in human-readable locations to avoid surprises.
- **No template registry** — every `.pad` in `/Templates` is a candidate. There's no separate "is a template" flag.

# Pad Templates

Users can pre-fill new `.pad` files with content from a template they prepared once. The plugin hooks into Nextcloud's existing template flow, so templates appear in the picker NC already shows for **+ → New pad**.

## Where templates come from

Three sources feed the same picker.

### Personal templates

Each Nextcloud account has its own *Templates* folder. The default location is `/Templates` in the user's userspace, but the path is configurable per user in **Personal Settings → Files → Templates folder**. Every `.pad` in that folder is offered to that user, and to nobody else.

### Group folder templates

Because Nextcloud mounts group folders into the user's virtual filesystem, a user can point their Templates folder at a group-folder path (e.g. `/MyTeam/Vorlagen`), and the team's shared `.pad` templates then show up for everyone who points their Templates folder at the same path. Each user has to set this themselves: NC only looks at the single path configured per account, so this shares the files, not the configuration.

Read-only shares (a folder shared *to* the user from someone else's account) are not officially supported as a template source; results depend on how NC's virtual filesystem mounts the share. For team templates, prefer group folders or the admin templates below.

### Admin templates (instance-wide)

An admin can upload `.pad` templates under **Administration settings → Etherpad**. They are offered to every account on the instance, with no per-user setup — this is the one source a user cannot opt out of or misconfigure.

They are stored in the instance's `appdata` directory, not in anyone's files. Practical consequences:

- A full backup of the data directory covers them; a per-user export does not.
- There is no versioning and no trash behind that folder. Uploading over an existing name is refused unless the request says it means to replace, and the admin page asks first — the previous file is gone for good.
- They are not visible in the Files app and are not searchable; the settings page is the only place they are listed.

## Creating a template

1. Create or copy a `.pad` file into the *Templates* folder (default `/Templates` or whatever the user configured).
2. Edit the pad and write the boilerplate you want every new copy to start with.

## Using a template

When the user clicks **+ → New pad** in the Files app, Nextcloud's template picker lists every `.pad` in their *Templates* folder together with the admin templates. Selecting a template creates a new pad in the current folder with:

- a freshly provisioned Etherpad pad on the server,
- the template body copied across (placeholders resolved — see below),
- a new binding row so the pad and the `.pad` file stay linked.

If the user picks "Blank" instead of a template, the new file is empty and behaves like any normal "+ New pad" creation — frontmatter is initialised on first open. The pad type is then the default one, protected, unless the admin switched protected pads off; in that case a blank pad is created as a public pad, readable by anyone with the link.

## Placeholder syntax

Placeholders in the **body** are resolved when the new pad is created. The template's filename is **not** rewritten — see the next section. Syntax: `{{<resolver>[:<arg>][|<format>]}}`.

| Token | Result | Example |
|---|---|---|
| `{{date}}` | today, ISO `Y-m-d` | `2026-05-17` |
| `{{date\|d.m.Y}}` | today, custom PHP date format | `17.05.2026` |
| `{{date:next monday}}` | relative date via `strtotime`, ISO | `2026-05-18` |
| `{{date:next monday\|d.m.Y}}` | relative date with custom format | `18.05.2026` |
| `{{date:+7 days}}` | 7 days from today | `2026-05-24` |
| `{{user}}` | current user's display name | `Erika Mustermann` |
| `{{user.uid}}` | current user's UID | `emustermann` |

Unknown directives stay as literal text (`{{forecast}}` → `{{forecast}}`). Unparseable date expressions also stay as literal so the user can fix the template without losing the file.

## Filename templates (not supported)

Placeholders in the template's filename are **not** rewritten. Nextcloud's `+ New pad` flow asks the user for a filename **before** showing the template picker, and `TemplateManager::createFromTemplate` re-fetches the new file by that user-typed path *after* our event fires. Renaming during the event causes NC's lookup to throw `NotFoundException` and the create call returns 403 to the client.

The new file ends up at the name the user types into NC's filename dialog. Body placeholders still get resolved.

## API for custom frontends

Custom frontends that don't go through Nextcloud's native `+ New pad` menu can call our own endpoint instead, which bypasses NC's `TemplateManager` entirely:

```
POST /index.php/apps/etherpad_nextcloud/api/v1/pads/from-template
Content-Type: application/x-www-form-urlencoded
requesttoken: <csrf>

file=/Meetings/Protokoll {{date:next monday|d.m.Y}}.pad&templateFileId=1234
```

Differences from the NC-native flow:

- **Filename placeholders are applied.** Pass `{{date}}` / `{{user}}` tokens in `file` and the endpoint resolves them on the server side before creating the file. The NC web flow can't do this because of how `TemplateManager` re-fetches by the user-typed path.
- **Any `.pad` in the user's userspace works as a template.** Pass any `templateFileId` the user has read access to; the source doesn't have to live in the *Templates* folder. Caller-side filtering (current folder, ancestor scan, explicit team list) is up to the frontend.
- **Single atomic call.** The endpoint provisions the pad, writes the file, creates the binding, and returns `file_id` + `pad_id` + `viewer_url` in one response.

Response shape on success (200):

```json
{
  "file": "/Meetings/Protokoll 18.05.2026.pad",
  "file_id": 4321,
  "pad_id": "p-newpad",
  "access_mode": "protected",
  "pad_url": "https://pad.example.test/p/p-newpad",
  "viewer_url": "/index.php/apps/files/files/4321?dir=%2FMeetings&editing=false&openfile=true"
}
```

Error responses:
- **400** — template is not a `.pad`, template is external (`ext.*`), template is empty, target path invalid
- **403** — no pad type is enabled in the admin settings, so nothing can be created
- **404** — template file ID does not resolve in the user's userspace
- **409** — target filename collides with an existing file (`A file with this name already exists.`)
- **500** — Etherpad unreachable or other unexpected failure

## Caveats

- **External pads (`ext.*`)** can't be used as templates — they hold only a snapshot, not Etherpad-side content. The new file is reset to empty and the user gets a clean blank pad instead.
- **Failed template materialisation falls back to a blank pad.** If anything in the listener throws (binding race, Etherpad unreachable, malformed template), the byte-copy NC made is wiped and the new file behaves like a normal empty `.pad` — the regular missing-frontmatter init kicks in on first open.
- **Placeholder substitution applies to both the plain-text and the HTML snapshot in the body**. If a placeholder ends up inside an HTML attribute (`<a href="{{date}}">`), it gets resolved too — keep placeholders in human-readable locations to avoid surprises.
- **No "is a template" flag** — every `.pad` in the user's *Templates* folder is a candidate, and every `.pad` an admin uploaded is offered instance-wide. Nothing inside the file marks it as a template.
- **A template keeps its own access mode**, unless the admin switched that pad type off. In that case the pad is created in the enabled mode instead of failing, so the template's content still lands. With no pad type enabled at all, template creation is refused. Be aware this can widen access: a protected template creates a public pad when protected pads are off — on such an instance that is the only option, but the resulting pad is open to anyone with the link.

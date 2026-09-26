# When a Pad Is Deleted

SPDX-License-Identifier: AGPL-3.0-or-later

A pad lives in Etherpad; its `.pad` file lives in Nextcloud. The pad is
deleted only once its file is deleted for good. Moving the file to the trash
does not delete the pad, so a restored file has its pad back as it was. The
technical side is in [architecture.md](architecture.md).

This applies to pads this Nextcloud manages. A `.pad` file that links a pad
on another Etherpad server never deletes that pad.

## Moved to the trash

A file goes to the trash when it is deleted through Files, a desktop or
mobile client, or a WebDAV `DELETE`, in a user's own folders as in a team
folder. So does a folder with its files, and a file replaced by moving
another file onto it (WebDAV `MOVE` with `Overwrite: T`).

- **The pad stays**, with its content and history.
- **A protected pad** loses its Etherpad sessions: whoever has it open can no
  longer edit it. For a single file this happens at once, for the files in a
  folder within minutes. Someone who only reads keeps seeing the last state
  until they reload.
- **A public pad** stays reachable by its link, for reading and editing,
  until its file is deleted for good. A team folder's trash with no
  retention limit may keep it for a long time.
- While the file is in the trash, nothing is synced into it: it keeps what it
  held when it was last opened. Edits made through a public link meanwhile
  are in the pad only.

## Restored from the trash

- **The file has its pad back**, the same pad with its full history,
  including edits made through a public link while it was in the trash.
- Opening the file gives a new Etherpad session, as always.
- Should Etherpad have lost the pad in the meantime, opening the file offers
  to make a new pad from the content saved in the file.

## Deleted for good

| How the file is deleted for good | When the pad is deleted |
|---|---|
| From the trash: the trash emptied, the item deleted there, expired, or `occ trashbin:cleanup`. A user's trash or a team folder's. | By a background job, within minutes. |
| Past the trash: a WebDAV `DELETE` with `X-NC-Skip-Trashbin: true`, the trash app switched off for the user, or a move to the trash that fails. | In the same request. Of a folder's files, as many as a few seconds allow; the rest by a background job. When Etherpad does not answer, the background job deletes them once it does. |
| With the account: the account deleted. | The pads of the account's own files - those in its home and its trash, shared ones included - by a background job, within minutes. Files in team folders, and files the account put into folders others shared with it, are not the account's: they stay, and so do their pads. |

"A background job" runs every five minutes when Nextcloud's background jobs
run by system cron; with AJAX or webcron, only as often as those run.

## Gone without the app seeing it

Some ways of removing a file tell no app about it. Their pads stay:

- a team folder deleted as a whole by an admin;
- files removed outside Nextcloud - on an external storage, or in the data
  directory followed by a scan - and an external storage removed.

A pad that stays keeps its content in Etherpad, and nobody reaches it
through Nextcloud any more; a public pad stays reachable by its link. The
consistency check on the admin page counts these pads
(`binding_without_file_count`).

## With `delete_on_trash` off

The setting reads "delete the pad when its `.pad` file is deleted for good".
Switched off, the app deletes no pad at all; moving a file to the trash
still ends a protected pad's sessions. Switched back on, the pads of files
deleted for good in the meantime are deleted too.

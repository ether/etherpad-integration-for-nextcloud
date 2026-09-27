# When a Pad Is Deleted

SPDX-License-Identifier: AGPL-3.0-or-later

A pad lives in Etherpad; its `.pad` file lives in Nextcloud. Whether and when
the pad is deleted with the file depends on how the file leaves: moved to
the trash, deleted for good, or removed outside Nextcloud. The technical
side is in [architecture.md](architecture.md), under "Trash/Restore" and
"Files gone for good".

What counts is the file, not its name: a `.pad` file renamed to something
else keeps its pad, and the pad goes once the file is deleted for good.

This applies to pads this Nextcloud manages, with `delete_on_trash` on (the
default). A `.pad` file that links a pad on another Etherpad server never
deletes that pad.

## Moved to the trash

A file goes to the trash when it is deleted through Files, a desktop or
mobile client, or a WebDAV `DELETE`, in a user's own folders as in a team
folder. So does a file replaced by moving another file onto it (WebDAV
`MOVE` with `Overwrite: T`).

- **A `.pad` file:** its pad is deleted, once the trashed file holds a last
  snapshot of it. A delete through WebDAV - Files and the clients included -
  holds the file's lock, so a background job writes the snapshot and then
  deletes the pad; until then a public pad stays reachable by its link.
  Where the file is not locked, this happens right away.
- **A folder:** the pads of the `.pad` files in it stay as they are, until
  the folder is deleted for good. A public pad stays reachable by its link.

## Restored from the trash

- **A `.pad` file whose pad was deleted:** a new pad is made from the
  snapshot in the file, at once. It holds the content, not the pad's
  history. This is also how files deleted under 1.1.0-beta.1 come back. A
  copy of another `.pad` file that was never opened is left as it is: its
  pad was not deleted, and opening it offers that pad or a new one.
- **A `.pad` file restored before the background job deleted its pad:** the
  file has its pad back, with any edits the snapshot missed. Should
  Etherpad have lost the pad meanwhile, a new one is made from the file at
  once. If Etherpad does not answer during the restore, the file is
  restored all the same, and its next open offers the new pad.
- **A folder:** its pads were never touched, and the files have them as
  before. A file among them whose pad Etherpad has lost offers a new one
  when it is opened.

## Deleted for good

| How the file is deleted for good | When the pad is deleted |
|---|---|
| From the trash: the trash emptied, the item deleted there, expired, or `occ trashbin:cleanup`. A user's trash or a team folder's. | A `.pad` file's pad is gone already, or goes with its snapshot as above. The pads of the files in a trashed folder are deleted by a background job, within minutes. |
| Past the trash: a WebDAV `DELETE` with `X-NC-Skip-Trashbin: true`, the trash app switched off for the user, or a move to the trash that fails. | By a background job, within minutes, for a folder's files too. When Etherpad does not answer, the job deletes them once it does. |
| With the account: the account deleted. | The pads of the account's own files - those in its home and its trash, shared ones included - by a background job, within minutes. Files in team folders, and files the account put into folders others shared with it, are not the account's: they stay, and so do their pads. |

"A background job" runs every five minutes when Nextcloud's background jobs
run by system cron; with AJAX or webcron, only as often as those run.

On Nextcloud 34 up to 34.0.4, Nextcloud reports the files inside a folder
deleted for good under the wrong ids (nextcloud/server#63969, fixed for
34.0.5). The app cannot tell which files they were, so the pads of the
`.pad` files inside such a folder stay, and the consistency check lists
them. A `.pad` file deleted on its own, and an account deleted, are not
affected.

## Gone without being deleted in Nextcloud

A file can also leave without anyone deleting it in Nextcloud. Its pad
stays:

- files removed outside Nextcloud - on an external storage, or in the data
  directory - that a scan then drops;
- a team folder deleted as a whole by an admin, and an external storage
  removed.

A pad that stays keeps its content in Etherpad, and nobody reaches it
through Nextcloud any more; a public pad stays reachable by its link. The
consistency check on the admin page counts these pads and lists them by pad
id (`vanished_file_count`), so an admin can delete the ones no longer
needed in Etherpad.

## When Etherpad has lost a pad

If Etherpad no longer has a pad - deleted there by hand, or lost with its
database - opening its file says so. Whoever may edit the file can make a
new pad from the content saved in it; from then on the file opens the new
pad. The same happens for a public pad that someone visited by its link
after it was lost, which Etherpad makes anew, empty. A copied `.pad` file,
which has no pad of its own, offers the same. A file restored from the
trash does not ask: it gets its new pad on its own.

## With `delete_on_trash` off

The app deletes no pad at all. A `.pad` file moved to the trash keeps its
pad, and its deletion waits; restored, the file has it back. The pads of
files deleted for good stay. Switched back on, the waiting deletions are
finished, and the pads of files deleted for good in the meantime are
deleted too.

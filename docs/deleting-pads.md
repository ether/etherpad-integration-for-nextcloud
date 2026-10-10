# When a Pad Is Deleted

SPDX-License-Identifier: AGPL-3.0-or-later

A pad lives in Etherpad; its `.pad` file lives in Nextcloud. The pad lives
as long as the file: the trash keeps it, and it is deleted once the file is
deleted for good. The technical side is in [architecture.md](architecture.md),
under "Trash/Restore" and "Files gone for good".

What counts is the file, not its name: a `.pad` file renamed to something
else keeps its pad, and the pad goes once the file is deleted for good.

This applies to pads this Nextcloud manages, with the admin setting
`delete_pad_with_file` on - the default; `delete_on_trash` before,
whose value it takes over. A `.pad` file that links a pad on another
Etherpad server never deletes that pad.

## Moved to the trash

A file goes to the trash when it is deleted through Files, a desktop or
mobile client, or a WebDAV `DELETE`, in a user's own folders as in a team
folder. So does a file replaced by moving another file onto it (WebDAV
`MOVE` with `Overwrite: T`), and every file in a folder moved to the trash.

The pad stays as it is, and nothing is written into the file.

- **A protected pad** loses its sessions: whoever still has it open can
  write no more from their next change on, and nobody opens it until the
  file is back. What does not fit in a few seconds - a pad opened many
  times, a folder with a few dozen protected pads, an Etherpad that is
  slow to answer - a background job takes within minutes, while the file
  stays in the trash; a file restored keeps what is left, and its next
  open makes a new session. A folder with more than 100 protected pads
  takes the sessions of the first 100 found; the others expire on their
  own, within six hours.
- **A public pad** stays reachable by its link, and can be written into.
  The file in the trash is not synced, so what is written there is in the
  pad, not in the file's snapshot; a restore brings it back with the pad.
- **A `.pad` file that names another user's group** - a legacy Ownpad file
  can - takes no sessions along at once. The background job takes them
  once every pad of that group is in a trash or gone; a pad that is no
  file of the app's, or a file still in Files, keeps them.

## Restored from the trash

- **A `.pad` file, or a folder with `.pad` files in it:** the file has its
  pad back as it was, with its history and anything written into it
  meanwhile. Opening it makes a new session for a protected pad.
- **A `.pad` file whose pad Etherpad has lost meanwhile:** a new pad is made
  from the snapshot in the file, at once. It holds the content, not the
  pad's history. If Etherpad does not answer during the restore, the file
  is restored all the same, and its next open offers the new pad. A folder
  restored does not ask for each of its files: those whose pad is lost offer
  a new one when they are opened.
- **A `.pad` file a share recipient deleted:** Nextcloud puts the file
  itself into its owner's trash and a copy into the recipient's. The
  owner's restore brings the pad back. The recipient's copy, restored, is a
  copy: it holds the file's content as of its last sync - what was written
  into the pad after that lives only in the pad, with the original - and
  its open offers a new pad from it, while the pad stays with the file in
  the owner's trash until that goes.
- **A `.pad` file trashed under an earlier version,** whose trash deleted
  the pad (1.1.0-beta.1 and before): a new pad is made from the snapshot in
  the file, at once. A
  copy of another `.pad` file that was never opened is left as it is: its
  pad was not deleted, and opening it offers that pad or a new one.

## Deleted for good

| How the file is deleted for good | When the pad is deleted |
|---|---|
| From the trash: the trash emptied, the item deleted there, expired, or `occ trashbin:cleanup`. A user's trash or a team folder's. | By a background job, within minutes; for a folder's files too. |
| Past the trash: a WebDAV `DELETE` with `X-NC-Skip-Trashbin: true`, the trash app switched off for the user, or a move to the trash that fails or that another app keeps from the trash. | By a background job, within minutes; for a folder's files too. |
| With the account: the account deleted. | The pads of the account's own files - those in its home and its trash, shared ones included - by a background job, within minutes. Files in team folders, and files the account put into folders others shared with it, are not the account's: they stay, and so do their pads. The account's own Etherpad sessions go as the delete starts, as on a logout. |

"A background job" runs on every tick of a five-minute system cron; with
AJAX or webcron, only as often as those run. It deletes the pad of a file
deleted for good once five minutes have passed, so the pad goes within
about ten minutes; the admin page's "Check pending pads" does it at once. When Etherpad does not answer, the job deletes the pad once
it does; until then the admin page counts it as a pending delete.

On Nextcloud 34, Nextcloud reports the files inside a folder deleted for
good under the wrong ids - and a trash emptied as a whole, from the Files
app or with `occ trashbin:cleanup`, is deleted as one folder. The fix
(nextcloud/server#63998) is in 35.0.1; its backport to 34
(nextcloud/server#64497) is planned for 34.0.5 and not merged yet. The app
cannot tell which files they were, so the pads of the `.pad` files inside
such a folder, or in a trash emptied as a whole, stay, and the consistency
check lists them. A `.pad` file deleted on its own - past the trash, or
from the trash by itself or when it expires - and an account deleted, are
not affected.

## Gone without being deleted in Nextcloud

A file can also leave without anyone deleting it in Nextcloud. Its pad
stays:

- files removed outside Nextcloud - on an external storage, or in the data
  directory - that a scan then drops;
- a team folder deleted as a whole by an admin, and an external storage
  removed. Files that were in the team folder's trash then may go as from
  a trash, and take their pads along: on a team folder kept on Nextcloud's
  own storage, not on one with a storage of its own.

A pad that stays keeps its content in Etherpad, and nobody reaches it
through Nextcloud any more; a public pad stays reachable by its link. The
consistency check on the admin page counts these pads and lists up to 25 of
them by pad id (`vanished_file_count`). The admin can delete them from
there, one listed pad or all of them, after a confirmation that names how
many: they then go like the pads of files deleted for good, within
minutes, or at once with "Check pending pads". Only do so when no `.pad`
file names them any more: after the file cache was rebuilt, or files were
restored from a backup, a file may still be there under a new id, and its
pad would go with the others. With `delete_pad_with_file` off, nothing is
deleted. The app never does this on its own, since only an admin can
tell.

Deleting them all takes only as many as the confirmation named: if more
vanished since the list was shown, nothing is deleted, and the page shows
the new count to confirm again. A pad marked for deletion cannot be taken
back from the page. Switching `delete_pad_with_file` off before the job
runs keeps it, but switching it back on deletes it.

A listed public pad can also be forgotten: it leaves the list and stays in
Etherpad, reachable by its link, and the app never cleans it up after
that. That suits a public pad still used by its link. A protected pad
cannot be forgotten: without its row, the sessions made for it would stay
valid until they expire, and where legacy protected imports are allowed a
user holding its address could claim its group with a legacy `.pad` file.
Forgetting does not help a file that came back under a new id either:
without the pad's row, nothing tells that the file names it, and the
file's open still offers a new pad from its content.

Deleting such a pad in Etherpad alone leaves it on the list.

## When Etherpad has lost a pad

If Etherpad no longer has a pad - deleted there by hand, or lost with its
database - opening its file says so. Whoever may edit the file can make a
new pad from the content saved in it; from then on the file opens the new
pad. The same happens for a public pad that someone visited by its link
after it was lost, which Etherpad makes anew, empty; an editor still open on
the file does not write that empty pad into it as it closes, so the content
stays for the new pad. A copied `.pad` file,
which has no pad of its own, offers the same. A file restored from the
trash does not ask: it gets its new pad on its own.

## With `delete_pad_with_file` off

The app deletes no pad at all: the pads of files deleted for good stay, and
the admin page counts them as pending deletes. Switched back on, the job
deletes them.

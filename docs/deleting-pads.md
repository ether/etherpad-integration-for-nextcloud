# When a Pad Is Deleted

SPDX-License-Identifier: AGPL-3.0-or-later

A pad lives in Etherpad; its `.pad` file lives in Nextcloud. Whether the pad
is deleted with the file depends on how the file leaves Nextcloud. This page
lists every way, as the app handles it today. The technical side is in
[architecture.md](architecture.md), under "Trash/Restore" and "Files gone for
good".

It applies to pads this Nextcloud manages. A `.pad` file that links a pad on
another Etherpad server never deletes that pad.

## With `delete_on_trash` on (the default)

| How the `.pad` file goes | What happens to the pad |
|---|---|
| Moved to the trash: through Files, a desktop or mobile client, or a WebDAV `DELETE`. A file replaced by moving another file onto it (WebDAV `MOVE` with `Overwrite: T`) goes to the trash as well. | Deleted once the trashed file holds a last snapshot of it. A delete through WebDAV - Files and the clients included - holds the file's lock, so a background job writes the snapshot and then deletes the pad; until then a public pad stays reachable by its link. Where the file is not locked, the delete does both right away. |
| Restored from the trash | A new pad is made from the snapshot in the file. While the deletion was still owed, the old pad comes back instead. |
| A folder moved to the trash | The pads of the `.pad` files in it stay as they are. A public pad stays reachable by its link. Restoring the folder changes nothing. |
| Deleted for good from a trash: the trash emptied, an item deleted there, expired, `occ trashbin:cleanup`. A user's trash or a team folder's. | The pads still there - those of files in a trashed folder - are deleted by a background job. |
| Deleted past the trash: a WebDAV `DELETE` with `X-NC-Skip-Trashbin: true`, the trash app switched off for the user, or a move to the trash that fails. | Deleted in the same request. Of a folder, as many as a few seconds allow; the rest by a background job. When Etherpad does not answer, the background job deletes them once it does. |
| The account deleted | The pads of the account's own files - the files in its home, shared ones included - are deleted by a background job. The pads of files in team folders, and of files the account put into folders others shared with it, stay: those files are not the account's, and they stay too. |
| A team folder deleted as a whole by an admin | The pads stay. Nextcloud tells no app about it. |
| Removed outside Nextcloud: on an external storage, or in the data directory followed by a scan. An external storage removed. | The pads stay. Nextcloud tells no app about it. |

"A background job" is `GoneFileSweepJob` or the jobs for owed deletions. They
run every five minutes when Nextcloud's background jobs run by system cron;
with AJAX or webcron, only as often as those run.

A pad that stays keeps its content in Etherpad, and nobody reaches it through
Nextcloud any more. A public pad stays reachable for anyone who has its link.
The consistency check on the admin page counts the pads whose file is gone
(`binding_without_file_count`).

## With `delete_on_trash` off

The app deletes no pad at all. A file moved to the trash keeps its pad, and
its deletion waits. Pads of files deleted for good or past the trash stay.
Switched back on, the owed deletions are finished, and the pads of files
since deleted for good go too.

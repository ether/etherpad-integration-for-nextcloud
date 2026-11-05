# Changelog

## 1.1.0-beta.3 — 2026-11-05

Third beta release for 1.1.0. Focus: Nextcloud 35, stricter checks on pad bindings and Ownpad imports, Etherpad sessions revoked for large folders too, less duplicate work from open viewers - and many smaller fixes.

### Security

- **An Ownpad file could have the app delete a public pad another file was bound to.** Before each call, Etherpad's API rewrites an id it has no pad for - whitespace and `:` become `_` - and then acts on that other pad: the migration bound `victim pad` as written, and the sweep later deleted `victim_pad`. Such ids are now refused before a binding is made. A binding an earlier version made under such an id loses only the binding when its file is deleted for good; the pad stays, a `:` pad on Etherpad before 3.3.3 included, and so does a group pad's group, with a warning naming the file id. With Etherpad's `lowerCasePadIds`, upper case is rewritten too: a public id that matches a bound one in another case is now refused. Bindings of that kind made before are not caught, so the setting is better left off (README). (#384)
- **The page that creates a pad for an embedding site works only inside a frame.** `/embed/create-by-parent/{parentFolderId}` opened on its own - by a link, `window.open` or a WebView - shows an error and creates nothing, and an opener gets no event. Before, a link anyone could send was enough to create a file with a pad in the signed-in person's account. (#402)
- **A pad on this server is reported from the file's binding, not from its text.** `meta-by-id`, `resolve`, and an `initialize` that finds the file initialized took the pad from the file's text: a copy reported the original's pad, and a hand-written file could have Etherpad asked about any pad. A copy, or a file without a binding, now answers with empty fields. Whoever may not write the file gets a public pad's read-only address and no `pad_id`, and a protected pad's `pad_id` and no address. Nothing changes in the interface; external clients that read these fields see them empty there. (#401)

### Added

- **Nextcloud 35 is supported**; the floor stays 32.0.9. On 35.0.0, as on Nextcloud 34, the pads of a folder deleted for good stay in Etherpad; 35.0.1 fixes that. (#394)
- `docs/architecture.md` says under "Cost at scale" what requests, open viewers, deletes and background jobs cost the server and Etherpad, and what grows without a bound, with the values `tests/load/` measured. The README says what to set for thousands of viewers: the sync interval, Redis for locks, enough database connections. (#371, #381)

### Changed

- **Several viewers of a pad no longer each write its file once per interval**, one file version per write. The Viewer and the embed page send their timed syncs with `periodic=1`; such a sync runs only where it can claim the file for half an interval, and otherwise answers 200 with `status=deferred`, a new status of the API. This needs a memory cache; where each web server has its own, syncs are paced per server. A user whose timed syncs keep failing for them alone holds off the timed syncs of the others, round after round, until a sync without the timer comes. (#379)
- **Installs, app updates and `occ maintenance:repair` no longer search the whole file cache for `.pad` files on every run**, with up to three queries without an index. A versioned repair step sets their MIME type and part once, in one `UPDATE` across all shards, and again where the `.pad` mapping was missing; `BackfillPadMimeType` is gone. (#372)
- **`external_pad_allowlist` no longer opens `frame-src`**; it only decides which hosts the app fetches from. An Etherpad of your own that redirects to another host loses its frame where that host was allowed only through the allowlist. An `etherpad_host` without a scheme gets no policy. (#406)
- **With `allow_external_pads` on, a file with a binding whose text names a pad on another server is refused** on open, in its live content and on sync, with the usual mismatch (400), instead of following the text; the sync writes nothing into the file. A legacy Ownpad link in a file bound to another pad is refused instead of overwriting the file. Only such installations are affected; an older version of the file, or a new external file, is the way out. (#405)
- **An empty "Etherpad API URL" follows later changes to the Base URL**; an API URL that normalises to the Base URL is stored empty as well. Older versions stored a copy of the Base URL there. A copy that matches it shows empty in the form and is cleared on the next save. A copy of a Base URL that has moved since, through `occ` or the form, reads as an address of its own and is kept: check the field after the update. A server that cannot reach Etherpad at the Base URL needs its own address there; the connection test checks it before a save. (#412)
- **The log tells a rejected API key apart.** Where Etherpad rejects the API key - its code 4, under HTTP 401 or 403 - the log writes an error of its own, "Etherpad rejected this Nextcloud's API key …", instead of "Etherpad could not be reached", from the sweep, the session jobs, the collector and the restore from the trash too. After a refused create it no longer warns about a pad that never was. Answers and jobs behave as in an outage, and a 401 or 403 from a proxy in front stays an HTTP error. (#382)
- **Creates and their cleanup check the file by one rule.** Before the write, a claimed file is compared with its expected content; after it, only the hash of the written document counts. A file changed since is left in place, one emptied or set back after the create wrote it included, for a template and the external tile too. The external tile writes the fetched document by the file's id and refuses a file written meanwhile, as `from-url` does. A failed blank create takes down its pad and binding before the file. The cleanup's log lines say what was left and why, and a pad removal Etherpad does not confirm is logged as "Could not confirm removal of a new Etherpad pad." with `padId`, `fileId` and `operation`. A restore warns where it cannot make sure the binding names the file's old pad. (#385, #386, #389)
- **Pad addresses stay out of logged traces.** When a create through the external tile fails, Nextcloud logs the exception with each frame's arguments; the address of the pad on the other server, with its query and token, no longer appears there. A pad's address counts as a credential: methods taking it are registered as sensitive, and the objects carrying it keep it private. API answers stay the same. (#387)
- When Etherpad cannot give a public pad's read-only address, opening fails for signed-in readers as through the public link: 503 with `retryable` when Etherpad does not answer, 400 when it refuses. The Viewer showed the app's own read-only view instead, which asked the same Etherpad for the content and showed nothing either; now the error card comes, after an outage with "Try again". The editable address is never handed out. The warning "Could not resolve the read-only pad URL; showing the read-only view instead." is gone. (#410)
- `open` and `open-by-id` answer readers of a public pad on this server with an empty `pad_id`, as `meta-by-id` and `initialize` do; an external client reading it there gets it empty. (#404)
- A public pad imported from an Ownpad `.pad` file whose id is not one of the app's (`nc-…`, `r-…`) stays in Etherpad when its file is deleted for good, also on an Etherpad only this Nextcloud uses; only the binding goes. The import refuses an unbound id in the app's form (`own_name`), and the consistency check offers only "Forget" for such a pad. (#395)
- A legacy Ownpad link to a pad whose other file was deleted for good answers `409 legacy_collision_no_access` until that file's binding is gone, instead of a general error with an offer to try again. (#403)
- When Etherpad does not answer the sweep for a pad an hour or more overdue, the sweep writes a warning with the wait instead of an info line, at most once an hour; `sweep_warned_at` in the app config says when it last did. (#383)
- The connection test's log reasons change: `api_key_mode` is gone (now `api_key_rejected`), a 401 or 403 without code 4 is `denied_in_front` instead of `api_key_rejected`, and a 407 `denied_in_front` instead of `unknown`. (#415)
- A public share counts as protected while it holds a password, or on Nextcloud 35 while `IShare::isPasswordProtected()` says so; a protected share with no or an empty password hash is refused. Admins notice nothing: Nextcloud's shares answer only by their password up to 35.0.2. (#394)
- The README's upgrade section runs `php occ upgrade` instead of disabling and enabling the app, which dropped a limit to groups on every upgrade, and says which to upgrade first. (#418)
- The README and the notes of 1.1.0-beta.2 say that Nextcloud 33 opens a `.pad` file from 33.0.3 on, and that on Nextcloud 34 the pads of folders deleted for good stay until Nextcloud's fix, listed by the consistency check. (#367, #368)

### Fixed

- **Large folders and files deleted for good lose their Etherpad sessions.** Deleting a folder with more than 100 protected pads, or more than 10,000 folders, left the pads beyond the search their sessions for up to six hours while the files were in the trash; a background job now finds them through the bindings and hands their groups to the existing jobs. Protected pads whose files were deleted for good kept their sessions as long where no delete request reached them - a large folder deleted past the trash or from it before its job ran, an account with shared pads; a background job now takes them, whatever `delete_pad_with_file` says. A folder no longer in the file cache leaves its sessions to expire. The walk of a deleted folder took one folder past `MAX_FOLDERS` along, and the delete listener could revoke the sessions of pads an earlier failed delete had found. (#373, #396, #397)
- **The Ownpad migration writes its document last, and only into the file as the open read it.** A file written since stays, and the API answers as for a changed file (409 `pad_file_changed`), for a pad on another server too. If another open has already replaced the shortcut when the migration checks the file, it answers this 409 as well, and the Viewer offers "Try again". Where the file names another pad after the refusal, the binding made for the shortcut goes again, that of an earlier migration of the file too, so the next open can take the pad the file names; an info or warning line says which happened. Without an enabled pad type, the blank entry, a type tile and a template's fallback remove their file only while it is empty, and a file whose id cannot be read stays for its first open. A template's fallback makes the file a blank pad only once emptying it worked; before, it made a pad and a binding next to the template's copy. (#388)
- **"New pad" waits for the Etherpad setup.** Until an Etherpad Base URL is saved - the form saves it only with the API key - "+ New" offers no "New pad", no type tile and no admin templates; with `allow_external_pads` on, the entry stays for the external tile. A create in Files that still meets a missing API address or key - a picker left open, the user's own template, a key removed with `occ` - removes its file again while it is unchanged, instead of leaving an empty `.pad`, and Nextcloud reports a create failure. Requests to the app's API answer as for an Etherpad that cannot be reached (503 with `retryable`); their log writes "Etherpad is not configured: set its host and API key in the Pads admin settings." instead of "Etherpad could not be reached while answering a request.". (#417)
- **A legacy Ownpad link reads its address only from its `[InternetShortcut]` section.** A `URL=` under another section bound the file to another pad, and an empty `URL=` took the next line as the address. Files with a BOM, or with `\r` alone as line ending, are recognised. (#416)
- **Accounts outside the groups the app is limited to no longer load its viewer** and styles on Files and Viewer pages; shared `.pad` files fall back to Nextcloud's own handling for them. Public share links work regardless of the group limit. A pad opened from Activity or Talk gets the Viewer's styles; its frame stayed unstyled there. (#414)
- The connection test recognises a rejected API key by Etherpad's own answer (code 4) instead of its text, and the hint names both possible causes, the key and `authenticationMethod`. A 401 or 403 without code 4, and a 407, get a hint of their own - refused, but not with Etherpad's answer to a wrong key: check the access rules of Etherpad and of proxies and firewalls in front, then the key - and mark no field; before, they read "Etherpad rejected the API key". (#415)
- The app's old public link (`/apps/etherpad_nextcloud/public/{token}`) leads to Nextcloud's share page on installations without pretty URLs too, instead of a 404; the link is built through Nextcloud's own route, with `index.php` where the server needs it. Without `files_sharing` the route answers 404. (#413)
- The connection test's hint on trusted embed origins checks the origins from the form against the cookie domain the form gives, a configured one too, instead of saved origins and a domain derived from the saved Etherpad host. Where the cookie domain itself does not work it stays silent, as the line on protected pads warns already. (#411)
- The restore card of a copy offers "Open the original" only for a `.pad` file the user can read; one blocked by File Access Control, or renamed, was offered and could not be opened. The legacy migration treats such a file as "no access" (409) instead of making the file a copy. (#409)
- A display name such as `/..` in a template path (`{{user}}`) became `..`, and the create was refused; the value now fills exactly one segment, and one with nothing left gives way to the UID. Characters the admin forbade (`forbidden_filename_characters`) become a space in the path instead of failing the create. A UID keeps its leading dots. (#408)
- An Etherpad under a path can be framed; `frame-src` names its origin. (#406)

### Tooling / tests / CI

- The upgrade test pins the checksum of the beta.2 archive and starts from the release before it while the tree carries its version; `protected-readonly-share.spec.ts` checks the reader without Etherpad's API too; `sync-app.sh` updates the app where Nextcloud asks for it (`isUpgradeRequired()`). (#366, #369, #370)
- `tests/load/` measures what open viewers, deletes, the sweep and the admin checks cost, with scripts for a stack of their own. (#381)

## 1.1.0-beta.2 — 2026-10-19

Second beta release for 1.1.0. Focus: pads that keep their history while their files are in the trash, missing pads recovered from the content saved in their files, safer external pads and public links, more reliable revocation of Etherpad sessions - and a great many smaller bug fixes.

### Security

- **An app limited to groups is limited for creating pads too.** Users outside the groups still saw "New pad" in Files and, through Nextcloud's template endpoint - from the menu or by a direct request - created an Etherpad pad with its binding; restoring a `.pad` file from the trash did the same. They now get neither the entry nor the tiles, a create is refused (the file Nextcloud has already made stays, without a pad), and a restore by such a user, `occ trashbin:restore` included, makes no pad. (#359)
- **The trash endpoint deleted pads for anyone who could see the file.** `POST /api/v1/pads/trash` ran for every user the file was visible to, the recipient of a read-only share included, and deleted the owner's pad. The endpoint is removed. (#302)
- **Whether an external pad's server is public is decided on its address alone**, the same on every PHP version and however the address is written. PHP's filter let `::7f00:1` (127.0.0.1 in the deprecated IPv4-compatible form) through on every version, and on PHP 8.1 and 8.2 also IPv4-mapped addresses such as `::ffff:127.0.0.1`, unique-local and link-local addresses with a dotted tail, and NAT64 and 6to4 addresses carrying a private IPv4. Newly refused, also for hosts on the allowlist: `100.64.0.0/10` (carrier-grade NAT, Tailscale), `198.18.0.0/15`, `192.0.0.0/24`, the documentation ranges, multicast, `64:ff9b:1::/48`, `2001::/23` and everything outside `2000::/3` but NAT64 `64:ff9b::/96`. An external pad on such an address can no longer be linked, opened or synced; its saved text stays in the file. (#358)
- **Fetching an external pad stays pinned to the checked address, through a proxy too.** A proxy from the server's environment (`https_proxy`, `all_proxy`) was given the name and resolved it again, and an internationalised, %-encoded or otherwise written IP host missed the pin: a name that pointed to an internal address after the check could reach an internal host - not its content, which TLS checks, but whether something listens there. The connection now goes to the checked address, and a proxy is asked for that address, never the name; a proxy that tunnels only to host names refuses the fetch. Nextcloud's own `proxy` setting still does not apply to it. (#357)
- **Saving the admin settings with external pads switched off emptied their allowlist**, and an empty allowlist admits every public HTTPS host once external pads are switched on again. The stored list is now kept. Administrators who saved the settings that way should check the list. (#320)
- **A deleted account loses its Etherpad sessions**, as on a logout. They stayed valid until they expired, with access to the protected pads shared with it. (#302)
- **Credentials and pad documents stay out of the exception traces Nextcloud serializes.** A logged error records the failing class, its message and its chain of causes without the arguments of each frame, and the objects and methods that carry an API key, a session, a share token or a pad's text keep them out of the trace Nextcloud writes for an exception that escapes. (#276, #307, #308)
- DOMPurify updated to 3.4.17 and the shipped frontend bundles rebuilt. (#310, #350)

### Added

- **Pads Etherpad lost are recovered from their files.** Opening such a file no longer sends anyone to an empty pad Etherpad would create anew: it says the pad is missing (`pad_missing`), and whoever may edit the file makes a new pad from the content saved in it (others get 403). This covers files 1.1.0-beta.1 created from a template too. A file restored from the trash whose pad is lost gets the new pad without asking. A Viewer that was open when the pad was lost no longer writes the empty pad Etherpad made anew over the file as it closes; a pad someone wrote into after that is synced as before. Each question to Etherpad waits at most three seconds; without a clear answer the pad opens as before. (#302)
- **Administrators can delete, or forget, the pads of vanished files**: files that left the file cache without the app seeing a deletion, such as files removed outside Nextcloud that a scan dropped. One listed pad or all of them, after confirming the count; a listed public pad can be forgotten instead and stays in Etherpad. The list shows each pad's access mode, and the total. New admin endpoints `delete-vanished`, `delete-all-vanished` and `forget-vanished`; `consistency-check` also answers with `pending_delete_count`. (#303)
- **Every visitor of a writable public link to a protected pad is an author of their own** in Etherpad, with their own colour and name, as long as their Nextcloud session lives; until now all of them were one author, "Public share". Up to 250 visitors per link and hour get their own; past that, and without a memory cache, they share one author as before. (#334)
- `docs/deleting-pads.md` says what each way of deleting a file does with its pad. (#302)

### Changed

- **The trash keeps a pad.** A `.pad` file in the trash keeps its pad and history, and a restore gives it the same pad back. The pad is deleted once the file is deleted for good - from the trash, past it, or with the account - by a background job within minutes. A protected pad loses its Etherpad sessions when its file is deleted; a public one stays reachable by its link while in the trash. Moving a `.pad` file to the trash, through WebDAV too, no longer loses the changes made since its last sync, and a restore no longer brings back an older snapshot instead of the pad. (#280, #282, #302)
- **`delete_on_trash` is now `delete_pad_with_file`** and decides whether a file deleted for good takes its pad along; the upgrade takes over the value. The admin endpoint `retry-pending-deletes` is now `settle-pending` and deletes only the pads of files deleted for good, answering with `checked`, `settled` and `pending_delete_count` (admin API change); its button reads "Check pending pads". (#302)
- **The consistency check counts and lists only the pads of vanished files** (`vanished_file_count`) and reports only those as a problem. `binding_without_file_count` and `samples.bindings_without_file` are gone: they also counted files deleted for good on their way to the background job. (#302)
- **The supported Nextcloud range starts at 32.0.9.** On Nextcloud 31, and on 32 before 32.0.9, clicking a `.pad` file could download it instead of opening the pad, an error of the Viewer there; Nextcloud 31 is no longer maintained. (#304)
- **Errors are said in the user's language, and say what can be done.** The API's error messages are translated, through shares too. An Etherpad that cannot be reached answers 503 with `retryable`, and the Viewer and the embed page offer "Try again" wherever opening may work later: a locked file, an Etherpad that does not answer, no answer from Nextcloud. Why a pad on another server cannot be linked or read is said in words, with sentences of its own for a redirect (usually a login page) and an untrusted certificate. (#298, #299, #313)
- **The embed create page waits for its create** instead of reporting a network error after ten seconds while the pad and the file were made. `epnc:create-failed` with `reason: 'network'` now means "no answer, outcome unknown", also for a proxy's 502, 503 or 504 and a 5xx page that is not the app's JSON (before: `server`), with a translated hint to look in the folder first, and the event carries the server's `code` and `retryable`. (#300)
- **The connection test asks Etherpad `checkToken`** instead of listing every pad, so its answer no longer carries `pad_count`, and a failed test answers 200 with `ok: false` and the reason, so that a proxy's error page cannot take its place. (#275)
- The sync status and the sync fail on an Etherpad answer without a revision count rather than report "synced" against revision 0. (#282)
- A file id is read strictly - a positive integer - in a request to a public share and on every route; ids such as `1e3` or `7.5` are refused. (#303, #321)
- A file restored from the trash without a pad gets a new one whatever the settings say, where 1.1.0-beta.1 made one only with `delete_on_trash` on; a copy of a `.pad` file that was never opened gets no pad of its own on restore, and opening it still offers the original's. (#302)
- On PHP 8.1 and 8.2 an external pad on `3ff3::/16`, global unicast that PHP's filter refused, is admitted. (#358)

### Removed

- `POST /api/v1/pads/trash`, `POST /api/v1/pads/restore`, and the debug endpoint `POST /api/v1/admin/test-fault` with all its faults and the `test_fault` setting, which the upgrade removes. The three background jobs that retried the trash's deletions give way to one that deletes the pads of files deleted for good. (#302)

### Fixed

- **Pads no longer stay behind a folder, a renamed file, a delete past the trash or a deleted account.** The pads of the `.pad` files in a folder that went to the trash stayed in Etherpad after it was deleted for good, and so did a renamed `.pad` file's pad, the pad of a file deleted past the trash (WebDAV with `X-NC-Skip-Trashbin`, the trash switched off) and the pads of a deleted account's own files. A background job now deletes them within minutes, from a team folder's trash too; pads in team folders stay when an account is deleted. On Nextcloud 34 a folder deleted for good, and a trash emptied as a whole, report their files under the wrong ids until a 34 release has the fix Nextcloud 35.0.1 has: those pads stay, and the consistency check lists them. (#302)
- **The closing sync starts at once.** It waited behind a running sync and was lost with the page; it now goes out at once, even while another sync of the file runs. Two syncs of the same file - two tabs, or the closing sync beside a periodic one - could write the older revision over the newer; with Nextcloud's file locking on, syncs of the same file now run one at a time. (#338)
- **Every Etherpad session of a deleted protected pad goes.** Deleting the file took the group's sessions only as far as two seconds and a hundred deletions reached, the rest staying valid for up to six hours, and a group Etherpad did not answer for kept them all. A background job now takes the rest while the file is in the trash or gone, and stops when it is restored. A legacy group loses its sessions also when its other pad went to the trash before, and sessions Etherpad refuses to delete no longer hold up the collection of expired ones. (#324)
- **Public links no longer pile up Etherpad sessions.** Every open of a writable public link to a protected pad made a session of its own, faster in a loop than they were collected; a link now hands out the session of the last hour per pad again once Etherpad confirms it, and a visitor can write for at least two hours. Opening a pad through a public link is limited to 300 a minute, per address signed out and per user signed in, and reading a public link's content counts a signed-in reader per user too; the Viewer says when a limit refused it. A public link's expired sessions are collected per group; a listing is read up to 4 MiB, and a run that reaches that or keeps timing out pauses for a day with a warning. (#321, #322, #333)
- **Opening a protected pad no longer drops the cookies Nextcloud sets in the same answer**: a public link's visitor whose first request was the open got no Nextcloud session, and a renewed "Stay logged in" did not arrive. (#323)
- Several API endpoints answered 500 where the docs say otherwise: `recover-from-snapshot` on an Etherpad outage, a locked file or an unreadable `.pad` without a binding (now 503 with `retryable`, or 400); `initialize` and `initialize-by-id` for a reader of a read-only share, after pad, group and binding had been made (now 403 before anything is made); `POST /pads`, `from-url` and `from-template` into a missing or read-only folder (now 404 or 403). (#332)
- A reader of a read-only share could call the sync: of an unchanged pad it answered 200 `unchanged`, of one changed since its last sync it had Etherpad asked and then answered 500, with an error in the log. The sync may write the file, so it now answers 403 to whoever may not change it, either way, before the file is read or Etherpad is asked. (#363)
- A recovery that fails because the file is no longer the case it was offered for - another recovery was faster, the file was written meanwhile, or Etherpad has the pad again - makes the Viewer and the embed page open the file again rather than show an error. "Loading pad..." and "No .pad file selected." are translated. (#337)
- The embed page's initialisation and recovery no longer give up after ten seconds; a recovery without an answer opens the file again. (#299)
- The old address `/apps/etherpad_nextcloud/public/{token}` of a password-protected share answered 404 and now leads to the share page; a signed-out visitor of the Viewer routes gets the login instead of an error page. (#321)
- In the admin settings, the connection test before the first save failed with 500; a trusted embed origin with `:443` never matched, so every message from the host page was dropped, the sync before closing included; and an Etherpad that did not answer the API version question on save had 1.2.15 written over the version detected before. (#320)
- The checks of the external pad allowlist and of the trusted embed origins showed a literal `{host}` or `{origin}` instead of the refused entry. (#313)
- An external pad URL with an IPv6 address in brackets works, with an empty allowlist; anything else in brackets is reported as an invalid URL instead of a host that cannot be resolved, and the log names a refused address. (#358)
- A failure to restore the pad no longer turns a restore from the trash into an error that cost the file its versions; it is logged. An Etherpad outage while opening, syncing or reading a pad is logged, once a minute with the file, instead of reaching only the client. (#290, #298)
- A restore resolved the path Nextcloud hands over against a folder called `files` where the user had one, and acted on another file of the same name. (#279)
- Deleting a protected pad Etherpad had already lost left its Etherpad group behind, empty but with its sessions; the group now goes too. (#293)

### Tooling / tests / CI

- The e2e stack installs groupfolders, pinned per Nextcloud major, and the lifecycle cases of the trash and of team folders run as Playwright specs; the shell e2e scripts are gone, and the suite is type-checked and fails on this app's browser errors. (#314, #317, #318, #319)
- PHPUnit runs strictly, Infection mutates the changed lines of every pull request, and targeted runs closed test gaps in the input checks, the pad file parser, the external export request, the Etherpad client and the upgrade and delete paths. (#339, #340, #341, #342, #343, #360)
- Psalm runs on level 1 against a baseline that can only shrink, every PHP file is linted, and the test stubs are checked against what they stand in for. (#278, #283, #345)
- An upgrade test installs the last published release with pads in its users' states, upgrades it and runs the suite, on every supported Nextcloud major for every release. (#361)
- `scripts/prepare-release.sh` sets the version everywhere and builds the bundles for a release, in a copy of the tree, after checking the version, the branch and the texts. (#362)
- Playwright, Vitest, happy-dom, jsdom, Vite, Vue and the GitHub Actions updated.

## 1.1.0-beta.1 — 2026-09-14

First beta release for 1.1.0. Focus: searchable pad content, native Nextcloud Viewer integration, safer legacy imports, and reliable file-type registration on fresh installations.

### Added

- **Pad text is searchable through Nextcloud's full-text search.** When `fulltextsearch`, `files_fulltextsearch` and a search backend such as `fulltextsearch_elasticsearch` are installed, `.pad` files can be found by their stored text instead of only by name. Only the plain-text snapshot is indexed; YAML frontmatter, pad ids and stored HTML remain excluded. Content indexing follows the Files FullTextSearch setting for each storage, so external storages and team folders stay out until an admin turns them on, and installations without the optional search apps are unaffected. Existing pads can be backfilled with `php occ fulltextsearch:index '{"provider":"files","force":true}'`. (#225)

### Security

- **Protected legacy Ownpad imports are disabled by default.** Administrators can enable them where this Nextcloud instance is the only system creating group pads on the Etherpad server. Public legacy pads and existing bindings are unaffected. (#251)
- DOMPurify updated to 3.4.15 and the shipped frontend bundles rebuilt. (#248)

### Changed

- **The pad Viewer now uses Nextcloud's supported Viewer API.** Public shares use Nextcloud's native opening and file actions instead of custom click interception, route watching and public-share UI workarounds. Existing direct links into shared folders remain supported. (#243)
- **Etherpad sessions last longer during normal use.** Sessions for authenticated protected pads now last six hours, while sessions issued through public shares last three hours. Both previously expired after one hour. (#252)
- **The supported Nextcloud range now starts at 31.0.9.** Earlier Nextcloud 31 patch releases fail several lifecycle and migration flows and will no longer be considered compatible. The upper supported release remains Nextcloud 34. (#249)

### Fixed

- **`.pad` files are registered when the app is installed.** A fresh installation now registers the extension, the mimepart used for filtering, and the human-readable `Etherpad` file type — which Nextcloud 32 and newer display, while 31 keeps showing the raw MIME type. The pad icon is served from the app instead of being copied into Nextcloud's signed core directory, avoiding integrity warnings and removal during server upgrades; an upgrade removes a copy an earlier version left behind. Search results receive the app icon through the Full Text Search extension event. (#242)
- **External pad snapshots no longer confuse pad text with snapshot markers.** Both snapshot sections are now always written, with an empty HTML section where necessary. Text containing `[HTML-BEGIN]` or `[HTML-END]` is preserved, no longer reaches the search index truncated and no longer causes repeated no-op synchronisations and file versions. Existing external pads are corrected on their next real sync where needed. (#256)
- **Partial pad creation and restoration failures are cleaned up more safely.** Access-mode decisions and rollback rules now have one implementation, active bindings are removed atomically, and concurrent or pending-delete bindings are preserved. (#238, #241)

### Tooling / tests / CI

- **Pushing a `v*` tag publishes the release.** The tarball is built with `scripts/build-release-tarball.sh`, the same script as locally, and attached to a GitHub release whose body comes from `docs/release-notes/<version>.md`, or from that version's changelog section where no such file exists. A tag is refused before anything is built when it disagrees with `appinfo/info.xml`, when `package.json` or `package-lock.json` have drifted from it, or when there are no release notes to publish; `js/` that is not a fresh build is caught after the build, which is the earliest it can be. (#80)
- CI now exercises the exact Nextcloud floor, the declared PHP 8.1-8.5 range and a Nextcloud 34 full-text-search stack with Elasticsearch. Search checks cover initial indexing, updated snapshots, storage settings and file icons, and fail when the underlying commands or backend fail. (#249, #250, #255, #258)
- Source checks detect consecutive PHP docblocks before annotations or documentation can silently attach to the wrong declaration. (#254)
- Playwright, Vitest and happy-dom updated. (#245, #246, #247)

## 1.1.0-alpha.5 — 2026-09-07

Fifth public-review release. Focus: a view-only share of a protected pad that is view-only on the pad server too, live read-only content, Etherpad session cleanup, Nextcloud 34, and one implementation each for creating, provisioning and opening a pad.

### Added

- **Live read-only view.** A reader without write permission sees the pad as it reads now, fetched from Etherpad, instead of the snapshot last written into the `.pad` file. It refreshes in place and its HTML is sanitised before it is rendered. (#218)
- **Nextcloud 34.** Declared and tested range is now 31 through 34. (#227)
- **Expired Etherpad sessions are collected** in the background, and logging out of Nextcloud revokes the user's active Etherpad sessions. (#213, #214)
- **A public share addresses its pad by file id.** Renaming or moving a shared file no longer breaks the link, and `A+B.pad` can no longer open the pad belonging to `A B.pad`. An id that disagrees with the path, points outside the share or cannot be used is refused rather than falling back to the path. (#235)

### Security

- **A view-only share of a protected pad is view-only on the pad server too.** Until now it opened in the editor: nothing on the authenticated path asked whether the recipient may write, so a "can view" share was issued an ordinary Etherpad session. A protected pad now renders without a session, a public one gets Etherpad's read-only URL - presentation rather than enforcement, since a public pad is reachable by anyone holding its id - and `readOnly` asks the share and the mount it was reached through. (#217, #235)
- **Frontmatter that would be read back as something else is refused.** Unsafe line breaks and NUL bytes are rejected instead of being silently altered or parsed as further keys. (#229, #231)
- Live content responses are not cached, and permissions and binding are checked on every request rather than only at open time. (#218)
- An external pad's export is capped at 5 MiB and follows no redirects, alongside the existing SSRF protections. (#218)
- DOMPurify 3.4.14, bundles rebuilt. (#202)
- The `child-src` override was removed: it is obsolete, interferes with Nextcloud's workers and is incompatible with Nextcloud 34. (#227)

### Changed

- **One way to create a file.** Creation, initialisation and rollback work from a file's identity and its expected content rather than from a path or a node object handed over earlier, so a concurrent move, replacement or duplicate request can no longer make them act on the wrong file. (#219, #220, #221, #222)
- **One way to provision a pad.** A first open and a restore from the trash pick the Etherpad primitive and seed the snapshot through the same path; an access mode neither of them recognises is refused rather than turned into an unprotected pad. (#236)
- **One way to read an open response.** The Viewer and the embed share the missing-metadata check, the payload validation, the read-only decision and the sync interval, which the embed had been taking unbounded. (#237)
- **A `.pad` file is parsed once** and passed through opening and initialisation as structured data. (#230)
- The frontend reads stable error codes such as `missing_frontmatter` instead of matching an English sentence. (#223)
- Time-dependent services read the clock through `ITimeFactory`. (#232)

### Fixed

- **A public-share click on an unhandled type falls back again.** `Viewer.open()` can reject asynchronously, and every rejection was being swallowed, leaving nothing to fall back to after `preventDefault()`. (#228)
- **Existing `.pad` files get their mime *part* repaired** during upgrade, and a folder named `Archive.pad` is no longer rewritten as a pad file. (#233)
- **`.PAD` is a pad file**, including through a DAV link, and every rule about the suffix, the extension, the mime type and the Files address has one home. (#234)
- The `window.OCA.Files.fileActions` registration was removed: the API exists on none of the supported majors, and Nextcloud's own Viewer answers the click. (#228)

### Tooling / tests / CI

- End-to-end coverage for view-only shares, live content, public shares by id, session collection and logout revocation. (#213, #214, #217, #218, #235)
- The CI and e2e matrices cover Nextcloud 34, and `scripts/check-nextcloud-range.py` holds seven files to the range `info.xml` declares. (#227)
- Node `^24.15.0` and npm `^11.0.0` are required and enforced by `npm ci --engine-strict`; on Node 20 two jsdom-pinned suites fail to load while the summary reads as a near miss. (#234)
- `fast-uri`, `browserslist` and `happy-dom` updated. (#215, #216, #224)

## 1.1.0-alpha.4 — 2026-08-31

Fourth public-review release. Focus: one pad-creation entry point with shared templates, a clearer admin surface, protected-pad session and lifecycle correctness, and a disposable end-to-end stack that runs the browser suite across three Nextcloud and two Etherpad majors.

### Added

- **Instance-wide pad templates.** Administrators upload and manage shared `.pad` templates; they appear in Nextcloud's template picker for everyone and always create a fresh Etherpad pad. (#181)
- **One "New pad" entry.** The separate protected/public/external entries in the New menu are replaced by a single entry, with the pad type and any shared template chosen in Nextcloud's own template picker. (#187)
- **Configurable pad types.** Protected and public pads can be enabled or disabled independently; pads of a disabled type keep working, and external pads keep their own setting. (#177)
- **Italian locale**, alongside German, Spanish and French. (#188)

### Changed

- **Admin page grouped into sections**, with the descriptions rewritten. (#178)
- **Connection test reports each part separately** — API, API key, browser-facing Etherpad URL, protected-pad cookie — with field-specific guidance where a setting needs attention. (#179)
- The cookie-domain calculation used by the settings page, the diagnostics and the runtime is one implementation. (#179)

### Security

- **The Etherpad session cookie is `SameSite=Lax`.** It was `None`, so any page on the web could frame a pad URL and a visiting user's cookie went with it. `etherpad_session_cookie_samesite=none` remains for a cross-site embed behind cookie-independent authentication. (#210)
- **`HttpOnly` from Etherpad 3.0.** The release is detected from `/health`; up to 2.7.3 the pad app reads the cookie in the browser, where `HttpOnly` would lock users out of every protected pad. (#209)
- **Deleting a protected pad removes its Etherpad group and that group's sessions**, instead of leaving both behind with nothing pointing at them. (#208)
- **Legacy Ownpad migration checks that a group pad is in the group it names**, closing a path where a hand-written `.pad` could name someone else's Etherpad group and have a session minted for it. (#208)

### Fixed

- **Several protected pads stay open at once.** Opening a second one no longer replaces the first one's Etherpad session. (#206)
- **Opening by file id is trustworthy** for group folders, shares, external storage and files whose path changed. (#205)
- **Pad names keep their special characters**, and Nextcloud judges what a valid name is. (#200)
- **Pad files are created atomically**, and a failed create rolls back the file it made. (#198)
- Creating a pad directly in the user's root folder works. (#196)
- Restoring a `.pad` from the trash works on Nextcloud 31. (#190)
- Cleanup and rollback are consistent when creation, binding, restoration or deletion only partly succeeds. (#198, #205, #208)

### Tooling / tests / CI

- **Disposable Docker e2e stack** — Nextcloud, Etherpad, PostgreSQL and TLS — so the browser suite runs against a throwaway target. (#195)
- **The Playwright suite runs in CI** against Nextcloud 31, 32 and 33 on Etherpad 2, and Nextcloud 33 on Etherpad 3. (#195, #207)
- Browser and unit coverage extended across creation, templates, shares, protected sessions, trash and restore, lifecycle cleanup and the security-sensitive paths. (#53, #138, #194)
- Test runs no longer leave fixtures in the Nextcloud trash. (#194)
- DOMPurify updated and the shipped frontend bundles rebuilt with it. (#144, #166, #189)
- Playwright artefacts excluded from the release tarball. (#136)
- Dependabot PRs auto-merge once CI passes. (#151)

## 1.1.0-alpha.3 — 2026-06-09

Third public-review release. Focus: security hardening of the Etherpad/admin surface, a browser end-to-end test suite, and a deep static-analysis / tech-debt pass. No user-facing feature changes since alpha.2.

### Security

- **API key stored as sensitive app config.** The Etherpad API key is now persisted via the sensitive-value app-config path so it is masked in `occ config` output and admin diagnostics instead of being readable in clear text. (#105)
- **External-pad framing requires an explicit allowlist.** The CSP `frame-src` for external Etherpad hosts is no longer opened implicitly; embedding an external pad now requires the host to be on the trusted-origin allowlist. (#102)
- **Client-side snapshot sanitisation.** Snapshot HTML is sanitised with DOMPurify in the browser before rendering, closing a stored-HTML surface in the viewer/recovery path. (#110)

### Changed

- **Etherpad HTTP via `IClientService`.** Outbound Etherpad API calls go through Nextcloud's HTTP client instead of raw transport, picking up proxy/TLS configuration and consistent timeouts. (#103)
- **Shared pad-sync frontend module.** The viewer and embed entry points now share one extracted pad-sync module instead of duplicating the loop. (#106)
- **No per-request MIME registration.** Dropped the MIME-type registration from the `Application` constructor (it ran on every request); the `.pad` MIME type is owned solely by the `RegisterMimeType` repair step. (#108)
- **Legacy retry job retired.** Removed the compatibility `RetryPendingDeleteJob` shim; the tiered Hot/Warm/Cold `TimedJob`s are the sole retry path for pending pad deletes. (#111)
- Removed a batch of dead code surfaced during the refactors. (#104)

### Tooling / tests / CI

- **Playwright end-to-end suite.** 23 browser tests against a live Nextcloud + Etherpad covering create/open, templates + placeholders, trash/restore, move/rename, orphan recovery, ownership boundary, snapshot round-trip, user-to-user share, public-share view, and the admin health check. (#54)
- **Psalm static analysis** enabled in CI with a baseline (#82), then the baseline was burned down: noise reduction via config + stubs + redundant-cast removal (#122/#133), all real type issues fixed so the type baseline is empty, and `findUnusedCode` turned on with `@psalm-api`-annotated entry points (#122/#134).
- CI now fails the build when committed `js/` assets are stale. (#101)
- Version metadata aligned across `appinfo/info.xml`, `package.json`, and `package-lock.json`, guarded by a version-consistency CI check. (#107, #119)

## 1.1.0-alpha.2 — 2026-05-27

Second public-review release. Focus: localisation cleanup, embed-create host signalling, and CI / release infrastructure.

### Added

- **Embed-create result events.** The `/embed/create-by-parent` flow now emits `epnc:create-succeeded` / `epnc:create-failed` postMessages to the parent host so embedders can react to the create outcome without scraping the iframe. (#95, #96)
- **GitHub Actions CI** (`lint-info-xml`, PHPUnit on PHP 8.2/8.3/8.4, npm-build + vitest, info.xml schema check), Dependabot config for npm, composer, and actions. (#75, #83)
- **Release tarball builder** `scripts/build-release-tarball.sh` for reproducible app-store-style builds. (#74)
- **NC-discoverable app icon** at `img/etherpad_nextcloud.svg` (+ dark variant + 512 px PNG) so the Apps page in NC settings shows the Etherpad icon instead of the generic placeholder. (#84)

### Changed

- **Locale cleanup for 1.1.0.** All maintained locales (`de`, `es`, `fr`) brought to 132/132 keys with no orphans. Source strings consolidated (`Health check` → `Test Etherpad connection`, `Pad file` → `.pad file`, unified `Could not …` / `Unable to …` pairs, `External Etherpad host allowlist`, …). DE locale reviewed end-to-end by a native speaker (`Du`-form, full terminology pass). ES and FR are best-effort first-pass translations done with AI assistance + school-level grammar — usable but expected to receive native-speaker polish via translatewiki once the project is onboarded (#98). Dropped the `de_DE` mirror and the legacy `*.php` catalogs — only `*.json` + `*.js` per locale, alphabetically sorted for clean cross-locale diffs. `docs/i18n.md` rewritten for the new layout. (#77)
- **`appinfo/info.xml` schema** now validates against the official apps.nextcloud.com schema; John McLear added as second author for the Etherpad upstream. (#94)
- Dev dependencies refreshed via Dependabot (actions/setup-node 6, actions/checkout 6, actions/cache 5, shivammathur/setup-php 2.37, vitest 4.1.7, dorny/paths-filter 4, skjnldsv/read-package-engines-version-actions 3).

### Investigated (no code change)

- `fileId=-1` preview 400 on fresh-pad create (#99) — reproduced with plain `+ → New Markdown file`; root cause is hard-coded in NC core's `PreviewController` and not actionable plugin-side. Closed as upstream.
- Tiptap unmount warning on fresh-pad create (#72) — re-diagnosed as NC Text-app `RichWorkspace` mounting on page load (fires regardless of pad create). Not in our flow. Closed as wrong diagnosis.

## 1.1.0-alpha.1 — 2026-05-20

First public-review release. Versioning reset to a clean minor cut with a pre-release marker; not intended for production deployments yet.

### Added

- **Pad templates.** Users can place `.pad` files in their `/Templates` folder and pick them from the `+ → New pad` menu. Mustache-style placeholder substitution for `{{date}}`, `{{user}}`, etc., with same-server access-mode inheritance and a claim-collision guard. See `docs/templates.md`.
- **Legacy Ownpad migration.** `.pad` files in the old `[InternetShortcut]` format are auto-converted on first open. Branching depends on the source URL's origin (same- vs. cross-server) and the pad-id format (`g.X$Y` → protected, anything else → public); claim-collision rule prevents one user's legacy file from claiming another user's bound pad. See `docs/legacy-ownpad-migration.md`.
- **Trusted embed integration** for same-site / trusted-origin hosts:
  - minimal authenticated embed page via `/embed/by-id/{fileId}`
  - trusted `frame-ancestors` / embed-origin allowlist
  - same-origin open flow with CSRF bootstrap inside blank template
- **Trusted embedded create flow** via `/embed/create-by-parent/{parentFolderId}` with redirect into embed viewer.
- **External integration APIs**: `POST /api/v1/pads/create-by-parent`, `POST /api/v1/pads/from-template`, `POST /api/v1/pads/from-url`, `GET /api/v1/pads/meta-by-id/{fileId}`.
- **Preview provider** for `application/x-etherpad-nextcloud` returning the pad-icon as a fallback thumbnail, so the Files app and template picker don't trigger `/core/preview` 4xx responses.

### Changed

- **Architecture cleanup.** `PadController` (347 LOC, 14 actions, 8 service deps) split into `PadCreateController` / `PadSessionController` / `PadLifecycleController` over a shared `AbstractPadController` base — public URL paths unchanged. `ExternalPadExportFetcher` extracted from `EtherpadClient` so the SSRF-hardened external-fetch surface is no longer dragged into services that only need the admin API. `PadLifecycleOperationService` and `PadPathService` folded back into their hosts. Repeated frontmatter-read incantation consolidated into a single `PadFileService::readPad` returning a typed `ParsedPadFile`.
- **Embedded sync UX**: host message hooks for visible/hidden/before-close/sync-now; close-flush ack protocol (`epnc:sync-flush-started|finished|failed`); short lock retries for `.pad` snapshot writes before returning `status=locked`.
- **Protected pad open** is meaningfully faster: earlier iframe start in embed flow, Etherpad author caching per Nextcloud user, author-name sync only on actual display-name changes.

### Fixed

- Fresh `+ → New pad` no longer logs two 4xx network errors on the first `/open` call. The template-flow listener now initialises frontmatter immediately when the user picks the `Blank` option, so the very first open returns 200. Two transient artefacts caused by NC's Files-app placeholder rendering (a `fileId=-1` preview 400 and a Tiptap unmount warning) are out-of-scope for this fix and tracked in #72.

## 1.0.0 - 2026-03-11

- First stable release of **Etherpad Integration for Nextcloud**.
- Native Nextcloud viewer integration for `.pad` files (authenticated and public-share flows).
- Protected/public pad modes with secure session handling for protected pads.
- Admin settings for Etherpad API connection, health check, external public pad policy, and sync interval.
- One-way content sync from Etherpad into `.pad` snapshots (automatic while open + manual trigger).
- Binding-based lifecycle: delete on Nextcloud trash, restore from Nextcloud trash, deferred retries if Etherpad is temporarily unavailable.
- External public pad linking with HTTPS enforcement and SSRF protection.
- NC30–NC33 compatibility with PHPUnit + E2E release checks.

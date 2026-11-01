# Etherpad Sessions

SPDX-License-Identifier: AGPL-3.0-or-later

An Etherpad session grants access to one group until `validUntil`. Anyone
holding its id can use it. App-created protected pads each have their own
group; legacy Ownpad pads may share one.

Sessions last six hours for authenticated users and three hours for public
links. Etherpad does not track Nextcloud login state, so the app handles
session revocation separately.

**Revocation** ends live access. **Collection** removes expired sessions from
Etherpad's indexes.

How to set up the cookie that carries a session - its domain, `SameSite`,
`HttpOnly` - is in [etherpad-integration.md](etherpad-integration.md). What
happens to the pad itself when its file goes is in
[deleting-pads.md](deleting-pads.md). What all this costs is in
[architecture.md](architecture.md), "Cost at scale".

## When access ends

| What happens | Whose sessions end | When |
|---|---|---|
| A logout | Every session of that user, in other browsers too. | In the request; what is left expires. |
| An account deleted | Its own, like a logout, and those of everyone its pads were shared with. | Its own in the request, as the delete starts; the others by a background job. |
| A file moved to the trash, or deleted past it | Every session of the pad's group, whoever it was issued to. | In the request; the rest by a background job, usually within minutes. |
| A folder with more than 100 protected pads deleted | As above, for every pad in it. | The first 100 pads found in the request; the others by a background job, usually within minutes under a system cron. |
| A file seen deleted for good, whatever `delete_pad_with_file` says | As above. | By a background job, usually within minutes; thousands of pads going at once take longer. |
| A share revoked, a permission downgraded, an account disabled, a public link deleted | None. | At `validUntil`. |

A revoked session stops working at once: Etherpad refuses the holder's next
change. A restore gives back nothing already revoked: the next open makes a
new session. A group keeps its sessions while a bound file is in Files, or
while it holds a pad without a binding ("Revoking").

Sessions are left to expire, within six hours unless the pad goes first, when:

- a job could not be queued;
- a run gives up;
- a file's removal is not seen: moved to a trash under new ids, on a storage
  with a wrapped cache, or reported under the wrong ids (see "Deleted for
  good" in [deleting-pads.md](deleting-pads.md)).

## Opening a protected pad

`lib/Service/PadSessionService.php`:

1. Extract the group id from the pad id.
2. Resolve the Etherpad author for the Nextcloud user.
3. Create a session for that group (`createSession`).
4. Set the `sessionID` cookie.
5. Open the regular pad URL.

Every open makes a new session, except on a public link ("Public links").
Etherpad re-checks `validUntil` on every socket message and keeps the session
id it was handed when the pad connected, so a session that expires mid-edit
rejects the next keystroke, and no later cookie reaches that socket. Read in
2.7.3, 3.0.0 and 3.3.3.

### Why the cookie carries more than one session

Keeping only the newest id in the cookie would remove the browser's
credentials for other groups, even while those sessions remained valid: a
second protected pad in a second tab would cost the first tab its access, and
Etherpad would answer 403 for it.

Etherpad accepts a comma-separated list and selects a session for the
requested group. The app therefore preserves eligible ids from the incoming
cookie:

- The ids the browser sent are the candidates. Nothing is added that was not
  already there, so an open never re-issues access to a pad the user has since
  lost.
- `listSessionsOfAuthor` says which of those belong to this Etherpad author,
  which group each is for, and how long it lasts. That keeps one entry per
  group rather than one per open.
- Ids the listing does not know are dropped, and the cookie expires with the
  longest-lived id it keeps, up to 25 of them.

If `listSessionsOfAuthor` is unavailable, the open still happens but carries
only the one fresh id, and logs a warning.

### What the cookie leaves out

An id the current author does not own cannot be attributed. That covers two
cases, and only one of them is harmless:

- A protected public share opens as an Etherpad author of its own ("Public
  links"), so its session looks foreign to a logged-in user's author. A share
  and an authenticated protected pad therefore cannot be open at the same
  time.
- The session of whoever used the browser before looks exactly the same, and
  carrying it would let the next person to log in keep their pad until it
  expired.

Since nothing here can tell those apart, both are dropped.

On a public share the listing is not asked for at all. There the author is a
link's visitor, or the link itself, and Etherpad deletes no sessions; a link
opened in a loop would make every open download its pile.

### What this accepts

**One exposure yields every open pad.** Where the cookie names the shared
parent domain, script on any host under it gets every protected pad the user
has open. Before Etherpad 3.0.0 the cookie cannot be `HttpOnly`. That domain
is how the open request can read the cookie at all, and how it reaches
Etherpad, so it cannot be narrowed.

### The author

The app keeps the Etherpad author id in Nextcloud's user config, per user
(`etherpad_author_id`, with the last synced name in
`etherpad_author_display_name`). A public link's uid (`public-share:*`) stores
neither.

On each authenticated open, it attempts to sync the current display name, even
if the cached name matches: the name may have changed directly in Etherpad, by
a user renaming themselves in the pad or another integrator. If the answer is
a different author id, the cache follows it.

If session creation reports an unknown author, the app resolves the author
mapping again and retries. Other session-creation errors fail the open.

The cached id remains available either way, so a later logout can still find
and revoke the user's sessions. No table of the app's own is involved:
sessions belong to an Etherpad author, the author is cached per uid, and
`listSessionsOfAuthor` answers the rest. If a failed open dropped the id, an
emptied cache could not be told from a user who never opened a protected pad,
and a logout after a brief outage would revoke nothing. So the id stays.

## Revoking

### A logout, and an account deleted

A logout revokes every session of that user, including ones opened in another
browser. That is deliberate: a cookie copied off the machine cannot be
narrowed down by the cookie you can see, and a shared computer is exactly the
case where the copy you can see is not the only one.

What is live is revoked within the budget below, starting with the sessions
this browser carries: the listing arrives oldest first, and the ceiling would
otherwise spend itself before reaching the one in the cookie of the person who
just logged out. What is left over, skipped or refused, is counted and logged.

A session counts as expired only five minutes after its `validUntil`, an
allowance for clock skew: Etherpad judges `validUntil` by its own clock, so a
session ours calls dead may still be honoured there, and skipping it would
leave exactly the access a logout removes.

A logout's failed revoke is not retried. If the pad server cannot be reached
the listing fails, nothing is removed, and the logout carries on regardless,
since a logout may not fail because Etherpad is down. On a shared machine that
leaves live sessions behind and a cookie still naming them; a listener has no
response, so the cookie is never cleared either.

Deleting an account revokes its sessions the same way, as the delete starts
(`RevokeSessionsOnAccountDeleteListener`): Nextcloud removes the account's
settings, the cached author among them, before it reports the account gone. A
delete the user backend then refuses has lost them as a logout would, and the
next open makes new ones. The groups of the protected pads on its home also go
to a job (below), which revokes the sessions of those they were shared with.

### A delete

A delete, to the trash or past it, revokes the sessions of the protected pads
it removes (`RevokeSessionsOnDeleteListener`): the trash keeps the pad, and a
session would keep giving access to it. Nothing here stops the delete.

The pads are found before the delete (`BeforeNodeDeletedEvent`): a file's own
row, or every active protected row under a folder. The folder is walked down
the file cache's `parent` index one level at a time, whatever the files are
called (`ProtectedPadsOfNode`). There is no walk at all when the instance has
no active protected pad. The walk runs in the delete's request and stops past
100 pads or at 10,000 folders, with an `info` line.

The sessions are revoked once the delete is done (`NodeDeletedEvent`). If the
delete fails, for example on a file locked by a sync, no session goes. For
each group, `PadSessionRevoker::revokeForPads()`:

- lists its sessions; a group with no live session asks nothing more;
- revokes them only if the group holds no other pads
  (`ManagedPadLifecycle::groupHoldsOnly()`, with all the pads leaving together
  counted as none): a legacy `.pad` may name someone else's group (see
  "Removing a pad" in [etherpad-integration.md](etherpad-integration.md));
- goes on to the next group only after that, so a slow Etherpad spends the
  budget on deletes rather than on listing every group.

Sessions that expire last go first: they most often belong to whoever is at
the pad now.

### The group's job

After that, every group the delete touched, except one gone already, goes to a
background job (`RevokeGroupSessionsJob`, `GroupSessionRevoker`), whose
argument is the group's id alone. It revokes what the request left: what went
past the budget, what was refused or failed, every group not asked, and a
session an open under way makes after the listing. A group holding other pads
too is the job's to judge.

**A group loses its sessions only when every pad has a binding and every bound
file is outside Files**, either in a trash or absent from the file cache
(`BindingService::anyInFiles()`).

What the job has not revoked by the time a bound file is back in Files stays,
and whoever opens the file gets a session of their own; what was revoked
already is not given back. A pad without a binding also prevents revocation: a
legacy Ownpad group may contain pads the app does not own.

In Files means reachable under a user's `files/`: a trash does not count, nor
a share's mount, whose row outlives the file's move.
`BindingService::anyInFiles()` has the details.

The first pass is scheduled one minute after the delete. After a pass finds no
remaining live sessions, the job checks once more ten minutes later for
sessions created by an open already in progress, and no third time. It stops
without that second check if the group is gone, a bound file is restored to
Files, or a pad has no binding.

Before each session deletion, the job rechecks file locations. Mounts are
checked before the first deletion and whenever a file moves. These checks
protect sessions issued after a restore or through a newly added mount, such
as a team folder joined. File and mount lookups run in batches of 500.

The passes are paced as the collector's are (`SessionSweepJob`): back a minute
later while there is more. A failed pass is retried after one, five and
fifteen minutes; after the third the job stops with a warning, and what is
left expires on its own.

### What a delete did not reach

If the request's walk is incomplete, `RevokeFolderSessionsJob` scans active
protected bindings for files still under the trashed folder and queues their
group jobs. The scan stops if the folder is restored or disappears from the
file cache. Its queries' limits bound what they return, not what the database
reads.

`GoneFilesListener` handles permanent removals, including files purged from a
trash, a folder deleted past the trash, and files belonging to a deleted
account. It queues `RevokeGoneFilesSessionsJob` in batches of up to 100
protected groups, and that job queues each group's own.

### Budgets

| Who | Deletes | Time |
|---|---|---|
| A logout, an account deleted | 25, enough for every id a cookie can hold | 2 s, each call given what is left of it |
| A delete's request | 100, over all the groups it takes | 2 s |
| A pass of `RevokeGroupSessionsJob` | 250 | 20 s |
| A run of the collector | 250, from one listing of at most 4 MiB and 15 s | 20 s |

The delete's request, the job and the collector all delete through
`SessionDeletes`, and a logout does too. A session Etherpad refuses to delete
blocks no other, up to twenty failed or refused deletions in a row and fifty
in a run.

A failure that reads as Etherpad unreachable counts towards an outage: in a
request at once, in a background run only when Etherpad then does not answer
at all. Five with no successful deletion or group listing between them end the
run (`EtherpadClientException::isEtherpadUnreachable()`,
`ManagedPadLifecycle::answers()`, `RunBudget::forRequest()`).

## Known gaps

**Losing access any other way revokes nothing** (the table's last row).
Covering those one event at a time means enumerating every way access can end,
and that list has no natural end. A public link in particular opens under
authors of its own, each visitor's kept only in their session and the link's
never cached, so there is nothing to look the sessions up by. The direction
that does close them is the other one: short sessions that have to be renewed
against a live permission check.

**A session can still expire while someone is editing.** Nothing renews a
session mid-edit, as the pad talks to Etherpad directly once it is open
("Opening a protected pad"). The window is the session's lifetime: six hours
from the open, at least two for a public link.

**Reopening a pad leaves the earlier session behind.** Only the cookie forgets
the previous one, so a pad reopened often carries several live sessions for
one group. A logout has to read all of them ("Collecting expired sessions").

**A logout cannot outrun an open already in flight.** A request that has
passed its permission check can issue a session after a revoke has listed what
to remove. The window is short and the outcome is one more session. A delete's
revocation catches it, through the job's passes above.

**Two Nextclouds pointed at one Etherpad share an author.** The mapper is
`nc:<uid>`, which Etherpad stores globally, so one instance's logout can end
the other's sessions. The mapper is asked for on every open, so changing its
shape re-issues an author for every existing user and orphans their live
sessions. Naming it per instance therefore needs a migration, and is not done
here.

## Collecting expired sessions

Etherpad never removes a session once it expires, and `listSessionsOfAuthor`
walks the author's whole index one awaited lookup at a time, expired entries
included. The cost of an open that lists them would grow with past opens
rather than with live access.

An open leaves the author's id in the job table, and a queued job does the
rest (`CollectExpiredSessionsJob`). The id says which author to look at, not
whether there is anything to collect: that answer is the listing, and the
listing is the slow call, so it belongs in the job together with the deleting.
This also reaches the case a request could not: the first open of a browsing
session carries no cookie ids and so makes no listing.

A public link's open that made a session queues a sweep of the group
(`CollectExpiredGroupSessionsJob`), which collects every author's expired
sessions through `listSessionsOfGroup`, at most hourly while some are live.
That also keeps short the listing a delete's revoke reads. A visitor's author
is collected this way only, so a link's visitors queue one sweep. The link's
own author gets a sweep of its own as well, because a group can hold more
sessions than one run can list.

The id is also all that is stored. A public link's uid -
`public-share:<token>`, or `public-share:<token>:<visitor>` for a visitor -
carries the credential from the share URL, and job arguments are persisted and
printed by `occ`.

**Listing limits.** A sweep's listing is read up to 4 MiB, tens of thousands
of sessions, rather than whole into a job's memory; a revoke's listing is read
whole. An author's index past the cap shrinks only through the sweeps of
groups a public link is opened for, or as a pad's group is deleted for good. A
timeout below the cap points at how fast Etherpad's database answers.

**Retries and parking.** A listing too long to read parks the sweep for a day,
with a warning that says why. One timing out while Etherpad answers otherwise
is tried again a minute later, as passing load may be all it is, and a timeout
on any retry parks it. A parked sweep lists nothing and no open queues it, and
after that day the next open does: an index in use is listed, and warned
about, once a day until it shrinks. A proxy that gives up within the app's 15
seconds answers with an HTTP error, which backs off as any other. A sweep that
gives up says so as info, not a warning: an open queues it anew.

**The next run.** A run requeues itself for the rest. A run with nothing to do
comes back when the earliest session still standing falls due, which also
keeps the next open from queueing a second sweep. An author or group Etherpad
no longer has ends the sweep. Nothing is deleted until five minutes after
expiry, for the clock skew above.

This assumes `deleteSession` removes the id from both indexes.
`tests/e2e/specs/etherpad-session-index.spec.ts` holds this by counting raw
API keys, since the client drops the entries a surviving key leaves. Where
they survive, collecting cannot shrink the index, and the sweep says so in the
log.

Not covered:

- live sessions, which keep an open pad working;
- authors nobody opens a pad for, in a group no public link is opened for;
- an author's index past the cap, when no public link is opened for its
  groups;
- a link's visitors in a group too long to list.

What they leave costs storage, and the length of the listing a revoke reads.
Recording each session's id at issue time would remove the listing; renewing
sessions instead of minting them would remove the pile.

## Public links

### Each visitor's author

Etherpad takes the author of a session over the browser's own, so a writable
link to a protected pad that opened as one author would show all its visitors
as one: one colour, and one name, which any of them could change for all. Each
visitor opens as an author of their own, `nc:public-share:<token>:<visitor>`
(`PublicLinkVisitors`):

- The visitor's id is random and kept in Nextcloud's session of the public
  page, for this link: the same author on every open while that session lives,
  a new one in a new browser session. No cookie of its own. The session keeps
  the Etherpad author too, so an open does not ask Etherpad for it; one
  Etherpad no longer has is asked for anew.
- The app gives a visitor no name. Etherpad lets them set one, which stays on
  their author; a name given on every open would overwrite it.
- A request without the session cookie is a new visitor, and each visitor
  makes an author Etherpad never deletes. So a link has at most 250 visitors
  of their own an hour, and a visitor is counted in every hour they open it.
  Any past the count open as the link itself, `nc:public-share:<token>`, named
  "Public share", one author for all of them. Writing works the same, only the
  colours are shared, and the log says so once an hour for the link.
- Counting takes a memory cache. With only a local one (APCu), the count holds
  for each web server on its own; without one, or while it fails, a visitor
  not yet counted this hour opens as the link. The keys carry an HMAC of the
  token, not the token.
- What the count bounds: a loop of requests without the session cookie makes
  up to 250 authors an hour for a link, 6,000 a day while it runs, and
  Etherpad deletes none. The public open's throttle, 300 calls a minute for an
  address, does not bound it lower.
- In a frame on another site, the browser does not send Nextcloud's session
  cookie, so every open there is a new visitor, until the hour's count, then
  the link. Writing works the same; it costs authors and colours.

### Sessions handed out again

Public links reuse sessions, since Etherpad keeps every session: a session per
open could outrun collection and grow a group's listing past what a revoke can
read in time. `PublicLinkSessions` caches a session for the same author and
group for up to one hour:

- With a distributed memory cache, a visitor makes about one new session an
  hour for each pad, however often they open it. Opens that miss the cache at
  the same moment make one each. A local cache holds for each web server on
  its own, as for the count above. Without any, every open makes a session,
  and only the throttle on the public open bounds them. A cache that fails
  keeps nothing and fails no open, and the log says so, since each open then
  makes a session again.
- The cache only points; Etherpad decides. A kept session is handed out only
  once Etherpad confirms that it exists, that it belongs to the opener's
  author for the pad's group, and that it runs at least as long as a new one
  would, less the time it is kept. A session revoked with the file's trash
  does not pass. A session is kept for an hour. It lasts three, so a visitor
  can write for at least two.
- If Etherpad's answer about a kept session cannot be read, the app logs it
  and makes a new one per open. A check that times out fails the open, as
  making one would only wait out another.
- The key is an HMAC of the Etherpad address, the uid opened as and the group
  under the instance's secret, so it does not give away the token. The value
  is the session id, the credential the cookie carries, in the server's own
  cache.

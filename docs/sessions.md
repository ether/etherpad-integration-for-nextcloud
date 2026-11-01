# Etherpad Sessions

SPDX-License-Identifier: AGPL-3.0-or-later

An Etherpad session is a bearer token for one group with a `validUntil`:
whoever holds its id can open that group's pads until then. Every protected
pad is its own group, or shares one if it came from a legacy `.pad` file. A
session lives six hours, three for a public link's, and Etherpad knows nothing
of Nextcloud's own login, so the app ends what it can itself.

Two words below keep apart what often gets mixed up: **revoking** takes a live
session away, and access ends. **Collecting** removes one already expired, and
only tidies up.

How to set up the cookie that carries a session - its domain, `SameSite`,
`HttpOnly` - is in [etherpad-integration.md](etherpad-integration.md). What
happens to the pad itself when its file goes is in
[deleting-pads.md](deleting-pads.md). What all this costs is in
[architecture.md](architecture.md), "Cost at scale".

## When access ends

| What happens | Whose sessions end | When |
|---|---|---|
| A logout | Every session of that user, in other browsers too. | In the request, up to 25 deletes within two seconds; what is left expires. |
| An account deleted | The account's own, as for a logout, as the delete starts. Those of the people its pads were shared with too. | The account's in the request; the others by a background job. |
| A file moved to the trash, or deleted past it | Every session of the pad's group, whoever it was issued to. | In the request, up to 100 deletes within two seconds; the rest by a background job, usually within minutes. |
| A folder with more than 100 protected pads deleted | As above, for every pad in it. | The first 100 pads found in the request; the others by a background job, usually within minutes under a system cron. |
| A file seen deleted for good, whatever `delete_pad_with_file` says | As above. | By a background job, usually within minutes; thousands of pads going at once take longer. |
| A share revoked, a permission downgraded, an account disabled, a public link deleted | None. | At `validUntil`. |

A group keeps its sessions while a file of it is in Files, or while it holds a
pad of no file ("Revoking"). Where a job could not be queued, a run gives up,
or a file's removal is not seen - moved to a trash under new ids, as on a
storage with a wrapped cache, or reported under the wrong ids (see
[deleting-pads.md](deleting-pads.md)) - the sessions expire on their own,
within six hours, unless the pad goes first. Etherpad refuses the next change
of whoever had the pad open. A restore gives nothing back: the next open makes
a new session.

## Opening a protected pad

`lib/Service/PadSessionService.php`:

1. Extract the group id from the pad id.
2. Resolve the Etherpad author for the Nextcloud user.
3. Create a session for that group (`createSession`).
4. Set the `sessionID` cookie.
5. Open the regular pad URL.

Every open makes a new session, but for a public link's ("Public links"):
Etherpad re-checks `validUntil` on every socket message and keeps the session
id it was handed when the pad connected - read in 2.7.3, 3.0.0 and 3.3.3 - so
a session that expires mid-edit rejects the next keystroke, and no later
cookie reaches that socket.

### Why the cookie carries more than one session

A session grants access to exactly one group, and the cookie is the only place
that state lives. Writing just the new id would revoke whatever the browser
held: a second protected pad in a second tab would take the first tab's access
away, and Etherpad would answer 403 for it.

Etherpad reads the value as a comma-separated list and picks the entry
matching the group being opened, so the others survive the write:

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

### What it deliberately does not carry

An id the current author does not own cannot be attributed. That covers two
cases, and only one of them is harmless:

- A protected public share opens as an Etherpad author of its own ("Public
  links"), so its session looks foreign to a logged-in user's author. A share
  and an authenticated protected pad therefore cannot be open at the same
  time.
- The session of whoever used the browser before looks exactly the same, and
  carrying it would let the next person to log in keep their pad until it
  expired.

Since nothing here can tell those apart, both are dropped. On a public share
the session listing is not asked for at all: there the author is a visitor of
a link, or the link itself, and Etherpad deletes no sessions, so a link opened
in a loop would make every open download its pile.

The cookie is scoped to the domain Nextcloud and Etherpad share, so it reaches
every host under that parent. It is not a pad-host-only cookie, which is how
the open request can read it in the first place.

### What this accepts

- **One exposure yields every open pad.** Script on any host under the shared
  parent domain - the cookie cannot be `HttpOnly` on Etherpad before 3.0.0 -
  gets every protected pad the user has open, not one. Narrowing the domain is
  not available: the cookie has to reach Etherpad.
- **A revoked share stays usable until its session expires.** Opening another
  protected pad does not cut it off. Revoking a share does not end the
  sessions already issued for it: only expiry, a logout, the recipient's
  account being deleted, or the pad's file being deleted clears them.

### The author

For authenticated users the app caches the Etherpad author per Nextcloud user,
in server-side user config: `etherpad_author_id` and
`etherpad_author_display_name`. Not shared across users, and not stored for a
public link's uid (`public-share:*`).

1. Try the cached author id for the current user.
2. Call `createAuthorIfNotExistsFor` with the current display name, on every
   open, even when the cached name still matches. It is the only thing that
   keeps Etherpad's copy of the name in step with Nextcloud's: a name that
   drifted on the Etherpad side - a user renaming themselves in the pad,
   another integrator - is never repaired otherwise. If the answer is a
   different author id, the cache follows it.
3. Build the open context with the cached author id.
4. If anything in that fails with an Etherpad API error, clear the cached
   author state, bootstrap again through `createAuthorIfNotExistsFor`, and
   build the open context again.

The cache keeps the id from being rediscovered from scratch, and holds the
last synced name so a rename is written back only when it changed. It is not a
way to skip the author call. This keeps the author stable across opens without
weakening access checks or moving trust to the client.

No table of the app's own is involved: sessions belong to an Etherpad author,
the author is cached per uid, and `listSessionsOfAuthor` answers the rest.
That cached id is therefore not dropped when an open fails. An emptied cache
cannot be told from a user who never opened a protected pad, and a logout
after a brief outage would revoke nothing.

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
next open makes new ones. The groups of the protected pads on its home go to a
job as well (below), which takes the sessions of those they were shared with.

### A delete

A delete, to the trash or past it, takes the sessions of the protected pads it
takes along (`RevokeSessionsOnDeleteListener`): the trash keeps the pad, and a
session would keep giving it to whoever holds one. Nothing here stops the
delete.

The pads are found before the delete (`BeforeNodeDeletedEvent`): a file's own
row, or every active protected row under a folder, by walking down the file
cache's `parent` index one level at a time (`ProtectedPadsOfNode`), whatever
the files are called. There is no walk at all when the instance has no active
protected pad (an index on state and access mode answers that). The walk runs
in the delete's request and stops past 100 pads or at 10,000 folders, with an
`info` line.

The sessions go once the delete is done (`NodeDeletedEvent`): one that fails,
a file locked by a sync say, takes none. For each group,
`PadSessionRevoker::revokeForPads()` lists its sessions first - most groups
hold none that live, and then nothing else is asked - and takes the live ones
here only when the group holds that pad alone, or nothing
(`ManagedPadLifecycle::groupHoldsOnly()`, the rule a pad's deletion goes by;
all of a group's pads leaving together count as that), which takes no lookup:
a legacy `.pad` may name someone else's group (see "Removing a pad" in
[etherpad-integration.md](etherpad-integration.md)). Group by group: a group's
sessions go before the next group is asked, so a slow Etherpad spends the
budget on the first group's deletes, not on listing them all. Those that
expire last go first: every open makes a session, so a pad opened often holds
more than a cookie can, and those are most often whoever is at it now.

### The group's job

Every group a delete takes a pad of, but one gone already, then goes to a
background job (`RevokeGroupSessionsJob`, `GroupSessionRevoker`), whose
argument is the group's id alone. It takes what did not fit in the request -
past the budget, the refusals and failures a request puts up with, every group
after them unasked, every group Etherpad did not answer for - and a session an
open under way makes after the listing there. A group holding other pads too
is the job's to judge.

**The rule:** a group loses its sessions while every pad of it is away from
Files: its file in a trash, or out of the file cache
(`BindingService::anyInFiles()`). It keeps them for a file back in Files, as
whoever opens a restored file gets a session that is theirs to keep, and for a
pad of no file, a legacy `.pad`'s neighbour say, which was never a delete's to
take.

In Files means under a user's `files/` through a mount Nextcloud keeps for
them: a mount inside a user's `files/` shows all it holds there, a home only
what is under `files/`; a user's trash lies outside, a team folder's beside
the folder's mount, and a `trash/` folder on an external storage inside one. A
share's mount does not count: its row stays in the mount cache until its
user's next login, rooted at the shared file wherever it went, the trash too.
Only the mounts rooted at the file or above it are read, a few of them, from
the mount cache's table: not by the storage's id, which Nextcloud keeps as a
hash once it is longer than 64 characters, and not through `IUserMountCache`,
which remembers a file's place for the rest of the process.

Its first pass runs a minute out and, once a pass leaves nothing, one more
look ten minutes later, and no third - but not after a pass that ended the
job, at a file back in Files, a pad of no file or a group gone. A pass looks
again where the files are before every delete, the first too, in batched
lookups, asking about the mounts before the first delete and after that only
when a file moved: a file restored during a pass, while Etherpad lists the
sessions too, keeps the session its opener just got, and so does a file a
mount made since shows - a team folder joined, say - as a session listed was
made after its maker's mount. The files of a group's pads, and the mounts
above them, are read in batches of five hundred, so a large legacy group with
every file in a trash does not spend a pass's budget before it lists a
session.

The passes are paced as the collector's are (`SessionSweepJob`): back a minute
later while there is more, backed off after a failure (one, five and fifteen
minutes), then left to expire, with a warning that says so. The passes a
second look needs are part of it, and a pass that ends the job takes the job's
own waiting passes with it, but not a row a delete queued since; a delete's
first pass stands down only behind a first pass's retry, which looks again
itself, so a later delete keeps its own second look.

### What a delete did not reach

A folder past the walk's limit goes to `RevokeFolderSessionsJob` once the
delete is done: it pages through the active protected rows, keeps those under
the folder as the file cache has it then, and queues each group's own job. A
folder the file cache no longer has, or one back in Files, ends it. Its
queries' limits bound what they return, not what the database reads.

The files of a folder gone that way are marked as they go
(`GoneFilesListener`), and those marks, as any, go to
`RevokeGoneFilesSessionsJob` as the groups of their protected pads, up to 100
a job, whatever `delete_pad_with_file` says: it queues each group's own job.
That is also how a file purged from a trash, and an account's home, get here.
The setting decides whether a pad stays, not whether what gave access to it
still does.

### Budgets

| Who | Deletes | Time |
|---|---|---|
| A logout, an account deleted | 25, enough for every id a cookie can hold | 2 s, each call given what is left of it |
| A delete's request | 100, over all the groups it takes | 2 s |
| A pass of `RevokeGroupSessionsJob` | 250 | 20 s |
| A run of the collector | 250, from one listing of at most 4 MiB and 15 s | 20 s |

The delete's request, the job and the collector all delete through
`SessionDeletes`, and a logout does too. A session Etherpad refuses to delete
stands in front of no other, up to twenty deletes in a row that do not go
through - refused, or failed - and fifty in a run. A failure that reads as
Etherpad unreachable counts towards an outage: in a background run only when
Etherpad then does not answer at all, as `GoneFileSweep` reads one
(`EtherpadClientException::isEtherpadUnreachable()`,
`ManagedPadLifecycle::answers()`), in a request at once
(`RunBudget::forRequest()`). Five with no delete, nor a group's listings, gone
through between them end the run.

## What stays open

**Otherwise, only expiry.** Losing a share revokes nothing, and neither does a
permission downgrade, a disabled account, or a deleted public link. Covering
them one event at a time means enumerating every way access can end, and that
list has no natural end. A public link in particular opens under authors of
its own, each visitor's kept only in their session and the link's never
cached, so there is nothing to look the sessions up by. The direction that
does close them is the other one: short sessions that have to be renewed
against a live permission check.

**A session can still expire while someone is editing.** Etherpad rejects the
next message rather than the next reload, and nothing renews a session
mid-edit: the pad talks to Etherpad directly once it is open. The window is
the configured lifetime, counted from when the pad was opened.

**Reopening a pad leaves the earlier session behind.** Only the cookie forgets
the previous one, so a pad reopened often carries several live sessions for
one group. A logout is one more reader of an index whose length is the subject
of the next section.

**A logout cannot outrun an open already in flight.** A request that has
passed its permission check can issue a session after a revoke has listed what
to remove. The window is short and the outcome is one more session of the
configured lifetime. A delete's revocation catches it: the job lists the
group's sessions again, a minute later, and once more ten minutes after that
for an open that took longer.

**Two Nextclouds pointed at one Etherpad share an author.** The mapper is
`nc:<uid>`, which Etherpad stores globally, so one instance's logout can end
the other's sessions. Naming it per instance needs a migration - the mapper is
asked for on every open, so changing its shape re-issues an author for every
existing user and orphans their live sessions - and is not done here.

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

A public link's open that made a session leaves the group's id, and the
author's when it opens as the link itself; a visitor with an author of their
own is collected by group alone, so a link's visitors queue one sweep, not one
each. A job for the group (`CollectExpiredGroupSessionsJob`) collects the
expired sessions of every author in it through `listSessionsOfGroup`, coming
back for a session still live an hour on at the soonest. It picks up what the
authors' sweeps leave - a signed-in user's sessions left by a sweep that gave
up or was lost while that user opens no protected pad, and an author index too
long for the author's own sweep - which keeps short the listing a delete's
revoke reads. The author's sweep runs beside it for the link's own author: a
group can hold more sessions than a run can list in time - every user's, and
in a legacy group other pads' - and the author's index, often a smaller one,
is collected all the same. A public link's open makes no listing, whatever ids
the browser carries.

The id is also all that is stored. A public link's uid -
`public-share:<token>`, or `public-share:<token>:<visitor>` for a visitor -
carries the credential from the share URL, and job arguments are persisted and
printed by `occ`.

A run requeues itself for the rest. A listing is read up to 4 MiB, tens of
thousands of sessions, rather than whole into a job's memory; a revoke's
listing is read whole. One too long to read parks the sweep for a day, with a
warning that says why. One timing out while Etherpad answers otherwise is
tried again a minute later, as passing load may be all it is, and a timeout on
any retry parks it. A parked sweep lists nothing and no open queues it, and
after that day the next open does: an index in use is listed, and warned
about, once a day until it shrinks. A public link's author adds a session an
open without a memory cache, and about one an hour for each pad with one
("Public links"); a timeout below the cap points at how fast Etherpad's
database answers. A proxy that gives up before Etherpad's 15 seconds answers
with an HTTP error instead, which is tried again with the backoff as any
other. An author's index past the cap shrinks only through the sweeps of
groups a public link is opened for, or as a pad's group is deleted for good.
An author or group Etherpad no longer has ends the sweep. A refusal is
requeued with a growing delay and a limit. A run with nothing to do comes back
when the earliest session still standing falls due, which also keeps the next
open from queueing a second sweep. Nothing is deleted until five minutes after
expiry, for the clock skew above. A sweep that gives up says so as info, not a
warning: an open queues it anew.

This assumes deleting a session removes its id from the author index, and from
the group's: Etherpad's `deleteSession` takes it out of both. Verified on
2.5.3 with PostgreSQL and on 2.x with the built-in store; an integration test
pins it by counting raw API keys, since the client filters out exactly the
entries a surviving key produces. Where entries do survive, collecting cannot
shrink the index and the sweep says so in the log.

Not covered: sessions still being created, which is what keeps an open pad
working; authors nobody opens a pad for in a group no public link is opened
for; an author's index past the cap when no public link is opened for its
groups; and a link's visitors in a group too long to list. What they leave
costs storage, and the length of the listing a revoke reads. Recording each
session's id at issue time would remove the listing; renewing sessions instead
of minting them would remove the pile.

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
- A visitor is cheap - a request without the session cookie is a new one - and
  each makes an author, which Etherpad never deletes, and sessions. So a link
  has at most 250 visitors of their own an hour, each counted in every hour
  they open it, so ids gathered over hours buy no more. Any past the count
  open as the link itself, `nc:public-share:<token>`, named "Public share",
  one author for all of them. Writing works the same, only the colours are
  shared, and the log says so once an hour for the link. Counting takes a
  memory cache: with only a local one (APCu), the count holds for each web
  server on its own; without one, or while it fails, a visitor not yet counted
  this hour opens as the link. A visitor counted in a later hour, by a server
  whose clock is ahead, is not counted again. The count's keys and the
  session's carry an HMAC of the token, not the token.
- Two first opens of one browser session at the same moment can each draw an
  id; the session keeps the later, and the other author opens once.
- What the count bounds: a loop of requests without the session cookie makes
  up to 250 authors an hour for a link, 6,000 a day while it runs, and
  Etherpad deletes none. The public open's throttle, 300 calls a minute for an
  address, does not bound it lower.
- In a frame on another site, the browser does not send Nextcloud's session
  cookie, so every open there is a new visitor, until the hour's count, then
  the link. Writing works the same; it costs authors and colours.

### Sessions handed out again

A session per open would let a link opened in a loop fill Etherpad with them
faster than the sweep removes them, and the revocation when the file goes to
the trash lists the group's sessions within its two seconds, so a flooded pad
would keep them all. The session made for the visitor, or for the link past
its count, and the pad's group within the last hour is handed out again
instead (`PublicLinkSessions`):

- With a distributed memory cache, a visitor makes about one new session an
  hour for each pad, however often they open it, and a link has at most 250
  visitors of their own and itself in an hour. Opens that miss the cache at
  the same moment make one each. With only a local cache (APCu), which
  Nextcloud then uses in its place, that holds for each web server on its own.
  Without any, every open makes a session, and only the throttle on the public
  open bounds them. A cache that fails - Redis gone, say, even while it is set
  up - keeps nothing and fails no open, and the log says so, since each open
  then makes a session again.
- The cache only points; Etherpad decides. A kept session is handed out only
  once Etherpad confirms that it exists - one taken away with the file's trash
  does not - that it is the opener's author's for the pad's group, and that it
  runs at least as long as a new one would, less the time it is kept. A
  session is kept for an hour, or for a third of its lifetime where that is
  shorter: a public link's session lasts three hours, so a visitor can write
  for at least two, since the next keystroke after it runs out is turned away.
  An answer about a kept session Etherpad gives but this cannot read is
  logged, since the link then makes one an open; a check that times out fails
  the open, as making one would only wait out another.
- The key is an HMAC of the Etherpad address, the uid opened as and the group
  under the instance's secret, so a key does not give away the token, not even
  one chosen by hand. The value is the session id, the credential the cookie
  carries, in the server's own cache.

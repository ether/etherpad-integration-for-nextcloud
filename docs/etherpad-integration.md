# Etherpad Integration

SPDX-License-Identifier: AGPL-3.0-or-later

## Architecture

Etherpad integration is centralized in `lib/Service/EtherpadClient.php`.
All Etherpad operations are executed through HTTP API calls with the configured API version.
For authenticated server-side Etherpad API calls, parameters (including API key) are sent in
`application/x-www-form-urlencoded` POST bodies instead of URL query strings.

Important:

- This app requires Etherpad API key mode (`authenticationMethod: "apikey"`).
- OAuth-only Etherpad configurations are not supported for this integration.

## Used Etherpad API Methods

- `checkToken` – whether Etherpad answers and accepts the API key: the
  connection test, and background jobs telling an outage from one call
  failing
- `createPad`
- `deletePad`
- `getText`
- `setText`
- `getHTML`
- `setHTML`
- `getRevisionsCount`
- `getReadOnlyID`
- `createGroup`
- `createGroupPad`
- `createAuthorIfNotExistsFor`
- `createSession`
- `getSessionInfo`
- `deleteSession`
- `listSessionsOfAuthor`
- `listSessionsOfGroup`
- `listPads`
- `deleteGroup`

### Removing a pad

A public pad is a pad. A protected pad is a pad *inside a group*, plus the
sessions that grant access to that group — and `deletePad` removes only the
first of those three. Every delete used to call it, so a protected pad left
its group and every session ever issued for it behind, with nothing in
Nextcloud pointing at them and nothing to collect them.

`ManagedPadLifecycle::discardIfPresent()` is the one place that decides:

- pad id not shaped `g.<group>$<name>` → `deletePad`;
- otherwise ask Etherpad what the group holds. Exactly this pad and nothing
  else, or nothing at all → `deleteGroup`, which removes the group, its pad
  and its sessions in one call. Anything else → `deletePad`.

The question is asked rather than inferred, because a binding's pad id does
not have to name a group this app created. A legacy Ownpad `.pad` file names
its own pad id and the migration binds it as given, so a file written by hand
naming another user's group would, on a plain shape check, have made deleting
that file destroy their group, their pad and their sessions. A group that
holds only the pad being deleted, or nothing at all, has nothing else to
lose.

It is done when it removed something, the pad or the empty group a
protected pad left behind, and when Etherpad says there was nothing left:
no such pad, or no such group. A caller that has just heard the pad is
gone says so (`knownAbsent`); a public pad then costs no call, a protected
one is still asked about its group.

A group Etherpad cannot list is given up by default: the pad goes alone,
the half that was always safe, and the empty group stays. A caller that
keeps its row and tries again says so (`retried`) and gets the failed read
instead, with nothing removed: the sweep of files gone for good, which
tries again an hour later. One that cannot try again - the clean-up after
a replacement - takes the default.

The shape rule itself lives in `Util\PadId` and is the same one that
classifies a binding as protected. It used to be stricter here, so a pad
bound as protected by the loose rule was not recognised as a group pad by
the strict one and its group was left behind — the leak this section is
about, reached from the other side.

## Pad Types

- `public`
  - Direct pad ID (`nc-...`).
  - No group session required.
- `protected`
  - GroupPad ID (`g.<group>$<name>`).
  - Access only with a valid Etherpad session (`sessionID` cookie).

Either type can be switched off in the admin settings, which stops new pads of
that type from being created. Existing pads keep opening regardless, so the
setting never cuts anyone off from content.

## Session Flow (protected)

Implemented in `lib/Service/PadSessionService.php`.

Normal protected open flow:

1. Extract group ID from pad ID.
2. Resolve Etherpad author context for the Nextcloud user.
3. Create an Etherpad session for that group via `createSession`.
4. Set the `sessionID` cookie — see below, it may carry several ids.
5. Open regular pad URL.

#### Why the cookie carries more than one session

A session grants access to exactly one group, and every protected pad is
its own group. The cookie is the only place that state lives, so writing
just the new id used to revoke whatever the browser held: a second
protected pad in a second tab took the first tab's access away, and
Etherpad answered 403 for it.

Etherpad reads the value as a comma-separated list and picks the entry
matching the group being opened, so the others survive the write:

- the ids the browser sent are the candidates — nothing is added that was
  not already there, so an open never re-issues access to a pad the user
  has since lost;
- `listSessionsOfAuthor` says which of those belong to this Etherpad
  author, which group each is for, and how long it lasts; that is what
  keeps one entry per group rather than one per open;
- ids the listing does not know are dropped, and the cookie expires with
  the longest-lived id it keeps, up to 25 of them.

If `listSessionsOfAuthor` is unavailable, the open still happens but
carries nothing — one fresh id, as before this mechanism existed — and
logs a warning.

#### What this deliberately does not do

An id the current author does not own cannot be attributed. That covers
two cases at once, and only one of them is harmless:

- a protected **public share** opens as an Etherpad author of its own (a
  visitor's, `nc:public-share:<token>:<visitor>`, below), so its session
  looks foreign to a logged-in user's author — a share and
  an authenticated protected pad therefore cannot be open at the same
  time, which was already true before;
- the session of **whoever used the browser before** looks exactly the
  same, and carrying it would let the next person to log in keep their
  pad until it expired.

Since nothing here can tell those apart, both are dropped. On a public
share the session listing is not asked for at all: there the author is a
visitor of a link, or the link itself, and Etherpad deletes no sessions,
so a link opened in a loop would make every open download its pile.

The cookie is scoped to the domain Nextcloud and Etherpad share, so it
reaches every host under that parent — it is not a pad-host-only cookie,
which is how the open request can read it in the first place.

#### The costs this accepts

- **One cookie now carries several sessions.** That is the point of it,
  but it also means a single exposure — script on any host under the
  shared parent domain, since the cookie cannot be `HttpOnly` on Etherpad
  before 3.0.0 — yields every protected pad the user has open rather than
  one. Narrowing the domain is not available: the cookie has to reach
  Etherpad.
- **A revoked share stays usable until its session expires.** Before, an
  open of any other protected pad happened to overwrite the cookie and cut
  it off; that only ever helped if the user opened another pad, and did
  nothing otherwise. Revoking a share does not end the Etherpad sessions
  already issued for it: only expiry, a logout, the recipient's account
  being deleted, or removing the group behind the pad clears them, so the
  window is the session TTL either way — it is just no longer shortened
  by accident.

### Author Resolution Strategy

For normal authenticated users, the plugin now caches Etherpad author state per Nextcloud user in server-side user config:

- cached keys:
  - `etherpad_author_id`
  - `etherpad_author_display_name`
- cache scope:
  - per Nextcloud user
  - not shared across users
  - not persisted for public-share pseudo users (`public-share:*`)

Open-path behavior:

1. Try cached `authorId` for the current Nextcloud user.
2. Call `createAuthorIfNotExistsFor` with the current display name. This
   runs on every open even when the cached name still matches: it is the
   only thing that keeps Etherpad's copy of the name in step with
   Nextcloud's, and a name that drifted on the Etherpad side — a user
   renaming themselves in the pad, another integrator — is never repaired
   otherwise. If the answer is a different author id, the cache follows it.
3. Build the open context with the cached `authorId`.
4. If anything in that fails with an Etherpad API error:
   - clear cached author state
   - retry full author bootstrap through `createAuthorIfNotExistsFor`
   - then build the open context again

What the cache is for:

- it holds the author id, so the id does not have to be rediscovered from
  scratch, and it holds the last synced name so a rename is written back
  only when it actually changed;
- it is not a way to skip the author call — see step 2.

This keeps the author identity stable across opens without weakening access checks or moving trust to the client.

Cookie details:

- Name: `sessionID`
- `secure: true`
- `samesite: Lax`
- `http_only`: depends on the Etherpad release, see below
- Domain handling:
  - if `etherpad_cookie_domain` is set, this value is used as-is
  - if empty, domain is derived from `etherpad_host`
    - two-label host (for example `example.org`) -> `example.org`
    - multi-label host (for example `pad.example.org`) -> `.example.org`
  - derivation is skipped for IP hosts and invalid host values
  - recommendation: use explicit `etherpad_cookie_domain` in multi-subdomain/proxy setups

### Session lifetime and revocation

An Etherpad session is a bearer token for one group with a `validUntil`.
Nothing about losing the Nextcloud session reaches it, so the app takes it
away itself:

- **Logout revokes every session of that user** – including ones opened in
  another browser, which is deliberate: a cookie copied off the machine
  cannot be narrowed down by the cookie you can see, and a shared computer
  is exactly the case where the copy you can see is not the only one.
- **Every open mints a fresh session**, but for a public link's (below).
  Etherpad re-checks `validUntil` on
  every socket message and keeps the session id it was handed when the pad
  connected – read in 2.7.3, 3.0.0 and 3.3.3 – so a session that expires
  mid-edit rejects the next keystroke, and no later cookie reaches that
  socket. Revocation fires on an explicit logout, on the account's
  deletion and on a delete of the pad's file (below), and is capped, so
  for most sessions the lifetime is what bounds the window.
- Expired sessions are left to the background sweep described below. Only
  what is expired by both clocks counts as expired: Etherpad judges
  `validUntil` with its own, so a session ours calls dead may still be
  honoured there, and skipping it would leave exactly the access a logout
  removes. What is live is revoked within a small budget – 25 calls or two
  seconds, with each call given what is left of it – starting with the
  sessions this browser is carrying, since the listing arrives oldest
  first and the ceiling would otherwise spend itself before reaching the
  one in the cookie of the person who just logged out. What is left over,
  whether skipped or refused, is counted and logged.

No table of our own is involved: sessions belong to an Etherpad author, the
author is cached per uid, and `listSessionsOfAuthor` answers the rest. That
cached id is therefore not dropped when an open fails – an emptied cache
cannot be told from a user who never opened a protected pad, and a logout
after a brief outage would revoke nothing.

**A delete of the file revokes the pad's sessions** – every session of
the pad's group, whoever it was issued to, once the file goes to the
trash or past it, for a folder's protected pads too: the trash keeps the
pad, and a session would keep giving it to whoever holds one. The delete's
request takes what fits in two seconds, those that expire last first; a background job
takes the rest while the file stays away, and leaves them once it is
restored. Which groups, and within what budget: `docs/architecture.md`,
"Trash/Restore".

**Deleting an account revokes its sessions**, as a logout does, as the
delete starts (`RevokeSessionsOnAccountDeleteListener`): Nextcloud removes
the account's settings, the cached author among them, before it reports
the account gone. A delete the user backend then refuses has lost them as
a logout would, and the next open makes new ones.

**Otherwise, only expiry.** Losing a share does not revoke anything, and
neither does a permission downgrade, a disabled account, or a deleted
public link. A session issued before any of those stays valid until
`validUntil`.
Covering them one event at a time means enumerating every way access can
end, and that list has no natural end – a public link in particular opens
under authors of its own, each visitor's kept only in their session and
the link's never cached, so there is nothing to look the sessions up by. The direction that does close
them is the other one: short sessions that have to be renewed against a
live permission check.

**A session can still expire while someone is editing.** Etherpad rejects
the next message rather than the next reload, and nothing renews a session
mid-edit – the pad talks to Etherpad directly once it is open. The window
is the configured TTL, counted from when the pad was opened.

**Reopening a pad leaves the earlier session behind.** Every open mints one
and only the cookie forgets the previous, so a pad reopened often carries
several live sessions for one group. A logout is one more reader of an
index whose length is the subject of the next section.

**A logout cannot outrun an open that is already in flight.** A request
that has passed its permission check can issue a session after a revoke has
listed what to remove. The window is short and the outcome is one more
session of the configured lifetime. A delete's revocation catches it: the
background job that follows lists the group's sessions again, a minute
later, and once more ten minutes after that for an open that took longer.

**Two Nextclouds pointed at one Etherpad share an author.** The mapper is
`nc:<uid>`, which Etherpad stores globally, so one instance's logout can
end the other's sessions. Naming it per instance needs a migration – the
mapper is asked for on every open, so changing its shape re-issues an
author for every existing user and orphans their live sessions – and is not
done here.

**A logout's failed revoke is not retried** – a delete's is, by the
background job above. If the pad server cannot be reached the
listing fails, nothing is removed, and the logout carries on regardless –
deliberately, since a logout may not fail because Etherpad is down. On a
shared machine that leaves live sessions behind and a cookie still naming
them; a listener has no response, so the cookie is never cleared either.

### Expired Etherpad sessions

Every open of a protected pad mints a session – since 1.0.0 – and Etherpad
never removes one once it expires. Nothing read the pile until 1.1.0-alpha.4,
when keeping several pads open at once began checking which cookie ids are
still valid: `listSessionsOfAuthor` walks the author's whole index one
awaited lookup at a time, expired entries included. The cost of an open
therefore grows with past opens rather than with live access.

An open leaves the author's id in the job table; a queued job does the rest.
The id says which author to look at, not whether there is anything to
collect – that answer is the listing, and the listing is the slow call, so
it belongs in the job together with the deleting. This also reaches the
case a request could not: the first open of a browsing session carries no
cookie ids and so makes no listing.

A public link's open that made a session leaves the group's id, and the
author's when it opens as the link itself; a visitor with an author of
their own is collected by group alone, so a link's visitors queue one
sweep, not one each. A job for the group
(`CollectExpiredGroupSessionsJob`) collects the expired sessions of every
author in it through `listSessionsOfGroup`, coming back for a session
still live an hour on at the soonest. It picks up what the authors' sweeps
leave - a signed-in user's sessions from before the collector existed, or
left by a sweep that gave up or was lost, while that user opens no
protected pad, and an author index too long for the author's own sweep -
which keeps short the listing a delete's revoke reads. The author's sweep
runs beside it for the link's own author: a group can hold more sessions
than a run can list in time - every user's, and in a legacy group other
pads' - and the author's index, often a smaller one, is collected all the
same. A public link's open makes no listing, whatever ids the browser
carries.

The id is also all that is stored. A public link's uid -
`public-share:<token>`, or `public-share:<token>:<visitor>` for a visitor
- carries the credential from the share URL, and job arguments are
persisted and printed by `occ`.

A run deletes up to 250 sessions within 20 seconds, requeueing itself for
the rest. A listing is read up to 4 MiB, tens of thousands of sessions,
rather than whole into a job's memory. One too long to read parks the
sweep for a day, with a warning that says why; one timing out while
Etherpad answers otherwise is tried again a minute later, as passing load
may be all it is, and a timeout on any retry parks it. A parked sweep
lists nothing and no open queues it, and after that day the next open
does: an index in use is listed, and warned about, once a day until it
shrinks. A public link's author adds a session an open without a memory
cache, and about one an hour for each pad with one (see "A public link's
session" below); a timeout below the cap points at how fast Etherpad's
database answers. A proxy that gives up before Etherpad's 15 seconds
answers with an HTTP error instead, which is tried again with the backoff
as any other. An author's index past the cap shrinks only through the
sweeps of groups a public link is opened for, or as a pad's group is
deleted for good. An author or group Etherpad no longer has ends the
sweep. A revoke's listing is read whole. A refusal is requeued with a
growing delay and a limit; sessions the server will never delete are
skipped rather than allowed to block the ones behind them, up to twenty
refusals in a row and fifty in a run - a failure that reads as Etherpad
unreachable, when Etherpad then does not answer at all, is an outage, and
a few end the run. A run with nothing to do comes back when the earliest
session still standing falls due, which also keeps the next open from
queueing a second sweep. Nothing is deleted until five minutes after
expiry, because Etherpad judges `validUntil` against its own clock and a
session dead by ours may still be live there.

This assumes deleting a session removes its id from the author index, and
from the group's: Etherpad's `deleteSession` takes it out of both.
Verified on 2.5.3 with PostgreSQL and on 2.x with the built-in store; an
integration test pins it by counting raw API keys, since the client filters
out exactly the entries a surviving key produces. Where entries do survive,
collecting cannot shrink the index and the sweep says so in the log.

Not covered: sessions still being created – that is what keeps an open pad
working –, authors nobody opens a pad for in a group no public link is
opened for, an author's index past the cap when no public link is opened
for its groups, and a link's visitors in a group too long to list. What
they leave costs storage, and the length of the listing a revoke reads.
Recording each session's id at issue time would remove the listing;
renewing sessions instead of minting them would remove the pile.

### A public link's visitors

Etherpad takes the author of a session over the browser's own, so a
writable link to a protected pad that opened as one author,
`nc:public-share:<token>`, showed all its visitors as one: one colour, and
one name, which any of them could change for all. Each visitor opens as an
author of their own now, `nc:public-share:<token>:<visitor>`
(`PublicLinkVisitors`):

- The visitor's id is random and kept in Nextcloud's session of the public
  page, for this link: the same author on every open while that session
  lives, a new one in a new browser session. No cookie of its own. The
  session keeps the Etherpad author too, so an open does not ask Etherpad
  for it; one Etherpad no longer has is asked for anew.
- The app gives a visitor no name. Etherpad lets them set one, which stays
  on their author; a name given on every open would overwrite it.
- A visitor is cheap – a request without the session cookie is a new one
  – and each makes an author, which Etherpad never deletes, and sessions.
  So a link has at most 250 visitors of their own an hour, each counted
  in every hour they open it, so ids gathered over hours buy no more. Any
  past the count open as the link itself, under its old name "Public
  share", one author for all of them as before – writing works the same,
  only the colours are shared – and the log says so once an hour for the
  link. Counting takes a memory cache: with only a local one (APCu), the
  count holds for each web server on its own; without one, or while it
  fails, every visitor opens as the link. A visitor counted in a later
  hour, by a server whose clock is ahead, is not counted again. The
  count's keys and the session's carry an HMAC of the token, not the
  token.
- Two first opens of one browser session at the same moment can each
  draw an id; the session keeps the later, and the other author opens
  once.

### A public link's session

Each open of a writable link to a protected pad minted a session of its
own, and a link opened in a loop filled Etherpad with them faster than the
sweep above removes them – and the revocation when the file goes to the
trash lists the group's sessions within its two seconds, so a flooded pad
kept them all. The session made for the visitor, or for the link past its
count, and the pad's group within the last hour is handed out again
instead (`PublicLinkSessions`):

- With a distributed memory cache, a visitor makes about one new session
  an hour for each pad, however often they open it, and a link has at
  most 250 visitors of their own and itself in an hour. Opens that miss the
  cache at the same moment make one each. With only a local cache (APCu),
  which Nextcloud then uses in its place, that holds for each web server
  on its own. Without any, every open makes a session, as before, and
  only the throttle on the public open bounds them. A cache that fails -
  Redis gone, say, even while it is set up - keeps nothing and fails no
  open, and the log says so, since each open then makes a session again.
- The cache only points; Etherpad decides. A kept session is handed out
  only once Etherpad confirms that it exists – one taken away with the
  file's trash does not – that it is the opener's author's for the pad's
  group, and that it runs at least as long as a new one would, less the
  time it is kept. A session is kept for an hour, or for a third of its
  lifetime where that is shorter: a public link's session lasts three
  hours, so a visitor can write for at least two, since the next
  keystroke after it runs out is turned away. An answer about a kept
  session Etherpad gives but this cannot read is logged, since the link
  then makes one an open.
- The key is an HMAC of the Etherpad address, the uid opened as and the group
  under the instance's secret, so a key does not give away the token,
  not even one chosen by hand. The value is the session id, the
  credential the cookie carries, in the server's own cache.

### `SameSite=Lax`

Nextcloud and Etherpad have to share a registrable domain for a protected
pad to work at all – a browser rejects a `Set-Cookie` whose `Domain=` is not
a suffix of the host that set it. So the pad iframe is a same-site
subresource, `Lax` covers it, and a foreign page that frames a pad URL gets
no session cookie with it: the pad renders unauthenticated instead of as the
visiting user.

`Strict` is deliberately not used: it would also withhold the cookie from a
top-level navigation, so a pad link in an email would open unauthenticated.

`None` exists as an opt-in and is never inferred:

```bash
occ config:app:set etherpad_nextcloud etherpad_session_cookie_samesite --value=none
```

It is needed by one deployment: a foreign site framing the embed routes.
Those routes are `NoAdminRequired`, so they need an authenticated Nextcloud
request – and Nextcloud sends its own session cookie as `Lax`, so a
cross-site frame is not logged in through it. What makes such an embed work
anyway is authentication that does not travel in a cookie: a proxy-injected
`REMOTE_USER`, Kerberos, or SAML in environment mode. That is not something
this app can detect, so an admin who runs it says so. The connection test
warns while the setting is on, and names what Nextcloud's own cookie does.

An embed origin under the same registrable domain – `portal.example.org`
framing `cloud.example.org` – is same-site throughout and needs none of
this. The connection test names any trusted embed origin the session cookie
domain does not reach, because that is the configuration that otherwise
fails in silence: the embedded pad gets no session and nothing says why.

A writable protected public share does mint a session, and its page carries
no `frame-ancestors` of its own – but any installed app may add one through
`AddContentSecurityPolicyEvent`, which Nextcloud merges into every response.
`Lax` is the safer value there for exactly that reason, rather than a
guarantee that such a page can never be framed.

### `HttpOnly` and the Etherpad release

Up to Etherpad 2.7.3 the pad app reads `sessionID` in the browser, so the
cookie has to stay script-readable — `HttpOnly` there locks the user out of
every protected pad. From 3.0.0 Etherpad takes the session id out of the
socket.io handshake instead, and the cookie can be withheld from any script
on the page. Measured on 2.7.3, 3.0.0 and 3.3.3.

The boundary is the major version — Etherpad 3 and up — and it lives in
`EtherpadReleasePolicy::HTTP_ONLY_SINCE_MAJOR`. The e2e test restates it
rather than asking the app: `tests/e2e/specs/protected-session-cookie-httponly.spec.ts`.
Moving it means moving both and this table.

The app finds this out from `GET /health` (`releaseId`), which needs no api
key. `/api` cannot answer it: it reports `1.3.1` on both 2.7.3 and 3.3.3.
The answer is cached for an hour, retried at most once a minute after a
failure, dropped after six hours without a successful check, and stored
together with the API host it was read from. Not knowing means a readable cookie.

The connection test in the admin settings shows which release was found and
what the cookie will be, and warns when the two have drifted apart — which
is what a downgrade looks like from the outside.

App config keys. `etherpad_http_only_session_cookie` and
`etherpad_session_cookie_samesite` are the two an admin sets; the rest is
this app's own bookkeeping:

| key | meaning |
| --- | --- |
| `etherpad_http_only_session_cookie` | Exactly `auto` (default), `yes` or `no` – anything else is ignored, with one warning per hour in the log and a line in the connection test. The escape hatch when detection is wrong. `yes` against an Etherpad below 3.0 stops every protected pad from opening. |
| `etherpad_http_only_override_warned_at` | when the warning above was last written, so it is one line an hour rather than one per pad open |
| `etherpad_release_failed` | JSON: when a check last failed, and for which host. Its own value, because a failure has nothing to say about the release – folding the two together made every failure overwrite the record. |
| `etherpad_session_cookie_samesite` | Exactly `lax` (default) or `none` – anything else, `strict` included, is ignored and named by the connection test. Only for a cross-site embed behind cookie-independent authentication, see above. |
| `etherpad_release_state` | JSON: the detected release, the API host it was read from, when it was last confirmed, and when a check last failed. One value on purpose – a check that finishes after the app has been repointed can only write a record that says which server it is about, and the next reader discards it. |

```bash
occ config:app:set etherpad_nextcloud etherpad_http_only_session_cookie --value=no
```

`tests/e2e/specs/protected-session-cookie-httponly.spec.ts` holds this: one
`sessionID` cookie per protected open, `Secure`, `SameSite=Lax`, and
`HttpOnly` from Etherpad 3 on; CI runs it against Etherpad 2 and 3.

## Read-only Behavior

A share without write permission gets no way to edit, whether it is a
public link or an internal share with another user. Both are decided the
same way and reach the same two answers.

- **Protected pads are rendered by this app**, and no Etherpad session is
  created at all. The content is fetched fresh on every open, over the
  Etherpad API for own pads and over the public HTML export for pads on
  other servers – the stored `.pad` snapshot is not a fallback, and a
  failed fetch shows an error with a retry rather than an older text.
  - Only a small tag whitelist survives (`p`, lists, headings, basic inline
    formatting, block/code tags, and `a`); dangerous tags are stripped, on
    the server and again in the browser.
  - Links stay clickable, and are the only place an attribute survives:
    `href` is kept when it names http, https or mailto – an allowlist, not
    a `javascript:` denylist – and `target="_blank"` with
    `rel="noopener noreferrer"` is set by both stages. Anything else keeps
    its text and loses the link. Colours and author highlighting are still
    dropped.
  - The fetch runs behind its own endpoint, which re-checks the share and
    the `.pad` binding on every call. The binding check is what keeps an
    edited `.pad` file from pointing this app's API key at somebody else's
    pad. A "Refresh" button re-runs that one request – not the whole open –
    so catching up on changes costs one checked read. The text on screen
    stays while it runs; a refresh that fails is reported beside the button
    and leaves the last content in place.
  - Both fetch paths stop at 5 MiB. A pad past that is reported as too
    large to preview (`pad_too_large`) and stays editable as usual; the
    limit exists because the view is read per reader on demand, and the
    body is held twice, once as text and once as the DOM parsed from it.
  - Etherpad's own read-only view is deliberately **not** used here.
    `SecurityManager` resolves a read-only id back to the real pad before
    any check, so the view needs the same group session as the editable
    one – and the editable pad id is written in plain text in the `.pad`
    file, which a read-only share still lets the recipient read. Handing
    over a session would therefore hand over editing, with the id supplied
    by the file itself. Etherpad has no session that grants reading but not
    writing.
- **Public pads get Etherpad's read-only URL** (`getReadOnlyID`), which is
  presentation rather than enforcement: a public pad is editable by anyone
  who has its id, and its id is in the `.pad` file. Nothing can be enforced
  there, so Etherpad's own live view is preferred. When the pad server
  cannot say what that URL is, the pad falls back to this app's read-only
  view rather than to the editable address.

The decision is `isUpdateable()` on the file as this user sees it, which
is the update permission bit – `(permissions & UPDATE)` – and not a lock
check. The share feeds into it, and so does the mount: a read-only
external storage or a group folder ACL puts the pad into the snapshot view
just as a "can view" share does. Not because edits would be lost –
Etherpad stores the pad itself, and a failed sync leaves a stale copy here
rather than lost text – but because an open without write permission in
Nextcloud may not issue a session that writes on the pad server.

One file can be reachable by several paths with different permissions –
shared directly and again inside a shared folder – so the id lookup
prefers a path the user may write. Otherwise whichever mount came first
would decide, and the open, the metadata and the sync could end up on
different ones.

The read-only id itself is random – `r.` plus sixteen characters, stored as
a `pad2readonly` / `readonly2pad` mapping – so it reveals nothing about the
pad it belongs to.

## Share Permission Mapping

Both open paths map Nextcloud share permissions the same way –
`PublicViewerController` for links, `PadOpenService` and
`PadMetadataService` for authenticated users:

- protected share without update permission -> local `.pad` text snapshot, no Etherpad cookie
- public pad share without update permission -> Etherpad read-only URL
- share with update permission -> Etherpad editable

## Error Handling

- API errors are propagated as `EtherpadClientException`.
- HTTP >= 400 and invalid JSON are treated as explicit failures.
- Critical lifecycle flows log failures and abort in a controlled way (no silent best effort).
- Protected open keeps author-cache fallback defensive:
  - stale cached author IDs are cleared automatically when session creation fails
  - author name sync failures do not block pad opening

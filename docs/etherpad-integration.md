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

- `createPad`
- `deletePad`
- `getText`
- `setText`
- `getHTML`
- `getRevisionsCount`
- `getReadOnlyID`
- `createGroup`
- `createGroupPad`
- `createAuthorIfNotExistsFor`
- `createSession`
- `listSessionsOfAuthor`
- `listPads`
- `deleteGroup`

### Removing a pad

A public pad is a pad. A protected pad is a pad *inside a group*, plus the
sessions that grant access to that group — and `deletePad` removes only the
first of those three. Every delete used to call it, so a protected pad left
its group and every session ever issued for it behind, with nothing in
Nextcloud pointing at them and nothing to collect them.

`ManagedPadLifecycle::discard()` is the one place that decides:

- pad id not shaped `g.<group>$<name>` → `deletePad`;
- otherwise ask Etherpad what the group holds. Exactly this pad and nothing
  else → `deleteGroup`, which removes the group, its pad and its sessions in
  one call. Anything else → `deletePad`.

The question is asked rather than inferred, because a binding's pad id does
not have to name a group this app created. A legacy Ownpad `.pad` file names
its own pad id and the migration binds it as given, so a file written by hand
naming another user's group would, on a plain shape check, have made deleting
that file destroy their group, their pad and their sessions. A group that
holds only the pad being deleted has nothing else to lose.

A group that is not there at all answers `groupID does not exist`, which the
callers read as "already gone" — correct, since a pad inside a group that
does not exist cannot exist either.

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

- a protected **public share** is its own Etherpad author (`nc:public-share:<token>`),
  so its session looks foreign to a logged-in user's author — a share and
  an authenticated protected pad therefore cannot be open at the same
  time, which was already true before;
- the session of **whoever used the browser before** looks exactly the
  same, and carrying it would let the next person to log in keep their
  pad until it expired.

Since nothing here can tell those apart, both are dropped. On a public
share the session listing is not asked for at all: the author there comes
from the share token, so every anonymous visitor of one link shares it,
and Etherpad deletes no sessions — a link opened by hundreds of people
would make every open download hundreds of entries.

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
  nothing otherwise. Nothing in the app deletes Etherpad sessions, so the
  window is the session TTL either way — it is just no longer shortened by
  accident.

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
- **Every open mints a fresh session.** Etherpad re-checks `validUntil` on
  every socket message and keeps the session id it was handed when the pad
  connected – read in 2.7.3, 3.0.0 and 3.3.3 – so a session that expires
  mid-edit rejects the next keystroke, and no later cookie reaches that
  socket. What bounds the window is revocation, not a shorter lifetime.
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

**Only logout.** Losing a share does not revoke anything, and neither does
a permission downgrade, a deleted or disabled account, or a deleted public
link. A session issued before any of those stays valid until `validUntil`.
Covering them one event at a time means enumerating every way access can
end, and that list has no natural end – a public link in particular opens
under its own Etherpad author whose id is deliberately never cached, so
there is nothing to look the sessions up by. The direction that does close
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

**Revocation cannot outrun an open that is already in flight.** A request
that has passed its permission check can issue a session after a revoke has
listed what to remove. The window is short and the outcome is one more
session of the configured lifetime.

**Two Nextclouds pointed at one Etherpad share an author.** The mapper is
`nc:<uid>`, which Etherpad stores globally, so one instance's logout can
end the other's sessions. Naming it per instance needs a migration – the
mapper is asked for on every open, so changing its shape re-issues an
author for every existing user and orphans their live sessions – and is not
done here.

**A failed revoke is not retried.** If the pad server cannot be reached the
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
it belongs in the job together with the deleting. This also reaches the two
cases a request could not: the first open of a browsing session carries no
cookie ids and so makes no listing, and a public link never carries any,
although every visitor of one adds a session under the same shared author.

The id is also all that is stored. A public link's uid is
`public-share:<token>`, the credential from the share URL, and job
arguments are persisted and printed by `occ`.

A run deletes up to 250 sessions within 20 seconds, requeueing itself for
the rest. A refusal is requeued with a growing delay and a limit; a single
session the server will never delete is skipped rather than allowed to
block the ones behind it. A run with nothing to do comes back when the
earliest session still standing falls due, which also keeps the next open
from queueing a second sweep. Nothing is deleted until five minutes after
expiry, because Etherpad judges `validUntil` against its own clock and a
session dead by ours may still be live there.

This assumes deleting a session removes its id from the author index.
Verified on 2.5.3 with PostgreSQL and on 2.x with the built-in store; an
integration test pins it by counting raw API keys, since the client filters
out exactly the entries a surviving key produces. Where entries do survive,
collecting cannot shrink the index and the sweep says so in the log.

Not covered: sessions still being created – that is what keeps an open pad
working – and authors nobody opens a pad for, whose leftovers cost storage
only. Recording each session's id at issue time would remove the listing;
renewing sessions instead of minting them would remove the pile.


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
`EtherpadReleasePolicy::HTTP_ONLY_SINCE_MAJOR`. The two test layers restate
it rather than asking the app: `tests/e2e/specs/protected-session-cookie-httponly.spec.ts`
and `tests/integration/e2e-protected-cookie-contract.sh`. Moving it means
moving all three and this table.

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

Regression safety check:

- `tests/integration/e2e-protected-cookie-contract.sh` validates the protected open response cookie contract:
  - one `sessionID` `Set-Cookie` header from app flow
  - includes `Secure` and `SameSite=Lax`
  - `HttpOnly` present exactly when the pad server's `/health` reports a
    release of 3 or newer; skipped with a note when `/health` cannot be
    reached from where the script runs

## Read-only Behavior

A share without write permission gets no way to edit, whether it is a
public link or an internal share with another user. Both are decided the
same way and reach the same two answers.

- **Protected pads render the stored snapshot**, and no Etherpad session is
  created at all.
  - If an HTML snapshot is stored, only a small tag whitelist is rendered
    (`p`, lists, headings, basic inline formatting, block/code tags);
    attributes and dangerous tags are stripped. Otherwise the text snapshot
    is used.
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
  there, so the live read-only view is preferred over a snapshot that would
  only be staler.

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

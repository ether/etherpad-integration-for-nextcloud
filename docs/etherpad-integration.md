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

### Expired Etherpad sessions

Every open of a protected pad mints a session – that has been true since
1.0.0 – and Etherpad never removes one once it expires. The pile was free
while nothing read it. Since 1.1.0-alpha.4 something does: keeping several
protected pads open at once means checking which of the ids in the cookie
are still valid, and that asks `listSessionsOfAuthor`, which walks the
author's whole index one awaited lookup at a time, expired entries
included.

So the cost of an open grows with the number of past opens rather than
with the number of sessions that still grant anything. Ten opens a day is
a few thousand entries in a year, and each one is a round trip inside
Etherpad before the pad appears.

No request deletes them, and no request finds out whether there are any
– the listing is the call that gets slow, so asking is as much the problem
as deleting. Every open of a protected pad leaves the author's id in the
job table instead, and the sweep does both: it lists, and it deletes what
has expired. Up to 250 per run inside a 20-second budget that no single
call may overrun, requeued for whatever did not fit. A run that ended on a
refusal – for the listing or for a delete – is requeued with a growing
delay and a limit instead: a queued job is removed from the list before it
runs, so a pad server hiccup would otherwise leave the pile for the next
open to rediscover, and a refusal read as progress would have the job
coming back every minute for good. A single session the server will never
delete does not stop the run; it is skipped past, and only a handful of
refusals ends one.

The id alone is enough, and that is the point. A listing is only made when
the browser carries session ids, so the first open after arriving made
none – and a public link never does, while every visitor of one link
writes to the same shared author's index. Both were invisible to a sweep
that had to be told there was a backlog.

The author id is also all that is written down. A public link's Nextcloud
uid is `public-share:<token>` – the credential out of the share URL – and
a job argument is persisted in the jobs table, printed by `occ`, and
carried into database dumps and support bundles. The job has no use for
it.

A sweep that finds nothing to collect does not simply end: it comes back
when the earliest session still standing falls due. That row is also what
tells the next open a sweep is already accounted for, so a busy public
link does not queue another walk of one shared index behind every
visitor.

This rests on one property of the pad server: deleting a session takes its
id out of the author's index, so the listing gets shorter. Etherpad
updates that index with `setSub(..., undefined)`, and there are reports of
the key surviving on some versions and storage backends – which would
leave an id the listing still walks and `deleteSession` will no longer
take, since it answers that the session does not exist. Collecting cannot
help there. Measured to hold on 2.5.3 with PostgreSQL, where a swept
author's index record disappears entirely, and on 2.x with the built-in
store; an integration test pins it against whatever Etherpad the suite
runs, counting the raw keys of the API response rather than what the
client hands back, because the client drops exactly the entries a
surviving key produces. When such entries do turn up, the sweep says so in
the log instead of reporting a clean run.

Nothing is deleted until five minutes after it expired. `validUntil` is a
number Nextcloud computes and Etherpad judges against its own clock, and
in the window where the two disagree a session this side calls dead is one
the pad server still grants – deleting it there would close a socket
somebody is typing into. Queueing is best-effort too: it writes to the
jobs table from inside an open, and housekeeping is not allowed to be the
reason a pad fails to open.

What this does not do is stop the sessions being created. That is a
property of issuing a fresh session per open, which is what keeps an
already-open pad working; the collector only keeps the record of it from
growing without end. Noting the id and expiry of each session as it is
issued would let the sweep skip the listing entirely; renewing one session
instead of minting another would remove the reason for the pile. Both are
larger changes than this.

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

- Read-only URL is built via `getReadOnlyID`.
- Authenticated protected GroupPad opens still require a session.
- Public read-only shares of protected GroupPads do not create an Etherpad session.
  - They render the last synced snapshot stored in the `.pad` file.
  - If an HTML snapshot is stored, only a small tag whitelist is rendered (`p`, lists, headings, basic inline formatting, block/code tags); attributes and dangerous tags are stripped.
  - If no HTML snapshot is stored, the viewer falls back to the text snapshot.
  - This prevents the public share response from setting a session cookie that could also open the writable GroupPad URL.

## Share Permission Mapping

`PublicViewerController` maps Nextcloud share permissions:

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

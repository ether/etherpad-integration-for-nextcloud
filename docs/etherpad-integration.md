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

## Sessions and the cookie

A protected pad opens through an Etherpad session, which the app makes, carries in
a cookie and takes away again: [sessions.md](sessions.md). What follows is how that
cookie has to be set up.

- Name: `sessionID`
- `secure: true`
- `samesite: Lax`
- `http_only`: depends on the Etherpad release, see below
- Domain: `etherpad_cookie_domain` and `etherpad_cookie_domain_configured` in `docs/api-reference.md` ("App Config Keys") say which one, and when there is none

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
  cannot say what that URL is, the open fails as other calls to it do:
  `503` where it does not answer, `400` where it refuses the pad. This
  app's own view would ask the same Etherpad for the content, and the
  editable address never stands in.

The decision is `isUpdateable()` on the file as this user sees it, which
is the update permission bit – `(permissions & UPDATE)` – and not a lock
check. The share feeds into it, and so does the mount: a read-only
external storage or a group folder ACL puts the pad into a read-only view –
this app's for a protected pad, Etherpad's for a public one – just as a
"can view" share does. Not because edits would be lost –
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
`PadMetadataService` for authenticated users. Where Etherpad cannot name
the read-only URL, the metadata answers the address empty and the open
fails:

- protected share without update permission -> this app's read-only view, its content fetched on every open and refresh, no Etherpad cookie
- public pad share without update permission -> Etherpad read-only URL
- share with update permission -> Etherpad editable

## Error Handling

- API errors are propagated as `EtherpadClientException`.
- HTTP >= 400 and invalid JSON are treated as explicit failures.
- Critical lifecycle flows log failures and abort in a controlled way (no silent best effort).
- Protected open keeps author-cache fallback defensive:
  - an author Etherpad no longer knows is resolved again when session creation reports it; other errors fail the open, and the cached ID stays for a later logout
  - author name sync failures do not block pad opening

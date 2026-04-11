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

## Pad Types

- `public`
  - Direct pad ID (`nc-...`).
  - No group session required.
- `protected`
  - GroupPad ID (`g.<group>$<name>`).
  - Access only with a valid Etherpad session (`sessionID` cookie).

## Session Flow (protected)

Implemented in `lib/Service/PadSessionService.php`.

Normal protected open flow:

1. Extract group ID from pad ID.
2. Resolve Etherpad author context for the Nextcloud user.
3. Create Etherpad session via `createSession`.
4. Set `sessionID` cookie.
5. Open regular pad URL.

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
2. If the current display name differs from the cached synced name:
   - call `setAuthorName`
   - update cached name
3. Try `createSession` with cached `authorId`.
4. If session creation fails with an Etherpad API error:
   - clear cached author state
   - retry full author bootstrap through `createAuthorIfNotExistsFor`
   - then create session again

Why this exists:

- Without caching, a normal protected open typically required:
  - `createAuthorIfNotExistsFor`
  - `setAuthorName`
  - `createSession`
- With cache hit and unchanged display name, the hot path is usually only:
  - `createSession`

This reduces Etherpad API round-trips on repeated opens without weakening access checks or moving trust to the client.

Cookie details:

- Name: `sessionID`
- `secure: true`
- `samesite: None`
- `http_only: false` (runtime compatibility with current Etherpad/socket flow on this deployment)
- Domain handling:
  - if `etherpad_cookie_domain` is set, this value is used as-is
  - if empty, domain is derived from `etherpad_host`
    - two-label host (for example `example.org`) -> `example.org`
    - multi-label host (for example `pad.example.org`) -> `.example.org`
  - derivation is skipped for IP hosts and invalid host values
  - recommendation: use explicit `etherpad_cookie_domain` in multi-subdomain/proxy setups

Regression safety check:

- `tests/integration/e2e-protected-cookie-contract.sh` validates the protected open response cookie contract:
  - one `sessionID` `Set-Cookie` header from app flow
  - includes `Secure` and `SameSite=None`
  - excludes `HttpOnly` (current runtime compatibility requirement)

## Read-only Behavior

- Read-only URL is built via `getReadOnlyID`.
- Protected GroupPads still require a session.
- Therefore protected/read-only first sets session, then opens read-only URL.

## Share Permission Mapping

`PublicViewerController` maps Nextcloud share permissions:

- share without update permission -> Etherpad read-only
- share with update permission -> Etherpad editable

## Error Handling

- API errors are propagated as `EtherpadClientException`.
- HTTP >= 400 and invalid JSON are treated as explicit failures.
- Critical lifecycle flows log failures and abort in a controlled way (no silent best effort).
- Protected open keeps author-cache fallback defensive:
  - stale cached author IDs are cleared automatically when session creation fails
  - author name sync failures do not block pad opening

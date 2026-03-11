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

1. Extract group ID from pad ID.
2. Resolve author via `createAuthorIfNotExistsFor`.
3. Create session via `createSession`.
4. Set `sessionID` cookie.
5. Open regular pad URL.

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

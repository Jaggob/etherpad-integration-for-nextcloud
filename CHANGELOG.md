# Changelog

## Unreleased

- Trusted embed integration for same-site / trusted-origin hosts:
  - minimal authenticated embed page via `/embed/by-id/{fileId}`
  - trusted `frame-ancestors` / embed-origin allowlist
  - same-origin open flow with CSRF bootstrap inside blank template
- Trusted embedded create flow:
  - minimal launcher page via `/embed/create-by-parent/{parentFolderId}`
  - same-origin pad creation with redirect into embed viewer
- External integration APIs:
  - `POST /api/v1/pads/create-by-parent`
  - `GET /api/v1/pads/meta-by-id/{fileId}`
- Embedded sync improvements:
  - host message hooks for visible/hidden/before-close/sync-now
  - close-flush ack protocol (`epnc:sync-flush-started|finished|failed`)
  - short lock retries for `.pad` snapshot writes before returning `status=locked`
- Protected pad open performance:
  - earlier iframe start in embed flow
  - Etherpad author caching per Nextcloud user
  - author name sync only on actual display-name changes

## 1.0.0 - 2026-03-11

- First stable release of **Etherpad Integration for Nextcloud**.
- Native Nextcloud viewer integration for `.pad` files (authenticated and public-share flows).
- Protected/public pad modes with secure session handling for protected pads.
- Admin settings for Etherpad API connection, health check, external public pad policy, and sync interval.
- One-way content sync from Etherpad into `.pad` snapshots (automatic while open + manual trigger).
- Binding-based lifecycle: delete on Nextcloud trash, restore from Nextcloud trash, deferred retries if Etherpad is temporarily unavailable.
- External public pad linking with HTTPS enforcement and SSRF protection.
- NC30–NC33 compatibility with PHPUnit + E2E release checks.

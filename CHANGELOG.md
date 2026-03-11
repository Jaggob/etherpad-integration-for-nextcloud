# Changelog

## 1.0.0 - 2026-03-11

- First stable release of **Etherpad Integration for Nextcloud**.
- Native Nextcloud viewer integration for `.pad` files (authenticated and public-share flows).
- Protected/public pad modes with secure session handling for protected pads.
- Admin settings for Etherpad API connection, health check, external public pad policy, and sync interval.
- One-way content sync from Etherpad into `.pad` snapshots (automatic while open + manual trigger).
- Binding-based lifecycle: delete on Nextcloud trash, restore from Nextcloud trash, deferred retries if Etherpad is temporarily unavailable.
- External public pad linking with HTTPS enforcement and SSRF protection.
- NC30–NC33 compatibility with PHPUnit + E2E release checks.

# End-to-end tests (Playwright)

SPDX-License-Identifier: AGPL-3.0-or-later

Browser-driven smoke tests for the flows that vitest + happy-dom can't
cover: NewFileMenu create, viewer mount, public-share view, lifecycle.
See issue #54.

## What it talks to

The specs are **target-agnostic** — they drive whatever Nextcloud
instance `E2E_BASE_URL` points at. For local development that's easiest
against an existing test instance (your own NC, or the shared test
server). A reproducible Docker NC+Etherpad target for CI is a later
phase; because the specs only depend on `E2E_BASE_URL`, adding it won't
require rewriting tests.

> Use a **dedicated throwaway test account**. The specs create and delete
> `.pad` files on the target instance.

## Setup

```bash
# 1. install the browser binaries once (Playwright itself is a devDep)
npx playwright install chromium

# 2. configure your target
cp tests/e2e/.env.e2e.example tests/e2e/.env.e2e
$EDITOR tests/e2e/.env.e2e      # fill in E2E_BASE_URL / E2E_LOGIN_URL / E2E_USER / E2E_PASS / E2E_APP_PASSWORD
```

## Run

```bash
npm run test:e2e        # headless
npm run test:e2e:ui     # Playwright UI mode (watch + time-travel)
```

The `setup` project logs in once and saves the session to
`tests/e2e/.auth/state.json` (gitignored); every spec reuses it.
`E2E_LOGIN_URL` defaults to `/login`. Override it for instances with a
custom login front door, for example `/login?noredir=1#body-login`.

## Layout

```
tests/e2e/
  playwright.config.ts     baseURL from E2E_BASE_URL, serial, trace-on-failure
  auth.setup.ts            form login -> .auth/state.json
  fixtures/
    env.ts                 required-env reader
    dav.ts                 WebDAV setup/teardown via app password
    nextcloud.ts           Files-app browser helpers
  specs/
    pad-create-public.spec.ts   smoke #1: create public pad + viewer mounts
```

Selectors prefer stable hooks (NC `data-cy-*`, our own `data-testid`)
over localized text so specs survive UI-language changes.

## Cleanup

Specs name their files `e2e-<label>-<timestamp>.pad` and delete them in
`afterAll` via WebDAV. `E2E_APP_PASSWORD` is required for these
non-browser requests, matching the existing `NC_APP_PASSWORD` pattern in
`tests/integration/*.sh`.

Keep `E2E_PASS` and `E2E_APP_PASSWORD` separate:

- `E2E_PASS` logs into the interactive Nextcloud web UI once and stores
  Playwright's browser `storageState`.
- `E2E_APP_PASSWORD` is used only for BasicAuth requests outside the
  browser, such as WebDAV cleanup and future OCS/API setup.

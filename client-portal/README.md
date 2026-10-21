# Kreatiklan Client Portal

A dependency-free, responsive preview of a design collaboration portal. All application source lives in this folder in `kreatiklan-collab/KBS`.

## Run

From the repository root:

```sh
python3 -m http.server 8000 --directory client-portal
```

Open http://localhost:8000. Choose a sample workspace and enter as the client or Kreatiklan team. Use the header role switcher to try both sides. Staff can switch client workspaces. Leave the preview to select a different sample client.

## Included

- Two sample client workspaces, one shared account per side represented by demo roles.
- New requests with title, brief, type, deadline, optional dimensions/name, references, and attachments.
- Search, status filters, grid/list/board layouts, and activity history.
- Transparent per-request conversations; no private internal notes.
- Staff design submissions with uploaded files or HTTP(S) links; client revisions and approval.
- Approval timestamps and reopening that preserve the entire timeline.
- IndexedDB persistence for requests and file contents across reloads in the same browser.
- In-session notification inbox. Email delivery is explicitly not connected.

## Preview limits

This is a reviewable frontend prototype, not a production service. Demo role selection is not authentication. Workspace scoping is a UI behavior, not a security boundary. All sample records are browser-local and do not synchronize across users or devices. Attachments are limited to five files per update and 4 MB per file. All event times display in Asia/Jakarta (WIB).

Before real clients use it, implement server-side shared accounts, secure sessions, authorization on every workspace/request/file operation, durable shared database and object storage, and verified email delivery. Add custom-domain DNS after the preview is approved. Sender/recipient addresses and production passwords are not embedded in this source.

The UI follows the existing employee portal’s TailAdmin-style sidebar/dashboard structure, its red brand accent (`#ea1d25`), Outfit typography, and official Kreatiklan wordmark. The wordmark was copied from `src/workday/kreatiklan-logo.svg` in the existing Kreatiklan portal source.

## Checks

```sh
node --check client-portal/app.js
```

`tests/portal.spec.cjs` provides browser workflow checks with Playwright. Install Playwright separately in your test environment and set `PLAYWRIGHT_PATH` to its entry point if it is not available as `playwright`. Start the server above, then run:

```sh
PORTAL_BASE_URL=http://localhost:8000 node client-portal/tests/portal.spec.cjs
```

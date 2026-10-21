# SiteGround server application

The SiteGround build uses PHP accounts and shared server storage. The Sites build remains a separate browser-local demonstration. There is no automatic migration of demo records or browser-stored files.

## Build

Run `node scripts/build-siteground.mjs` from the KBS root. Upload the **contents** of ignored `siteground-dist/` into the dedicated `clientportal.kreatiklan.com` document root. This build includes a PHP API, authenticated client, upload limits, and a generated `server/config.php`.

The build keeps an existing config file, so rebuilding does not replace activation material or an installed configuration. It creates a deployment-only activation key separately under `/workspace/artifacts/portal-activation-key.txt` by default. The key is not part of the web archive or Git source. Keep it private; the deployment operator uses it only to create the initial Kreatiklan account. There are no default usernames or passwords.

Do not upload the source repository or the demo-only `dist/` output as the live application. Do not overwrite an existing production `server/config.php` when deploying updates.

## Requirements to verify in the actual SiteGround account

- A plan/site mapping that can serve the external hostname `clientportal.kreatiklan.com` from its own document root.
- HTTPS, PHP 8.1 or newer, PDO SQLite **or** PDO MySQL, fileinfo, sessions, and private writable storage outside the document root.
- Upload limits of at least 4 MB per file and 24 MB per request. The package supplies `.user.ini`; check the effective limits after upload.
- The actual private directory must be covered by hosting backups along with the application files. Its contents include the database, uploads, and session files.

The application defaults to SQLite in `../kreatiklan-portal-data` relative to the document root. It rejects storage inside the public website folder. If the hosting account restricts private paths, set an allowed private path in `server/config.php` before activation. For MySQL, set `dsn`, `db_user`, and `db_password` in that file; see `config.example.php`. Database initialization creates tables automatically. MySQL credentials stay out of Git. SQLite was verified in local PHP integration and browser tests; actual SiteGround driver and permissions still require account verification.

The production configuration requires HTTPS and uses Secure, HttpOnly, SameSite=Lax session cookies. Test mode is exclusively for an isolated local test server and must not be deployed.

## Initial activation and client accounts

Open the HTTPS portal and use the separate activation key to create the shared Kreatiklan staff account. Activation is disabled once the first account exists. Create each client workspace and its shared username/password through the interface. Each client can see only its assigned workspace. Staff can switch workspaces, reset a client's password, and change their own password. Resetting a password revokes existing sessions for that account.

## Shared workflow and access

The server validates workspace ownership, role permissions, state transitions, required reasons, file references, and the most recent event ID. IDs, timestamps, account identity, and approval-to-version links are server-issued. Every deletion preserves its brief, files, conversation, approval records, actor, reason, and time in a read-only archive.

Uploaded files are stored outside the web root and downloaded through the authenticated API. Only raster images use inline delivery; other files, including SVG/HTML, download as attachments with a sandbox policy. Uploaded filenames never become executable server paths. Unattached staged uploads remain private to their uploading account.

## Email notifications

The notification inbox is implemented. Email events are queued only when an installation sender and recipient addresses are configured. Set a verified domain sender in `config.php` as `mail_from` **before initial activation**, or update the existing `settings.mail_from` value during deployment. Owner email is collected during activation; client email is collected during workspace creation.

After the hosting/domain sender is verified, schedule `php /absolute/document-root/server/send-notifications.php` through SiteGround Cron Jobs. The worker is CLI-only and processes at most 20 queued messages per run, retrying failures up to five times. A successful PHP `mail()` result means the mail server accepted the message, not that the recipient received it. Hosting mail transport and domain authentication must be verified before enabling it. With no sender configured, the worker sends nothing.

## Verification

Verify the DNS target in SiteGround before creating a record. Point only `clientportal.kreatiklan.com` at that site, preserving existing root and mail DNS records. Issue HTTPS and test the actual hostname. Confirm sign-in, workspace isolation, both roles, upload/download, approval, reopening, deletion history, password resets, cross-device shared data, and backups. Preserve the existing Weebz website.

Automated tests live in `tests/server/api.spec.cjs` and `tests/server/browser.spec.cjs`. They cover owner activation, credentials, CSRF, role and client isolation, private files, shared workflow/history, stale-update conflicts, resets, and independent browser sessions. Tests run against an isolated PHP server with a disposable private database. They must never target a production account/database.

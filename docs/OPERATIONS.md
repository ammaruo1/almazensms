# ALMAZEN SMS Operations

## Manual test procedure

1. Use only an owner-controlled destination or one whose owner explicitly consented. Check carrier policy, plan, and balance before sending.
2. Open the app and inspect the Internet, permissions, SIM, and service indicators. These are independent signals; a SIM or Internet indicator does not prove SMS availability.
3. Open the manual send screen, enter the destination and text, and select the intended active SIM.
4. Review the exact number, message, SIM, and segment count. If `SEND_SMS` is missing, read the in-app explanation and grant it through Android. Then return to review; no message is sent automatically after granting.
5. Tap the final confirmation only when ready. The app stores a masked-recipient attempt before asking Android to send.
6. Check local history for the result. `SENT` means Android reported successful handoff to the SMS stack/network, not recipient receipt. `DELIVERED` is shown only when Android provides a positive parseable carrier report. Reports may be unavailable or delayed.

Do not automatically retry `UNKNOWN`: the first message might have been transmitted even when the app missed its callback. A manual retry can create a duplicate and must be a new consciously reviewed attempt.

## Status meanings

| Status | Meaning |
| --- | --- |
| `SENDING` | Android send was invoked; callbacks are pending. |
| `SENT` | Sent callback indicates successful handoff. Not proof of delivery. |
| `DELIVERED` | A positive delivery status report was parsed. Carrier support is not guaranteed. |
| `SEND_FAILED` | Android reported a send failure. |
| `DELIVERY_FAILED` | A parseable explicit negative delivery report was received. |
| `UNKNOWN` | The callback/status is missing, delayed, or ambiguous. Do not automatically retry. |

## Permission and device troubleshooting

- `SEND_SMS` is only requested when the user begins the manual-send flow. If Android has permanently denied it, enable it in the app's system permission settings, then reopen the app. The app cannot bypass Android controls.
- `READ_PHONE_STATE` is used to inspect active subscriptions and SIM slots. Without it, SIM details/selection may be unavailable. This permission does not expose SMS inbox contents.
- No SIM, `NOT_READY`, or unavailable service: verify SIM seating/activation and device radio using Android settings. A carrier label alone does not mean SMS service is ready.
- Internet unavailable does not itself prevent SIM SMS, and Internet connectivity does not prove SMS radio service.
- If there is no delivery report, leave the result as sent/unresolved; the carrier may not support or return reports.

## Privacy and scope

The attempt database stores a masked destination, carrier/SIM metadata, segment count, status, timestamp, and redacted error description. It does not store message text or the full destination. Do not add message text, complete phone numbers, or sensitive content to logs/screenshots when collecting support evidence.

The app monitors default-network changes while its Activity is started, and periodically refreshes SIM, battery, and attempt diagnostics while foregrounded. It stops monitoring when the Activity stops to conserve resources.

At boot after unlock, interrupted `SENDING` attempts are marked `UNKNOWN`; when the app resumes after a process stop, attempts older than the callback timeout are likewise marked `UNKNOWN`. No message is resent. The boot receiver does not start a service or launch the UI. If Android defers boot work for a force-stopped app, launching the app performs stale recovery.

The local/manual workflow remains available. A 72-hour observation is pending in `docs/PHASE2_72H_LOG.md`.

## Android gateway setup (Phase 4)

1. Provision a project and device with the backend API. Keep the one-time device token private; the app encrypts it with Android Keystore and excludes the credential preferences and message database from Android backup/transfer.
2. Open **الخادم**, choose the intended SIM (grant `READ_PHONE_STATE` when requested), enter the HTTPS API origin and one-time device token, then test/save. The connection test sends only a heartbeat and does not send SMS.
3. Select **تفعيل استقبال طلبات API** only after confirming expected costs and quotas. The app requests `SEND_SMS` through Android and requires a separate user confirmation. If denied or revoked, new remote sends stop; results already recorded locally remain available for reporting.
4. Pause to stop new claims while allowing pending result reporting, or disconnect to cancel work and remove the device token from this phone. Replacing the token requires the backend's current device credential.

The project limit defaults to 20 messages per UTC day; the backend accepts at most 10 requests/minute per project key, and the app enforces a minimum one-minute gap between remote sends. The server quota can be set as high as 1000/day, so keep it low until carrier terms, costs, and measured capacity are known. WorkManager uses Android's minimum 15-minute periodic interval and may run later because of Doze, battery policy, OEM management, force-stop, no network, or system scheduling. `SENT` is only a device/network handoff report. `UNKNOWN` is not retried automatically.

The app does not run an always-on foreground service. This avoids unsupported 24/7 claims and time-limited foreground-service behavior on Android 15+; WorkManager is the deferred-work path. This remains a best-effort gateway, not an uptime/SLA commitment.

## Backend local setup (Phase 3)

This starts development services only. It is not a production deployment: the API binds to `127.0.0.1`, database and Redis ports are private to the Compose network, no public TLS proxy is included, and real MySQL/Redis behavior must be verified before deployment.

1. Install PHP 8.3 and Composer 2. Copy `backend/.env.example` to `backend/.env`. For loopback-only local development set `APP_ENV=local` (keep `APP_DEBUG=false`); for deployment set `APP_ENV=production`. Generate a unique `APP_KEY` with `php artisan key:generate`; set long, unrelated `DB_PASSWORD` and `DB_ROOT_PASSWORD` values. Keep `.env` private and excluded from source control. Do not use the example placeholder credentials.
2. From `backend/`, start services with `docker compose up --build -d`. Wait until MySQL and Redis health checks pass. The API container applies migrations; queue and scheduler containers run Laravel workers, the scheduled outbox scan, and daily pruning of expired Sanctum tokens.
3. Create the first dashboard administrator without putting the password on the shell command line: `docker compose exec api php artisan dashboard:admin owner@example.com "ALMAZEN Owner"`. Enter a unique password of at least 14 characters with upper/lowercase letters, a number, and a symbol at the hidden prompts. If running Artisan directly on the host, use the same command without the `docker compose exec api` prefix. There is no public registration or default production admin.
4. For local API calls, use the local API host and operator login route. In production, provision an HTTPS domain and TLS reverse proxy, configure trusted proxy addresses, set production environment secrets through a secret manager, and verify TLS before creating keys/devices.
5. Create a project, then create a project API key and device from authenticated project routes. Save each one-time secret in an approved secret store; the API does not display it again. Revoke and recreate a lost API key/device credential.
6. Observe queue health using `php artisan queue:failed`, `php artisan queue:retry <id>`, and the `outbox_events` rows. Queue retries do not imply SMS retries. Do not retry any device report with `unknown` automatically.

Backend automated tests use SQLite `:memory:` and queue fakes. Use `php artisan test` and `vendor/bin/pint --test` for local checks. Before production, test migrations and concurrent claims on MySQL 8 and confirm Redis worker recovery on Redis 7; Docker daemon availability was missing during Phase 3 implementation. No Android/backend live connection is configured in this phase.

## Backend request lifecycle and safety

- API keys authenticate a project, not an individual end recipient. Apply customer authorization, carrier policy, quotas and abuse controls before exposing keys to external clients.
- A message request persists encrypted recipient/body content. Define access controls, key rotation and retention/deletion policy for `APP_KEY` and personal data before production. Loss of `APP_KEY` makes encrypted message payloads unreadable.
- The transactional outbox stores an event in the same SQL commit as the message. The scheduler publishes due events to Redis with a lease; expired leases are recoverable. The Android claim API reads committed SQL directly. Queue publication/processing does not mean an SMS was transmitted.
- `sent_to_network` means only device-reported handoff. `delivery_reported` requires separate evidence. `unknown` is unresolved and never automatically resent.
- In production, all `/api/v1` routes require HTTPS. Correct recognition of HTTPS forwarded by a proxy depends on narrowly configuring Laravel's trusted proxies. Do not trust arbitrary forwarded headers.

The Hostinger account inspected for the owner is a shared website environment, not a verified runtime for the current API topology. It reports a PHP 8.3 CLI and required extensions but no Docker or local Redis service; its existing web root belongs to the shop. Do not deploy the Laravel source into that public root or run the shop's deployment BAT for this app. Use a separate VPS/API subdomain for the chosen MySQL 8 + Redis topology, or obtain explicit approval before redesigning to shared-host services.

There is no automated restore procedure, API host/domain/TLS setup, monitoring/alerting, retention enforcement, or live MySQL/Redis verification for the API. These remain deployment-readiness items, not verified features.

## Dashboard administration (Phase 5)

After applying database migrations and building frontend assets, visit `/login` on the HTTPS dashboard origin and sign in with an active administrator provisioned using `php artisan dashboard:admin`. The route `/` redirects to `/admin`. The dashboard is admin-only; operator users are denied web-dashboard access. Use Projects and quotas to create/disable projects, Devices to enroll/revoke a gateway, and API Keys to issue/revoke a project credential. Credentials appear only in the immediate creation response; copy them to an approved password manager/secret store before navigating away. A lost key/device token must be revoked and replaced.

Review Messages for state and UUID only, Usage for the daily project quota, Audit for administrative actions, and Diagnostics for current service/device snapshot. `unknown` is intentionally not actionable for automatic resend. A diagnostic page is not monitoring or an alert. No admin send-SMS action, OTP workflow, or customer self-service is included.

## Hostinger shared-plan deployment (selected, not yet deployed)

The hPanel lists `api.almazen.online` at `/home/u893896051/domains/almazen.online/public_html/api`; the existing shop root is a separate directory and must not be replaced. Keep the Laravel application at `/home/u893896051/apps/almazen-sms-api`, outside `public_html`; copy only `backend/public/` into the API subdomain document root. The deployed `index.php` must load `vendor/autoload.php` and `bootstrap/app.php` from the private app root. Never upload `.env`, the full source tree, `vendor`, or `storage` into a public directory. A deployment bundle must exclude `.env`, test/dev files, and local secrets.

Create a dedicated MySQL database and database user in hPanel for ALMAZEN SMS (do not reuse the shop database). Use PHP 8.3 and the host values shown by the database panel. Start from `backend/hosting.env.example`; set `APP_KEY` privately with `php artisan key:generate`, `APP_URL=https://api.almazen.online`, production/debug/cookie settings, MySQL credentials, and `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`. Keep this live file outside the website and never paste its secrets into chat or source control. The MySQL server version is not yet known; verify it from PHP/PDO after setting credentials.

Run `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader`, `php artisan migrate --force`, and `php artisan dashboard:admin <email> <name>` from the private application root. Generate the APP_KEY before migrations/admin bootstrap. The public CSS/JS bundle is already built locally at `backend/public/build`.

In Hostinger hPanel → Cron Jobs, schedule both entries every minute (Hostinger cron time is UTC). Replace the account path if hPanel displays a different home directory:

```sh
/opt/alt/php83/usr/bin/php /home/u893896051/apps/almazen-sms-api/artisan schedule:run
/opt/alt/php83/usr/bin/php /home/u893896051/apps/almazen-sms-api/artisan queue:work database --queue=outbox --stop-when-empty --max-time=45 --timeout=30 --tries=3 --no-interaction
```

The shared plan does not provide a confirmed always-running worker. Verify Cron task limits, PHP-FPM version, database version, TLS certificate, `/up`, dashboard login, database queue processing, and backup/recovery before enabling any Android gateway. Do not describe cron-driven polling as a continuous worker or a 24/7 guarantee.

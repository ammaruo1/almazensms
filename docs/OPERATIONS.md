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
- No SIM, `NOT_READY`, or unavailable service: verify SIM seating/activation and device radio using Android settings. A carrier label alone does not mean SMS service is ready. A failed or null SIM-list read is not the same as no SIM. On Android 10 through API 32 the app does not treat a service-state value of 0 as confirmed `IN_SERVICE`; that reading was not verified on an Android 10 device.
- Internet unavailable does not itself prevent SIM SMS, and Internet connectivity does not prove SMS radio service.
- If there is no delivery report, leave the result as sent/unresolved; the carrier may not support or return reports.

## Privacy and scope

The attempt database stores a masked destination, carrier/SIM metadata, segment count, status, timestamp, and redacted error description. It does not store message text or the full destination. Do not add message text, complete phone numbers, or sensitive content to logs/screenshots when collecting support evidence.

The Activity's `ConnectivityManager` callback refreshes the on-screen indicator only while the Activity is started and is unregistered when it stops. While gateway automation is enabled, `platform/GatewaySyncMonitor.kt` keeps a separate default-network callback for the life of this process and is gone when the process dies. It nudges WorkManager at most once a minute when validated Internet is present. Pause does not cancel that registration; a nudge returns immediately while automation is disabled. SIM, battery, and attempt diagnostics still refresh on the foreground 15-second interval.

At boot after unlock, and immediately on `MY_PACKAGE_REPLACED`, interrupted `SENDING` attempts are marked `UNKNOWN`. If that mark throws, recovery still reschedules WorkManager when the gateway opt-in is enabled. No message is resent. Neither receiver starts a foreground service or launches the UI. Force-stop blocks both broadcasts until the user opens the app. Opening the app performs stale recovery and restores periodic work. When the app resumes after a process stop, attempts older than the two-minute callback timeout are likewise marked `UNKNOWN`.

The local/manual workflow remains available. A 72-hour observation is pending in `docs/PHASE2_72H_LOG.md`.

## Android gateway setup (Phase 4)

1. Provision a project and device with the backend API. Keep the one-time device token private; the app encrypts it with Android Keystore and excludes the credential preferences and message database from Android backup/transfer.
2. Open **الخادم**, choose the intended SIM (grant `READ_PHONE_STATE` when requested), enter the HTTPS API origin and one-time device token, then test/save. The connection test sends only a heartbeat and does not send SMS.
3. Select **تفعيل استقبال طلبات API** only after confirming expected costs and quotas. The app requests `SEND_SMS` through Android and requires a separate user confirmation. Enable is refused when the device does not report the SMS feature, when the SIM list could not be read, when the selected SIM is missing from a readable list or its card state is not `READY`, or when cellular service is not a confirmed `IN_SERVICE`. Confirmed `IN_SERVICE` requires the API 33+ location-free read. Android 10 through API 32 do not pass that check from a reported state of 0; this was not verified by running the app on an Android 10 device. Missing `SEND_SMS` opens the permission request and does not enable until it is granted. Offline or unvalidated Internet does not block enable. If denied or revoked, new remote sends stop; results already recorded locally remain available for reporting.
4. Pause (**إيقاف استقبال الرسائل الجديدة**) sets local `enabled` to false. It does not cancel WorkManager. The next run still heartbeats and uploads results already stored locally, then stops before a new claim. `claim` and `acknowledge` re-check `enabled`. Disconnect cancels the unique work and removes the device token from this phone. Replacing the token requires the backend's current device credential.

The server enforces each API key's daily limit and per-minute limit. The phone does not display a fixed quota. It still waits at least one minute between remote sends; that wait is local pacing. The project daily ceiling is an extra shared guard, and the effective remaining amount is the smaller of the key remainder and the ceiling remainder. New keys start at 20 per UTC day and 10 new accepts per minute until an administrator changes them. Carrier-terms review is out of the current scope. WorkManager uses Android's minimum 15-minute periodic interval. That interval is inexact and may slip under Doze, OEM policy, or force-stop, and it also waits when there is no network. Enabling the gateway, opening the app, reboot, and app update also enqueue one immediate sync when Android accepts it. On API 31+ that immediate request asks for expedited execution and falls back to ordinary work when the expedited quota is exhausted. `SENT` is only a device/network handoff report. `UNKNOWN` is not retried automatically.

The app does not run an always-on foreground service, keep its screen on, or poll every five seconds. FCM is the fast wake path when both Firebase client configuration and server service credentials are installed. Each push contains a message UUID only; Android shows one privacy-safe notification and queues a WorkManager sync. High-priority FCM requests expedited work; a normal-priority push queues ordinary work. Firebase may delay, drop, or downgrade pushes, so WorkManager remains a slower fallback with a 15-minute minimum periodic interval. If OTP expires before the phone claims and receives server send authorization, the API suppresses it instead of sending a stale code. The app shows whether Firebase is configured, but that status is not proof the FCM transport is currently delivering.

Force-stop blocks background recovery until the user opens the app. Swiping the app away is not the same guarantee: stock Android can keep scheduled work, while Samsung may still stop it. Device power-off resumes work after boot only if the app was not force-stopped and the boot broadcast is delivered. A dedicated phone on charger and Internet reduces some operational risks, but does not provide uninterrupted service or an uptime/SLA guarantee. Do not request a battery exemption automatically. This build does not use a mismatched foreground-service type, keep-screen-on flag, wake lock, or hidden API.

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
- The transactional outbox stores an event in the same SQL commit as the message. The scheduler publishes due events to Laravel Database Queue with a lease; expired leases are recoverable. Redis is not required for the selected Hostinger deployment. The Android claim API reads committed SQL directly. Queue publication/processing does not mean an SMS was transmitted.
- `sent_to_network` means only device-reported handoff. `delivery_reported` requires separate evidence. `unknown` is unresolved and never automatically resent.
- In production, all `/api/v1` routes require HTTPS. Correct recognition of HTTPS forwarded by a proxy depends on narrowly configuring Laravel's trusted proxies. Do not trust arbitrary forwarded headers.

On 2026-11-12 the owner accepted the current Hostinger plan. Production now serves Laravel from `/home/u893896051/apps/almazen-sms-api`, outside the shop's public root, with generated frontend assets under `public_html/api/build`. MariaDB migrations are applied. Hostinger has two verified every-minute cron entries for `schedule:run` and bounded `queue:work --queue=outbox --stop-when-empty --max-time=45`. Live `/up` and `/login` returned HTTP 200 after deployment. The shop root was not replaced. The new FCM token migration/code is not deployed yet; Firebase secrets and `app/google-services.json` are not present. The status must not be treated as a verified phone-to-server FCM or SMS path.

Backups, alerts, and carrier-terms review remain outside the approved scope. No automated restore procedure exists. The public health endpoint and Laravel migrations have been checked, but an end-to-end heartbeat/claim/report through the Android handset and FCM wake have not been verified. Scheduler execution is confirmed by the Hostinger cron entries; application heartbeat freshness should still be checked in Dashboard → Diagnostics.

## Dashboard administration (Phase 5)

After applying database migrations and building frontend assets, visit `/login` on the HTTPS dashboard origin and sign in with an active administrator provisioned using `php artisan dashboard:admin`. The route `/` redirects to `/admin`. The dashboard is admin-only; operator users are denied web-dashboard access. Use Projects and quotas to create/disable projects, Devices to enroll/revoke a gateway, and API Keys to issue/revoke a project credential. Credentials appear only in the immediate creation response; copy them to an approved password manager/secret store before navigating away. A lost key/device token must be revoked and replaced.

Review Messages for state, recipient, body, and UUID, Usage for the project ceiling and each key's effective remaining quota, API Keys to create and edit per-key limits, OTP to enable the feature and inspect masked challenges, Audit for administrative actions, and Diagnostics for the queue connection, pending jobs, pending outbox rows, failed jobs, and scheduler heartbeat. OTP cancel or expiry marks affected outbox rows processed with `suppressed:otp_not_sendable`; that is not an SMS send. After `sent_to_network`, neither the API nor the gateway can recall the handset transmission. `unknown` is intentionally not actionable for automatic resend. A diagnostic page is not an alert. Backups and alerts are out of the current scope. The local Messages source now has an admin test-SMS form: it accepts a manually entered E.164 number, requires recipient-ownership/consent confirmation, queues one fixed test message through the normal quota/outbox path, and limits each admin to two submissions per ten minutes. This form is not deployed until the desktop deployment helper has actually been run. Test recipient/body are visible in admin Messages and Audit; audit copies are encrypted at rest. OTP stays off until an administrator enables it, and the OTP page does not show the code, the full phone number, or the message text. SaaS and customer self-service billing are out of the current scope.

## Hostinger shared-plan deployment (deployed 2026-11-12)

The hPanel maps `api.almazen.online` to `/home/u893896051/domains/almazen.online/public_html/api`; the existing shop root was preserved. The Laravel application is at `/home/u893896051/apps/almazen-sms-api`, outside `public_html`; only the front controller and compiled public assets are in the API document root. Never upload `.env`, the full source tree, `vendor`, or `storage` into a public directory. The deployment used a private release bundle excluding `.env`, test/dev files, and local secrets. The prior app snapshot is retained under `/home/u893896051/apps/almazen-sms-api-previous-20261112-041644`.

A local desktop helper mirrors the store launcher: `D:\Desktop\تحديث المازن SMS API.bat` runs `scripts/deploy-desktop.ps1`. It prompts once for the Hostinger SSH password (not stored), lists changed `backend/` API paths from Git, commits locally, backs up remote code folders, uploads `app`, `config`, `database`, `resources`, `routes`, and `openapi.yaml` over SSH, then runs `/opt/alt/php83/usr/bin/php artisan optimize:clear` and `migrate --force`. It never uploads `.env`, `storage`, or `vendor`, and it does not send SMS.

Create a dedicated MySQL database and database user in hPanel for ALMAZEN SMS (do not reuse the shop database). Use PHP 8.3 and the host values shown by the database panel. Start from `backend/hosting.env.example`; set `APP_KEY` privately with `php artisan key:generate`, `APP_URL=https://api.almazen.online`, production/debug/cookie settings, MySQL credentials, and `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`. Keep this live file outside the website and never paste its secrets into chat or source control. The MySQL server version is not yet known; verify it from PHP/PDO after setting credentials.

Run `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader`, `php artisan migrate --force`, and `php artisan dashboard:admin <email> <name>` from the private application root. Generate the APP_KEY before migrations/admin bootstrap. The public CSS/JS bundle is already built locally at `backend/public/build`.

In Hostinger hPanel → Cron Jobs, both entries are configured every minute (Hostinger cron time is UTC):

```sh
/opt/alt/php83/usr/bin/php /home/u893896051/apps/almazen-sms-api/artisan schedule:run
/opt/alt/php83/usr/bin/php /home/u893896051/apps/almazen-sms-api/artisan queue:work database --queue=outbox --stop-when-empty --max-time=45 --timeout=30 --tries=3 --no-interaction
```

The shared plan does not provide an always-running worker. Cron jobs are configured, but their ongoing punctuality remains subject to Hostinger limits. Do not describe cron-driven processing as a continuous daemon or a 24/7 guarantee. The two current code changes still pending deployment are the FCM-token migration and notifier updates. Firebase needs `FIREBASE_CREDENTIALS` to point to a service-account JSON kept outside the document root, and Android needs the matching `app/google-services.json`; do not paste either secret into chat or commit them.

### Enable the Firebase wake path

1. In Firebase Console, create/select the project and register an Android app with package ID `com.almazen.sms`; download its `google-services.json` into the local Android project at `app/google-services.json`. Keep this file out of Git.
2. In Google Cloud/Firebase, enable Firebase Cloud Messaging API for the same project and create a service account with permission to send FCM messages. Download its JSON key to a private directory outside `public_html` and outside the Laravel source bundle.
3. Set `FIREBASE_CREDENTIALS=/absolute/private/path/firebase-service-account.json` in the Laravel `.env` on Hostinger. The notifier reads `project_id`, `client_email`, and `private_key` from that file; do not put key contents directly into `.env` or logs.
4. Deploy the Laravel FCM-token migration and notifier code, then run `php artisan migrate --force` from the private app root and clear cached config if the host uses cached Laravel configuration.
5. Build/install the Android app with the matching `google-services.json`. Open the app, grant notification permission, enable the gateway, and confirm the device heartbeat registered an FCM token in local/server diagnostics.
6. Test a push with SMS disabled or the gateway paused first. Confirm only a UUID is in the FCM data payload, the handset receives a privacy-safe notification, and WorkManager starts a claim. Only separately test actual SMS after an owner-approved test number and explicit send confirmation.

FCM is an acceleration path, not an uptime guarantee. If the token is invalid, the service account is misconfigured, Google Play services delays delivery, or the device is force-stopped/offline, the API remains queued and WorkManager may run later. OTP expiration/send authorization must continue suppressing expired challenges.

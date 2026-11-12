# Engineering Decisions

## D-001: Preserve the existing Android project

The existing project structure, package/application ID `com.almazen.sms`, SDK levels, and Kotlin/Compose stack are retained. Phase 0 does not recreate the project or add application dependencies.

## D-002: Align the build tools with the installed Android Studio and API 37

The project now uses Android Gradle Plugin `9.1.1` and Gradle Wrapper `9.3.1`. The installed Panda 3 Android Studio supports AGP through 9.1, API 37 requires AGP 9.1.1, and AGP 9.1.1 requires Gradle 9.3.1. The wrapper distribution is pinned with the official SHA-256 checksum. Kotlin remains `2.2.10`; compile/target/min SDK values remain 36.1/36/26.

## D-003: Do not initialize Git in Phase 0

The owner chose to leave this directory as-is. Since no `.git` repository exists at this path, status/history cannot be reported. This limitation is documented rather than creating repository metadata.

## D-004: Ignore local configuration, generated output, and signing secrets

The root `.gitignore` covers Gradle/Kotlin caches, build outputs, `local.properties`, `.env` variants (while allowing `.env.example`), common Android signing-key files, and logs. No `.env` file or credential-like assignment was found in the scanned text sources. Because Git is absent, ignored/tracked state cannot be confirmed.

## D-005: Keep later-phase work behind the Phase 0 gate

Phase 0 made no SMS sending or backend changes. Phase 1 has since been explicitly approved by the owner and is implemented in the existing app.

## D-006: Manual, explicit test sending only

Phase 1 sends only after the user enters a destination and message, reviews the complete values and selected SIM, then taps the final send confirmation. Permission grant never triggers sending by itself. No automated/bulk/background send path is present. The owner later confirmed successful receipt of the Phase 1 controlled SMS; this is recorded without recipient details.

## D-007: Use Android's supported per-subscription SMS API

The engine uses `SmsManager` bound to the selected active subscription. `READ_PHONE_STATE` supports enumerating active subscriptions and SIM slots; `ACCESS_NETWORK_STATE` powers the Internet indicator. SMS hardware is an optional manifest feature. No hidden API, root, inbox, contact, location, or receive-SMS access is used.

## D-008: Minimize locally retained message data

Persist attempt metadata and a masked recipient only. Do not persist message content or the complete destination. The Phase 1 history is SQLite-backed, with segment-level callback aggregation.

## D-009: Keep delivery evidence and ambiguity distinct

`SENT` reflects a successful Android sent callback only. `DELIVERED` requires a positive parsed carrier status report; explicit negative reports map to `DELIVERY_FAILED`, while temporary or unrecognized reports remain unresolved. Missing callbacks after timeout map to `UNKNOWN`. There is no automatic retry for `UNKNOWN`.

## D-010: Explicit callback intents with limited mutability

Sent and delivery PendingIntents target the app's non-exported receiver and use unique attempt/part/action data. They are mutable because Android's telephony framework fills delivery report PDU/format extras into the callback. Mutability is constrained by the explicit component and unique identity.

## D-011: Keep Phase 1 local and synchronous

No backend, queue worker, dashboard, OTP, foreground service, external-provider fallback, or new runtime library was introduced. The Phase 1 sender remains foreground/manual; Phase 2 adds local recovery and diagnostics only.

## D-012: Preserve SQLite and migrate it transactionally

Phase 1 already persists each attempt before calling `SmsManager`. The owner approved retaining `SQLiteOpenHelper` instead of introducing Room. Phase 2 advanced schema v1 to v2 with an append-only event table and foreign-key enforcement; migration keeps all existing attempts. Reasons: it is already durable, has few local entities and no Android-side queue yet, preserves the tested sending path, and avoids dependency/schema migration risk before there is a concrete Room need. Revisit only if Android/backend integration requires richer transactional queries or flows.

## D-013: Recover interrupted sends as UNKNOWN, never queued for replay

After boot, outstanding `SENDING` attempts become `UNKNOWN`. On process/activity recovery, attempts that exceed the two-minute callback window become `UNKNOWN`. This records uncertainty and prevents automatic duplicate sends. Late platform callbacks may still resolve an attempt.

## D-014: Use a short boot receiver only for local reconciliation

The app declares `RECEIVE_BOOT_COMPLETED` and a non-exported receiver. After Android completes boot/unlock, it updates local database state and exits; it does not start a background/foreground service or launch the UI. A force-stopped package may not receive boot work until the user launches it, so UI startup is also a recovery point.

## D-015: Monitor connectivity only while the UI is foregrounded

The app registers Android's default-network callback while its Activity is started, unregisters it on stop, and refreshes other diagnostics every 15 seconds while foregrounded. Battery percent/charging is sampled from the system sticky battery intent. No wake lock, polling service, or periodic background worker is justified while there is no backend queue.

## D-016: Long-run stability requires a measured 72-hour observation

Automated tests and a device reboot smoke test cannot substitute for 72 hours of continuous operation. The run log must record start/end, phone/app conditions, connectivity interruptions, reboot observations, crashes, attempt counts, and privacy-redacted evidence. Phase 2 should not claim 72-hour stability until the run is completed.

## D-017: Add a separate Laravel 13 backend on PHP 8.3

Phase 3 uses Laravel 13 (`^13.17`, resolved 13.42.0) and PHP `^8.3`. This satisfies the owner's explicit PHP 8.3 requirement while using Laravel's supported authentication, validation, queue, migration, scheduler, and testing facilities. Android app files and its build configuration remain unchanged.

## D-018: Use MySQL 8.4 and Redis 7 for backend deployment topology

The Compose foundation defines MySQL 8.4 LTS-compatible storage and Redis 7 with append-only persistence for Laravel Queue. Predis is used so the Laravel application does not require the optional native `phpredis` extension. SQLite `:memory:` is used only by portable automated tests; it is not the target backend database.

## D-019: Persist requests and outbox events atomically

Message row, initial lifecycle event, and outbox event are committed in one SQL transaction. A scheduled dispatcher leases due rows before publishing Laravel jobs to Redis; failed dispatch releases the lease, and expired leases are eligible for recovery. Queue jobs are idempotent. Device claim reads the committed SQL message row, so accepted work does not depend on Redis being available at claim time. This is at-least-once event publication with idempotent processing, not exactly-once SMS delivery.

## D-020: Use one-time hashed API/device credentials and encrypted message payloads

Sanctum protects operator endpoints. Project API keys and device bearer secrets are random opaque values returned only at creation, stored as SHA-256 hashes, and checked with constant-time digest comparison. Message destination and body use Laravel encrypted casts with `APP_KEY`; the idempotency digest is an HMAC. Secrets and raw message content are not included in audit metadata. Production API routes require HTTPS.

Operator bearer tokens expire after 60 minutes. Admin-only routes list users and change role/access state; user creation is CLI-only and administrators cannot disable or demote their own account in the API.

## D-021: Keep Phase 3 separate from Android SMS execution

The API accepts and persists messages, provides status queries, and exposes device heartbeat/claim/ack/report endpoints. Phase 3 does not call SmsManager, send SMS, contain a Dashboard, or implement OTP. `sent_to_network` is not recipient delivery; `unknown` is never automatically retried. Android/backend integration and actual device command execution remain Phase 4.

## D-022: Keep Phase 3 testable without claiming unverified production services

Feature tests run against SQLite `:memory:` and queue fakes. Compose files define MySQL and Redis, but Docker daemon unavailability prevented service startup and live MySQL/Redis testing. Before deployment, run the same migration/API/queue tests against the selected MySQL 8 and Redis 7 services and configure trusted TLS proxy, domain, secrets, retention, and backups.

## D-023: Apply conservative message intake quotas before automatic gateway sending

Project API keys may submit up to 10 requests/minute and projects default to 20 accepted messages per UTC day, configurable up to 1000. The quota increment and message/outbox insert share the same SQL transaction and a project-row lock; idempotent replays do not consume quota. This is a starting cost-control guardrail, not a billing system or carrier throughput guarantee. Revisit the default with measured Yemen Mobile capacity before production traffic.

## D-024: Keep Android remote commands in the existing SQLite store

Phase 4 adds a `remote_commands` table to the approved SQLite database, using a transactional migration from v2 to v3. The server command ID is unique, encrypted destination/body are persisted before the device acknowledges the claim, and transition to local `SENDING` is committed in the same transaction that creates its attempt. Once SMS invocation may have occurred, recovery resolves the command as `UNKNOWN` and reports it; it is never eligible for automatic resend. This preserves the already accepted SMS engine and avoids a parallel persistence framework.

## D-025: Use encrypted device credentials and payloads with opt-in WorkManager polling

The one-time device token and claimed message payload are encrypted with AES-GCM using an Android Keystore key; gateway preferences and the local attempt database are excluded from Android backup/transfer because the key is not transferable. An operator must configure HTTPS, select a SIM, grant Android SMS permission, and explicitly opt in before remote sending. The app uses constrained WorkManager scans (15-minute minimum periodic interval), one-minute send pacing, and backend quotas rather than an always-on service. Android may defer work; no prompt-delivery or 24/7 guarantee is made. A rejected device credential disables remote sending.

## D-026: Treat Phase 4 as best-effort integration, not production deployment

Phase 4 wires the Android device to the Phase 3 API, but deployment credentials, public HTTPS host, live MySQL/Redis verification, signed Android release, and end-to-end SMS lifecycle are absent. The owner authorized automatic API-originated sends subject to limits; tests never send SMS. Google Play's current restricted SMS permissions require default SMS/Assistant handler status, so the current non-default gateway app must not be represented as Play-upload-ready. Distribution mode is an owner decision.

## D-027: Preserve the current Hostinger store while deciding API hosting architecture

The owner designated `almazen.online` and provided a BAT launcher. Inspection showed that the BAT invokes the existing store deployment script, which stages/commits/pushes the separate `C:\laragon\www\shop` Git repository to the Hostinger `main` deploy hook; that script was not executed. The store's deployment target is `/home/u893896051/domains/almazen.online/public_html`. A different Hostinger helper also targets that same public root. Read-only SSH preflight succeeded only through the configured `almazen-host` alias; the helper's separate Ed25519 key was rejected. Host runtime reports PHP 7.4 by default and PHP 8.3.36 CLI with required extensions, but no Docker or local Redis service, and no `api.almazen.online` DNS/docroot was found. The project requires MySQL 8 and Redis queue/outbox workers. Therefore no project files were uploaded and no existing store paths were changed. Production deployment is held until the owner chooses a separate VPS/API subdomain that preserves the required stack, or explicitly approves redesign for shared hosting (including queue/Redis architecture and database compatibility).

## D-028: Build the Phase 5 dashboard as an admin-only Laravel web application

The dashboard uses Laravel's existing session authentication and the existing `role.admin` middleware. Login is rate-limited, admits only active admin accounts, regenerates the session ID, and has no public registration. State-changing web forms use the standard `web` middleware and CSRF tokens. Project changes, user role/status changes, credential creation/revocation, and device enrollment/revocation append audit records. API keys and device credentials remain SHA-256 hashes and are returned only in a `Cache-Control: no-store` response at creation; the dashboard never renders message bodies or full recipient numbers. Message lookup is limited to the message UUID, project, and status. The HTML dashboard does not implement OTP, message sending, or automatic retries. CSS/JS use the existing Tailwind 4/Vite dependencies. An interactive `dashboard:admin` command is provided to provision the first administrator with a hidden, confirmed, strong-password prompt. This remains a development implementation until deployed and tested against the production services.

## D-029: Use MySQL-backed Laravel services on the approved Hostinger shared plan

The owner selected the current Hostinger shared hosting plan, which provides PHP 8.3 CLI and `pdo_mysql` but has no local Redis daemon or Docker. The `api.almazen.online` subdomain is present in hPanel and maps to `/home/u893896051/domains/almazen.online/public_html/api`; that directory currently contains the Hostinger placeholder page. Production shared-host configuration will use Laravel's database queue, database sessions, and database cache. A short `queue:work --stop-when-empty --max-time=45` task and `schedule:run` will be configured in hPanel Cron Jobs, subject to the plan's task quota/runtime limits. The Laravel application root, `.env`, `vendor`, and `storage` must live outside `public_html`; only the contents of Laravel's `public/` may be deployed to the subdomain document root, with its front controller loading the private app root. This replaces Redis only for this deployment; local Docker Compose remains the MySQL 8/Redis reference. The production MySQL/MariaDB server version, database credentials/limits, cron limits, DNS resolution, and actual queue throughput are not yet verified. This shared-host adaptation is best-effort and does not provide an always-running worker or 24/7 uptime guarantee.

## D-030: Use MariaDB and Laravel Database Queue on the accepted Hostinger plan (2026-11-12)

The owner confirmed that the current Hostinger hosting is acceptable and that MySQL 8 and Redis must not be requirements. The application keeps Laravel's `mysql` driver, which speaks to MariaDB. Queue, cache, and session tables already exist. Laravel's database queue uses `FOR UPDATE SKIP LOCKED` on MariaDB 10.6 or newer and a plain row lock on older MariaDB, so the chosen host does not need MySQL 8. The outbox still commits with the message; `outbox:dispatch` publishes to the `database` connection. Defining `schedule:run` in code does not mean Hostinger cron is configured. `scheduler:heartbeat` records a last-run row only when the scheduler actually executes it. Until that row exists, diagnostics show the scheduler as unconfirmed. No Hostinger setting was changed and no production command was run.

## D-031: Enforce per-API-key quotas, with the project ceiling as an extra guard (2026-11-12)

Each API key has its own UTC daily limit and rolling per-minute limit, edited from the admin website. The existing project `daily_message_limit` stays because several keys can share one gateway and one cost budget. Effective daily remaining is `min(key limit - key used today, project ceiling - project used today)`, floored at zero. The per-minute limit counts newly accepted messages for that key only. Reservation, the message, its event, and the outbox row commit under a project-row lock and a key-row lock. A repeated `Idempotency-Key` returns the original row before either counter moves. Quota edits are audited with the actor, key id, old limits, and new limits. The full key and message bodies are not logged. Android no longer displays fixed quota numbers that can drift from the server.

## D-032: Include OTP, disabled until an administrator enables it (2026-11-12)

OTP settings live on the project and default to disabled. Only the admin panel can enable them. `POST /api/v1/otp/request` and `POST /api/v1/otp/verify` are the endpoints. The code is generated with `random_int` and the challenge stores only an HMAC-SHA256 keyed by `APP_KEY`. The OTP request and verify responses, the admin pages, and the logs do not contain the code. The SMS is created through the existing message intake, so the encrypted body, lifecycle event, and outbox row are the same path as any other message; the registered device still receives that body on claim so it can transmit the SMS. The challenge row is inserted in that same transaction and requires a message id, so a rollback cannot leave an active challenge without an accepted message. Expiry, attempt limit, resend cooldown, and project-plus-phone binding apply. The admin list shows a masked phone and status only. No OTP SMS was sent.

## D-034: Recall OTP messages before cellular handoff and gate Android send (2026-11-12)

OTP challenges still store only an HMAC of the code and OTP stays disabled until an administrator enables it. Supersede, expiry, verify-side expiry, authenticated cancel, and claim scanning recall the linked message while it is still `queued`, `assigned`, or `accepted_by_device`: the message becomes `cancelled`, the encrypted body is replaced with a fixed non-code placeholder, and pending outbox rows are marked processed with `suppressed:otp_not_sendable` so dispatch never treats them as live traffic. `POST /api/v1/otp/{challenge}/cancel` is idempotent when already cancelled. If send authorization has reserved the message as `sending`, the challenge closes but the API returns `409` with `message_recalled: false`; the SMS may already have been submitted and cannot be guaranteed recalled. Device claim sets `send_authorization_required` for OTP messages. The send-authorization endpoint locks the challenge and message and transitions the message to `sending` in the same transaction before returning `send: true`. That transition is the cancellation boundary: a later cancel may invalidate verification but must return `message_recalled: false`. If Android loses the authorization response and a retry sees `already_started`, it reports `UNKNOWN` and never invokes the SMS sender again. Android calls this gate after local preconditions and immediately before `SmsManager`; without authorization or when the server is unreachable, it does not start an OTP send. No OTP SMS was sent.

## D-033: Remove SaaS from the current product and defer the long-run tests (2026-11-12)

SaaS, billing, payments, and a commercial multi-customer product are removed from the current scope. Older decision entries that mention a future commercial shape are left in place as history. The 72-hour observation, real boot recovery, and network-outage test remain written down and are deferred to the end of the project. Backups, alerts, and carrier-terms review are out of the current scope and were not implemented. This decision does not change the recorded fact that the owner confirmed one Phase 1 Yemen Mobile delivery, and it does not claim that any later phase sent another SMS.

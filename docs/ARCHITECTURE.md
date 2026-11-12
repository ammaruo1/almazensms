# ALMAZEN SMS Architecture

## Current product (2026-11-12)

The accepted deployment is the current Hostinger plan: MariaDB through Laravel's `mysql` driver, and Laravel Database Queue. Redis is not required. The outbox and the message commit stay in one SQL transaction; the scheduler publishes due outbox rows to the database queue. Diagnostics name that connection `Database Queue` and do not treat a missing Redis service as a fault. Scheduler status is unconfirmed until `scheduler:heartbeat` writes a last-run row. Defining the schedule in Laravel does not prove Hostinger cron is configured.

Each API key has a UTC daily limit and a rolling per-minute limit, edited on the admin website. Effective daily remaining is the minimum of the key's remaining messages and the project's remaining daily ceiling. The ceiling stays so every key on a project shares one budget. Idempotent replays do not consume either counter. Android tells the operator that the server enforces the key quota and does not display a fixed numeric quota.

OTP is per project and disabled by default. Only an administrator can enable it. Request, verify, and cancel are API endpoints. The code is hashed, and the SMS uses the existing message path inside the same transaction as the challenge. Supersede, expiry, cancel, and the claim scanner recall OTP messages before cellular handoff, replace the encrypted body with a non-code placeholder, and suppress pending outbox publication. Android checks `send-authorization` immediately before `SmsManager`; the server atomically reserves a valid OTP message as `sending` before authorizing it, so a competing cancel cannot claim a recall after authorization. If the response is lost, Android reports `UNKNOWN` instead of trying again. The admin OTP page shows a masked phone and status, not the code, the full number, or the message text.

SaaS, billing, payments, and a commercial multi-customer product are out of the current scope. Backups, alerts, and carrier-terms review are out of the current scope. The 72-hour, real-boot, and network-outage tests are deferred to the end of the project.

The sections below keep the phase history. Present-tense claims in those sections that require Redis, exclude OTP, or treat SaaS as current work are superseded by this section.

## Phase 2 scope

The existing `:app` Android application provides a local, manually operated SMS proof of concept. Phase 2 adds durable attempt event history, process/boot recovery to `UNKNOWN`, foreground network-change monitoring, battery/charging diagnostics, and database upgrade coverage. Phase 2 itself had no backend queue, dashboard, OTP flow, inbound SMS receiver, or background sending service, and in that phase every SMS transmission was initiated from the review-and-confirm screen by the user. Later opt-in gateway sending is specified in Phase 4 and D-034. Operator-typed messages still start on that review screen.

```mermaid
flowchart TD
    User --> Compose[Arabic RTL Compose UI]
    Compose --> Permission[Runtime permission explanation and request]
    Compose --> Diagnostics[Gateway diagnostics]
    Compose --> Gateway[AndroidSmsGateway]
    Gateway --> Validator[SmsInputValidator]
    Gateway --> Store[(SQLite attempt store)]
    Gateway --> SmsManager[Per-subscription SmsManager]
    SmsManager --> Receiver[Explicit internal callback receiver]
    Receiver --> Store
    Boot --> Recovery[Mark interrupted SENDING as UNKNOWN]
    Recovery --> Store
    Diagnostics --> Telephony[SubscriptionManager / TelephonyManager]
    Diagnostics --> Connectivity[ConnectivityManager]
```

The diagram is the Phase 2 local path. Boot and package-update recovery now also reschedule WorkManager, as specified in Phase 4 and D-034.

## Components

- `MainActivity.kt`: Compose screens for gateway status, manual send, and local history. Before sending it displays the selected SIM, complete destination, complete message, and multipart count. User confirmation is a distinct action. A permission grant does not automatically resume/send the pending message. Diagnostics polling is lifecycle-aware and pauses when the Activity is not started.
- `domain/SmsAttempt.kt`: input normalization, number masking, attempt state model, and multipart state reducer.
- `device/GatewayDiagnostics.kt`: permission-gated SIM and carrier checks, service state where permitted by API, and validated Internet connectivity. It reports unknown/unavailable values instead of inferring network availability from SIM presence. `GatewaySnapshot.simListReadable` is false when the subscription list throws, `SubscriptionManager` is missing, or `getActiveSubscriptionInfoList()` returns null. A null list is not proof the saved SIM was removed: Android 10's method is nullable, and null is also what a failed subscription-service call can look like. A non-null empty list is a completed read with no active subscription. That null-versus-empty split was not executed on an Android 10 device. The flag stays true, with an empty list, when phone-state permission is absent or `FEATURE_TELEPHONY_SUBSCRIPTION` is missing. A list that was actually read and does not contain the saved subscription is a real omission.
- `platform/AndroidSmsGateway.kt`: isolated send adapter, per-subscription `SmsManager`, input/device checks, a single-send coordinator, unique attempt IDs, explicit sent/delivery callback PendingIntents, and attempt persistence before invoking Android.
- `platform/SmsCallbackReceiver.kt`: internal, unexported callback handling. It records sent-part results and separate carrier delivery reports; temporary status reports remain unresolved.
- `platform/SmsSendCoordinator.kt`: in-process single-flight guard and timeout handling. Timed out attempts become `UNKNOWN`; no automatic resend occurs.
- `data/SmsAttemptStore.kt`: local SQLite history, per-part callback state, and append-only attempt events. Foreign keys are enabled; migration from schema v1 to v2 creates the event table without dropping attempts. It retains only a masked destination, SIM slot, sanitized carrier label, segment count, status, timestamps, and redacted diagnostic detail. It does not store the recipient's full number or message body.
- `platform/BootRecoveryReceiver.kt`: handles `BOOT_COMPLETED` after unlock. The manifest does not register `LOCKED_BOOT_COMPLETED`. The receiver calls `GatewayRecoveryCoordinator.reconcileAfterBoot`, which marks in-flight `SENDING` attempts `UNKNOWN` inside a caught failure. If that mark throws, periodic WorkManager is still restored and one immediate sync is still enqueued when the saved gateway opt-in is enabled. The receiver does not launch an Activity or a foreground service and never retries SMS. Phase 2 stopped after the local database write; the reschedule is the Phase 4 / D-034 behavior. `platform/PackageUpdateReceiver.kt` is a non-exported `MY_PACKAGE_REPLACED` receiver and uses the same mark-then-reschedule order.

## Permissions and privacy

The manifest declares `SEND_SMS`, `INTERNET`, `READ_PHONE_STATE`, `ACCESS_NETWORK_STATE`, `RECEIVE_BOOT_COMPLETED`, and `POST_NOTIFICATIONS`. `POST_NOTIFICATIONS` is requested as part of enabling API reception because FCM wake events need a user-visible, privacy-safe notice. The app declares no foreground service, `WAKE_LOCK`, or battery-optimization exemption. Firebase Messaging is initialized only when the local `google-services.json` configuration generates a Firebase app ID. No inbox, contacts, call-log, location, or receive-SMS permissions are requested. The application declares telephony hardware as optional so it can install on devices without SMS hardware. Receivers are the unexported SMS callback receiver, an unexported `BOOT_COMPLETED` receiver, an unexported `MY_PACKAGE_REPLACED` receiver, and Firebase's messaging service.

Manual sending is gated by the `SEND_SMS` runtime permission and an explicit review confirmation. Remote gateway sending uses the separate API-reception opt-in in D-034 and does not start on that review screen. Every send attempt uses a subscription selected by the user. The callback PendingIntent is explicit to the app's unexported receiver, uniquely scoped by attempt and segment, and mutable only so Android's telephony framework can attach the delivery PDU and format extras.

`SENT` means Android's sent callback succeeded; it does not mean the recipient received the message. `DELIVERED` is set only after a recognized positive status report. No status report is not evidence of delivery. Some carrier/device formats may not support reports, so absence remains `SENT` or an unresolved state. An ambiguous outcome is never automatically retried.

## Detection limits

- Active subscriptions and SIM slot/carrier details depend on `READ_PHONE_STATE` and Android/carrier exposure.
- SIM state does not prove that SMS service is available. `domain/CellularServiceRead.kt` accepts `IN_SERVICE` only from API 33+ `getServiceState(INCLUDE_LOCATION_DATA_NONE)`. On Android 10 through API 32 the deprecated read is not treated as confirmed `IN_SERVICE`: `ServiceState.STATE_IN_SERVICE` is 0, this app does not request location permission, and that call was not verified on an Android 10 device. A throw, a null, or an unconfirmed 0 stays `UNKNOWN`. Explicit out-of-service, emergency-only, and power-off values are still recorded. API 33+ acceptance was not executed on SM-S928U in this change either.
- The Internet indicator reflects validated IP connectivity, not the SMS radio/network path.
- Android can return a successful send callback before recipient delivery. Carrier delivery reports may be absent, delayed, unsupported, or use format-specific status values.
- The owner confirmed Phase 1's real SMS was sent via Yemen Mobile and delivered to the receiving handset. This is owner-reported evidence; no recipient number or message content is stored here. Delivery-report callback availability remains unverified.
- The UI's `ConnectivityManager` callbacks run only while the Activity is started and are unregistered when it stops. They refresh the on-screen indicator. While gateway automation is enabled, `platform/GatewaySyncMonitor.kt` keeps a separate default-network callback for the life of this process. The callback dies with the process. It nudges one `NetworkType.CONNECTED` WorkManager sync when validated Internet is present, at most once a minute. Pause does not unregister it; the nudge returns immediately while automation is disabled. Disconnect stops it. SIM/network diagnostics still refresh on foreground lifecycle events and at a 15-second foreground interval. Battery percentage/charging status is read from the system sticky battery intent; no wake lock or polling service is used.
- After process recreation, old `SENDING` records become `UNKNOWN` once the two-minute callback timeout expires. After `BOOT_COMPLETED` and after `MY_PACKAGE_REPLACED`, any interrupted `SENDING` records are marked `UNKNOWN` immediately and are not resent. If that mark throws, `platform/GatewayRecoveryCoordinator.kt` still restores periodic WorkManager work when the saved gateway opt-in is enabled, and still enqueues one immediate network-constrained sync. A force-stopped app receives neither `BOOT_COMPLETED` nor `MY_PACKAGE_REPLACED` until the user opens it. Opening the app runs the stale-record recovery and restores periodic work; that is the supported recovery step.
- The manifest contains the boot receiver and a non-exported `MY_PACKAGE_REPLACED` receiver. Physical `BOOT_COMPLETED` delivery was not confirmed on the Samsung after two actual reboots: no receiver log entry appeared. Package-replace delivery on SM-S928U / API 37 is also unconfirmed on device. Treat activity startup reconciliation as the verified fallback until OEM delivery is confirmed.
- Phase 2 added no WorkManager, Room, foreground service, or wake lock. Phase 4 adds constrained WorkManager for opt-in gateway sync only. There is still no Room migration, no always-on foreground service, and no wake lock. SQLite remains the durable local store.

## Phase 4 Android/backend integration

```mermaid
flowchart LR
    API[Laravel API] -->|HTTPS claim| Worker[Constrained WorkManager]
    Worker -->|encrypt payload first| Local[(SQLite v3 remote_commands)]
    Worker -->|acknowledge after commit| API
    Worker -->|opt-in + selected SIM| Sender[Existing AndroidSmsGateway]
    Sender --> SmsManager[Per-subscription SmsManager]
    SmsManager --> Callback[SMS callback receiver]
    Callback --> Local
    Local -->|sent / delivered / failed / UNKNOWN| Worker
    Worker -->|report| API
```

- `data/GatewayCredentialStore.kt` stores the device bearer credential and local command payloads as AES-GCM ciphertext using Android Keystore; configuration and the SQLite attempt database are excluded from backup/transfer. `domain/GatewayUrlPolicy.kt` permits HTTPS origins only and rejects embedded credentials, query strings, fragments, and API paths.
- `network/GatewayApiClient.kt` implements heartbeat, claim, acknowledge, and status reporting over HTTPS with bounded timeouts, no redirects, and no payload/token logging. `worker/GatewayWorkScheduler.kt` uses unique WorkManager work constrained to `NetworkType.CONNECTED`. Periodic work uses Android's 15-minute minimum interval and is inexact: Doze, App Standby, OEM battery policy, and force-stop can delay it further. There is no exact alarm. Enabling the gateway, opening the app, `BOOT_COMPLETED`, `MY_PACKAGE_REPLACED`, and a validated-Internet nudge enqueue immediate sync. On API 31+ normal immediate work may request expedited execution and falls back when quota is unavailable. A high-priority FCM data message posts a user-visible notice and appends expedited work; a normal-priority FCM message appends ordinary work. Push work uses `APPEND_OR_REPLACE` so multiple wake events are not discarded while the unique immediate chain is active. FCM carries only a UUID; Android still claims the encrypted command from the API. A token refresh uses `GatewayTokenRegistrationWorker`, which sends a heartbeat only and must not claim or send queued work. FCM can be delayed, dropped, or deprioritized; ordinary WorkManager remains the slower fallback. The immediate non-push work uses `ExistingWorkPolicy.KEEP`. Pause sets `enabled` to false and does not cancel WorkManager. The next run still heartbeats and uploads results already stored locally, then records `gateway_paused` and returns before a new claim. `claim` and `acknowledge` each reload `enabled` and do not proceed when it is false. Disconnect, and a 401/403, call `GatewayWorkScheduler.disable` and cancel the periodic, immediate, and drain work. `isReadyToSend` requires `SEND_SMS`, an available SMS feature, phone-state permission, a readable SIM list, and a listed saved SIM whose card is `READY` and whose service is confirmed `IN_SERVICE`. Confirmed `IN_SERVICE` is the API 33+ location-free result only. An Android 10 report of state 0 is `UNKNOWN` and refuses the claim; that refusal was not run on an Android 10 device. An `UNKNOWN` card, an `UNKNOWN` service, an unreadable SIM list, and a readable missing SIM all refuse the claim. None of those states is ready.
- `domain/GatewayOperationalStatus.kt` maps credentials, diagnostics, and the last sync into an Arabic status card on the gateway tab. An `UNKNOWN` SIM card and an `UNKNOWN` cellular service are `WAITING`: unconfirmed, not absence, and not ready. `smsFeatureAvailable == false` is `BLOCKED`. A failed SIM-list read (`simListReadable == false`) is `WAITING` with the headline «تعذر قراءة قائمة الشرائح»; that failure is not proof the saved SIM was removed. A readable list that is empty or omits the saved subscription stays `BLOCKED`. Revoked SMS permission, missing phone-state permission, an absent or unready card, and out-of-service, emergency-only, or power-off cellular service are `BLOCKED`. Lost, unvalidated, or unknown Internet is `WAITING`. The card states that sync is deferrable and that there is no always-on foreground service. It does not claim the gateway stays running. The sync-state label `sim_list_unreadable` is stored when `simListReadable` is false, including a null subscription list. `sim_missing` is stored only after a readable list omits the saved subscription. An Android 10 service-state value of 0 is stored as `UNKNOWN` before this card is evaluated, so it cannot become `READY`. That Android 10 path was not verified on a device. Samsung `BOOT_COMPLETED` delivery remains unconfirmed.
- `data/SmsAttemptStore.kt` migrates SQLite v2 to v3 with a unique remote-message link. The command is encrypted and committed locally before acknowledgment. A local attempt and transition to `SENDING` are transactional and precede `SmsManager`; after ambiguity/restart, the existing attempt is reconciled to `UNKNOWN` and only reported. No automatic resend path exists for `SENDING`/`UNKNOWN` commands.
- The Android adapter remains the existing `AndroidSmsGateway`; it still uses the selected `SmsManager` subscription and callback statuses. Remote requests require explicit in-app opt-in, system `SEND_SMS` permission, a chosen active SIM, a per-device one-minute interval, and backend project quotas (default 20/day, 10 requests/minute per project key; project maximum 1000/day).
- No foreground service, wake lock, root, accessibility, or hidden API is used. WorkManager is best effort and does not provide immediate/24-hour uptime.
- **Current production boundary (2026-11-12):** Laravel is deployed at `api.almazen.online`, MariaDB migrations and the two Hostinger minute crons have been applied. Owner-provided Android Firebase configuration is present locally as an ignored file, and the handset has acquired an FCM token and made a successful heartbeat. The backend `.env` still lacks `FIREBASE_CREDENTIALS`; migration deployment, server-side token persistence and FCM push delivery are unverified. The admin Messages page now includes a confirmed, quota-limited test-SMS form and shows full numbers/bodies to administrators; these source changes have not been deployed. The current debug APK was rebuilt, installed, and its `MainActivity` observed resumed on SM-S928U / Android 17 (API 37); unit tests and lint evidence are in `docs/TEST_PLAN.md`. Actual FCM wake, device claim/ack/report, OTP expiry at a sleeping device, and long-run/reboot/network-recovery behavior remain unverified. No SMS test was sent in this change.

## Verified development/device environment

- Android Studio Panda 3 (`2025.3.3`); Android Gradle Plugin `9.1.1`; Gradle Wrapper `9.3.1`.
- Kotlin and Compose compiler plugin `2.2.10`; Jetpack Compose BOM `2024.09.00`.
- Gradle uses Android Studio JBR 21.0.9. Host `java` command reports Java 25.0.2.
- `compileSdk` 36.1, `targetSdk` 36, `minSdk` 26; app ID `com.almazen.sms`.
- Connected Samsung `SM-S928U`, Android 17/API 37, security patch `2026-09-05`.
- ADB properties observed `Yemen Mobile`, SIM state `LOADED,NOT_READY`, radio network type `LTE,Unknown`, airplane mode off. These device properties are observations, not proof SMS is usable.

## Phase 3 backend foundation

The Android app remains unchanged. A separate `backend/` Laravel 13 API is introduced; Phase 3 does not send SMS and does not add Dashboard or OTP functionality.

```mermaid
flowchart LR
    Client[API client] -->|X-API-Key + idempotency key| API[Laravel REST API]
    API -->|single MySQL transaction| DB[(MySQL 8.4: message + event + outbox)]
    Scheduler[Laravel scheduler] --> Outbox[Outbox dispatcher]
    DB --> Outbox
    Outbox -->|leased event| Redis[(Redis 7 queue)]
    Redis --> Worker[Laravel queue worker]
    Worker --> DB
    Android[Registered Android device] -->|HTTPS in production: heartbeat / claim / report| API
    DB -->|atomic claim from committed SQL row| Android
```

- Laravel 13.42 / PHP `^8.3`; local PHP 8.3.36 and Composer 2.11.1 were used. Runtime dependencies include Sanctum 4.4 and Predis 3.7.1. Docker setup pins MySQL 8.4 and Redis 7 Alpine. The selected Compose topology has no public database or Redis ports and binds the development API only to loopback.
- Operators are provisioned by `php artisan almazen:user:create`; there is no public registration route. Sanctum bearer tokens authenticate operator actions and expire after 60 minutes; expired token rows are pruned daily. Admin-only user management can list accounts and modify role/status, while preventing self-lockout. Owner scoping is enforced for project, API key, and device routes. Project API keys and per-device bearer credentials are shown once and persisted as SHA-256 hashes. API key creation/revocation, device enrollment/revocation, project creation, and user updates append audit rows.
- `POST /api/v1/messages` validates E.164-shaped destinations and message size, requires `Idempotency-Key`, enforces 10 requests/minute per API key and a project daily quota (default 20, configurable up to 1000), encrypts the full destination and body with Laravel's configured application key, and atomically reserves quota and writes the message, lifecycle event, and outbox event. The key/payload comparison digest is HMAC-SHA-256. A repeated key with the same payload returns the existing ID without consuming quota; a different payload returns HTTP 409.
- The scheduled dispatcher leases due outbox rows and dispatches idempotent Laravel jobs to Redis. If dispatch throws, it releases the lease for another scan. Devices claim from committed MySQL rows under a transaction, so durable accepted work remains queryable if Redis is unavailable. In Phase 3 the queue job records durable queue processing only; Android SMS initiation and end-to-end result reconciliation are Phase 4.
- Message state transitions and append-only event rows are centrally checked. `sent_to_network` means only a device reported platform/network handoff; `delivery_reported` is a separate state. `unknown` is not automatically retried. API/device isolation checks constrain status and claim lookups to the authenticated project/device.
- Production API routes reject non-HTTPS requests. Reverse-proxy TLS termination, trusted-proxy configuration, a public domain, certificates, production secrets, backup/retention policy, and live MySQL/Redis verification remain deployment decisions. `php artisan serve` and the provided Compose file are a local foundation, not production hosting.
- Backend tests use SQLite `:memory:` for portable feature testing. MySQL 8 and Redis 7 services are defined but were not started in this environment because Docker Desktop's daemon was unavailable. SQLite tests do not verify MySQL locking/driver behavior or Redis delivery guarantees.

## Phase 5 web dashboard

The Laravel web entry point redirects to `/admin`; unauthenticated visitors use `/login`. Session login is restricted to active administrators and rate-limited. Each dashboard route uses session authentication and the active-admin guard. Blade pages use RTL Arabic layout and existing Tailwind/Vite assets. The sections cover project ownership/status/daily quota, account roles/status, gateway devices and recent heartbeats, API key prefixes/revocation, message status search, daily project usage, audit events, and a point-in-time database/Redis/outbox/device-health view. State changes use CSRF-protected forms and append audit events.

Secrets are only rendered in their immediate creation response with no-store, no-referrer, and noindex headers; the database persists only their SHA-256 hashes. Dashboard message views use only message IDs/status/attempt count/timestamps; they do not decrypt or display destination/body. Admin bootstrap is through `php artisan dashboard:admin`, which asks for the password through hidden prompts and enforces length/character rules. No self-registration, OTP, or administrative SMS-send action is present. The dashboard has not been deployed; its diagnostics page is an application snapshot and is not an external alerting/monitoring system.

## Hostinger shared-host deployment layout

The hPanel subdomain `api.almazen.online` is listed with public document root `/home/u893896051/domains/almazen.online/public_html/api`. The Laravel application root is to be `/home/u893896051/apps/almazen-sms-api`, outside the web tree. The subdomain document root receives only Laravel `public/` assets plus a front controller pointing to that private root. `.env`, `vendor`, `storage`, migrations, and app source remain outside the web tree. Hostinger CLI PHP 8.3.36 provides `pdo_mysql`, and the server has Composer/rsync/zip utilities; the MySQL server version and hPanel database credentials have not been verified.

On this shared plan, queue, cache, and sessions use MySQL tables. The scheduler invokes the transactional outbox dispatcher once per minute; a bounded queue worker cron processes `outbox` jobs and exits. Android still claims messages from SQL directly. Cron is best-effort and subject to shared-host task/runtime quotas; it is not a daemon or uptime guarantee. Deployment and production traffic remain pending database provisioning, TLS/DNS verification, and live API/device checks.

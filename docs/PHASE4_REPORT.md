# Phase 4 — Android/API Gateway Integration Report

**Date:** 2026-11-11  
**Scope:** Continue toward operational readiness after owner approved automatic API-originated sends subject to configured limits/safeguards. No Dashboard, OTP, or Phase 5 work was started during Phase 4.

**Later note (2026-11-12):** The owner accepted the current Hostinger plan with MariaDB and Laravel Database Queue. Per-key quotas and a disabled-by-default OTP flow were added locally after this report, and the Phase 5 dashboard already existed locally by then. This report's record stands: Phase 4 did not send SMS, and live Android-to-backend traffic was not completed in that phase. The owner's earlier confirmed Phase 1 Yemen Mobile delivery remains a separate, earlier fact. Nothing in the 2026-11-12 work was published to Hostinger.

## Implementation

- Connected the existing Android app to Laravel v1 heartbeat, message claim, acknowledgement, and result-report routes over HTTPS.
- Added explicit operator opt-in, permission and selected-SIM checks, one-device-at-a-time sending, and a persistent minimum 60-second interval for remote SMS. Backend intake defaults to 20 messages per project per UTC day and 10 API submissions per minute; daily project cap is configurable to at most 1000 and is not a carrier/cost guarantee.
- Kept the accepted `AndroidSmsGateway` / `SmsManager` send path. The UI's manual send and review flow remains. No tests transmitted an SMS.
- Added encrypted Android Keystore storage for the device bearer credential and locally queued recipient/body. The SQLite schema migrates v2→v3, retains old history, and stores an idempotent remote-command ID before the API acknowledgment. A send attempt and command transition to `SENDING` are committed before invoking Android. Interrupted sends resolve to `UNKNOWN`, are uploaded for reporting, and are not automatically resent.
- Added network-constrained WorkManager polling and result upload. Periodic work uses Android's 15-minute minimum and may be delayed; there is no always-on service, wake lock, or 24/7 claim.
- Added transactional project/day quota enforcement in Laravel and same-device replay of an unacknowledged claim, covering a lost claim response.

## Files changed

- Android: `app/build.gradle.kts`, `gradle/libs.versions.toml`, `app/src/main/AndroidManifest.xml`, `app/src/main/res/xml/backup_rules.xml`, `app/src/main/res/xml/data_extraction_rules.xml`, `app/src/main/java/com/almazen/sms/MainActivity.kt`, `data/SmsAttemptStore.kt`, `data/GatewayCredentialStore.kt`, `domain/GatewayUrlPolicy.kt`, `network/GatewayApiClient.kt`, `worker/GatewayWorkScheduler.kt`, `worker/GatewaySyncWorker.kt`, `platform/AndroidSmsGateway.kt`, `platform/SmsCallbackReceiver.kt`; tests in `app/src/test/java/com/almazen/sms/SmsDomainTest.kt` and `app/src/androidTest/java/com/almazen/sms/SmsAttemptStoreTest.kt`.
- Backend: project quota migration/model, project update validation, atomic daily quota reservation, same-device claim replay, and feature coverage in `backend/database/migrations/2026_11_11_000005_add_project_daily_message_limits.php`, `backend/app/Models/ProjectDailyUsage.php`, `backend/app/Models/Project.php`, `backend/app/Http/Controllers/Api/V1/ProjectController.php`, `backend/app/Http/Controllers/Api/V1/MessageController.php`, `backend/app/Http/Controllers/Api/V1/DeviceMessageController.php`, `backend/app/Providers/AppServiceProvider.php`, `backend/tests/Feature/GatewayApiTest.php`, and `backend/openapi.yaml`.
- Documentation updated: `README.md`, `docs/MASTER_SPECIFICATION.md`, `docs/ARCHITECTURE.md`, `docs/DECISIONS.md`, `docs/OPEN_QUESTIONS.md`, `docs/TEST_PLAN.md`, `docs/OPERATIONS.md`, `docs/API.md`.

## Commands and observed results

Android build, static analysis, unit tests, and connected tests:

```powershell
.\gradlew.bat build lint testDebugUnitTest connectedDebugAndroidTest --console=plain --no-daemon
```

Observed final result: `BUILD SUCCESSFUL in 35s`; 123 tasks. Five unit tests passed with no failures/errors/skips. Eight instrumentation tests passed with no failures/errors/skips: one `MainActivityLaunchTest` and seven `SmsAttemptStoreTest` cases. These include history retention on SQLite v2→v3 upgrade and remote-command interruption recovery to `UNKNOWN` without resend. Lint completed with 22 non-fatal warnings: 2 Android Gradle plugin update suggestions, 4 dependency update suggestions, 1 newer-version suggestion, 3 synchronous SharedPreferences writes used to persist safety/credential state, 5 KTX suggestions, and 7 unused starter color resources. There were zero lint errors. A first compile attempt failed due to a missing `GatewaySettings` import; it was fixed before the passing runs.

Backend checks run from `backend/` using a temporary portable PHP 8.3.36 binary:

```powershell
& "$env:TEMP\almazen-phase3-tools\php83\php.exe" artisan test --compact
& "$env:TEMP\almazen-phase3-tools\php83\php.exe" vendor/bin/pint --test
```

Observed: **17 tests / 93 assertions passed**; Pint passed. Tests use Laravel's SQLite in-memory configuration and queue fakes. Docker could not connect to `dockerDesktopLinuxEngine`, so MySQL 8.4 and Redis 7 were not started or validated.

Device observed: Samsung `SM-S928U`, Android 17, API 37. `adb install -r app/build/outputs/apk/debug/app-debug.apk` returned `Success`. `adb shell am start -n com.almazen.sms/.MainActivity` opened the app and `dumpsys activity` reported `com.almazen.sms/.MainActivity` as top-resumed; a process ID was observed. The UI displayed the SIM permission explanation in the gateway screen; this check did not grant an Android permission. Connected instrumentation ran successfully; AndroidX test runner emitted a non-fatal shell `appops` message (`No UID for androidx.test.services in user 0`) and still ran all eight tests. No real SMS was sent by these checks.

`apksigner verify --verbose app/build/outputs/apk/debug/app-debug.apk` passed (one signer; v2 signature verified). Verification of `app-release-unsigned.apk` returned `DOES NOT VERIFY` / `Missing META-INF/MANIFEST.MF`, confirming it is not uploadable as a signed release. SHA-256 of the unsigned release artifact: `F299F884E59582121EE995E560053915AF5C9BBD2F7F2759BBAEADC4769A2A9A`.

## Deployment and safety status

**Not production-ready or uploaded.** The owner designated `almazen.online` and pointed to a desktop deployment launcher. The root URL resolves and redirects to `/shop/`; `/shop/` redirects to the existing storefront at `online.almazenye.com`. DNS lookup did not find `api.almazen.online`, and the corresponding subdomain/public directory is absent. The existing store occupies `/home/u893896051/domains/almazen.online/public_html`.

Read-only deployment preflight found: SSH succeeds via the configured `almazen-host` alias; the separate Ed25519 key referenced by the other deploy helper was rejected. The shared host's default CLI PHP is 7.4.33; PHP 8.3.36 is available at `/opt/alt/php83/usr/bin/php` with the required Laravel extensions, including `pdo_mysql` and the PHP Redis extension. There is no Docker/Podman, Redis server/CLI or local Redis listener, supervisor, or `crontab` binary. MySQL server version/database availability and external Redis availability were not verified. The current Compose topology (PHP 8.3 + MySQL 8 + Redis workers) cannot be run on this shared hosting as-is; no DNS, directory, website, or server file was changed.

The pointed BAT file only invokes `C:\laragon\www\shop\scripts\deploy-desktop.ps1`; that script stages/commits/pushes the separate shop Git repository to Hostinger's `main` hook, with file deletions applied to that storefront. It was not run. `deploy-hostinger.ps1` also targets the existing `public_html` store root and is not a Laravel deployment mechanism. The API must use a separately provisioned VPS/subdomain preserving the selected MySQL 8 + Redis topology, or the owner must explicitly approve a redesign for shared-host capabilities. Until then Android heartbeat/claim/ack/report has not been exercised against a live backend.

The generated release APK at `app/build/outputs/apk/release/app-release-unsigned.apk` is unsigned because no production signing keystore is configured. The tested debug APK is at `app/build/outputs/apk/debug/app-debug.apk` and is not a production release artifact.

The app declares `SEND_SMS`. Google Play's current policy says apps must be registered as the default SMS or Assistant handler before declaring/requesting restricted SMS permissions. This gateway is not a default SMS handler; Google Play upload is therefore blocked as configured. A private/internal APK route may be possible after signing and owner selection. See [Google Play SMS and Call Log permissions policy](https://support.google.com/googleplay/android-developer/answer/18265346?hl=en).

WorkManager periodic tasks have a 15-minute minimum and Android may defer them; this is best-effort polling, not immediate service. Android 15+ also limits `dataSync` foreground services, so no always-on foreground service was added. References: [WorkManager periodic work](https://developer.android.com/develop/background-work/background-tasks/persistent/getting-started/define-work), [foreground-service timeouts](https://developer.android.com/develop/background-work/services/fgs/timeout).

Remaining evidence: Phase 2's 72-hour run; physical reboot recovery and visual network-loss/reconnect; MySQL/Redis service tests; deployed HTTPS heartbeat/claim/report; signed release; distribution decision; and production host/domain/access/secrets. The owner's earlier confirmation of one real Yemen Mobile delivery remains the only real-SMS evidence; Phase 4 did not send another message.

Git metadata is unavailable because this workspace has no `.git` directory; no repository was created. The project-local `.env` was not printed or included in this report.

## Phase gate

At the time of the Phase 4 gate, integration code and local automated validation were implemented, but end-to-end integration and deployment gates were not met. Phase 5 dashboard was later implemented locally; see the current status in `docs/MASTER_SPECIFICATION.md`, `docs/TEST_PLAN.md`, and `docs/OPERATIONS.md`. Do not claim the system is ready for live API traffic, 24/7 operation, or Google Play upload based on Phase 4 evidence.

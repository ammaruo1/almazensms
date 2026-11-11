# Test Plan and Phase Results

## Deferred and out of scope (2026-11-12)

The owner deferred these checks to the end of the project. The procedures stay in this file and in `docs/PHASE2_72H_LOG.md`. They were not run in the 2026-11-12 change and are not passes:

- 72-hour stability observation
- physical boot recovery on the Samsung
- network interruption and reconnection

Out of the current scope, and not implemented: backups and restore, alerting, carrier-terms review, SaaS, billing, payments, and a commercial multi-customer product.

## Local tests added on 2026-11-12

Feature and unit tests were added or updated for per-key quotas, idempotent replay, OTP, admin quota/OTP pages, and database-queue diagnostics. They were **not run**. Do not treat this paragraph as a pass. Earlier pass counts below are historical results from the dates in those sections.

### Android API 29+ background execution (added, not run this round)

| Suite | Files | Coverage intent |
| --- | --- | --- |
| JVM unit | `app/src/test/.../BackgroundExecutionPolicyTest.kt` (parameterized API 26, 29, 30, 31, 33, 34, 35, 36, 37), `BackgroundExecutionPolicyMinSdkTest.kt` | WorkManager-first strategy, no always-on FGS, expedited work from API 31, FGS/notification rules, operational limits text |
| Device | `app/src/androidTest/.../GatewayWorkSchedulerApiTest.kt`, `GatewayRecoveryCoordinatorTest.kt` | `enqueueNow` on API 29+ images, recovery coordinator behavior on the connected device |

Run at project end: `.\gradlew.bat testDebugUnitTest` and, with an API 29 emulator or the physical API 37 device, `.\gradlew.bat connectedDebugAndroidTest`.

## Phase 1 result

## Phase 1 result

The owner reports one real SMS sent from ALMAZEN SMS through Yemen Mobile and received by the intended handset. Phase 1 is accepted as complete by the owner. No recipient number or message text is retained here. This is owner-confirmed receipt; a device/carrier delivery-report callback was not recorded.

## Phase 2 automated tests

Final code verification command (PowerShell, project root):

```powershell
.\gradlew.bat build lint testDebugUnitTest connectedDebugAndroidTest --console=plain
```

Results:

| Check | Result |
| --- | --- |
| `build` | PASS, `BUILD SUCCESSFUL`; Debug and Release assembled. |
| `lint` | PASS, 0 errors, 14 warnings. Existing dependency/tool update notices and unused starter resources remain. |
| `testDebugUnitTest` | 4 tests, 0 failures/errors/skips (existing domain suite; Gradle task passed). |
| `connectedDebugAndroidTest` | 6 tests, 0 failures/errors/skips on `SM-S928U - 17`. Includes app launch, SQLite process recreation, schema v1→v2 migration, stale callback recovery, and simulated boot recovery. |

Gradle emitted the known non-fatal setup output `appops set androidx.test.services MANAGE_EXTERNAL_STORAGE allow` / `No UID for androidx.test.services in user 0`; tests still started and completed successfully.

During implementation, an intermediate compile failed because the new event insert helper called `insertOrThrow` outside its database receiver. The helper was corrected to use the writable database; the final combined command above passed.

Instrumentation verifies database/receiver behavior, but does not prove a physical phone reboot delivers BOOT_COMPLETED or that carrier callbacks survive reboot.

## Device checks

- Device available over authorized ADB: Samsung `SM-S928U`, Android 17/API 37.
- Owner-confirmed Phase 1 SMS success is above. No real SMS was sent during Phase 2 automated tests.
- Two physical reboots executed with `adb reboot`; `sys.boot_completed` returned `1` each time, Android API 37 returned, and the package remained installed. The app did not auto-launch (by design); explicit `am start` brought `MainActivity` to the foreground. Package metadata lists the boot receiver, but no `AlmazenBootRecovery` log line appeared after the clean reboot. Therefore actual receiver delivery/database mutation is **not verified** on this Samsung; instrumentation only simulated the receiver call. A persisted in-flight send across physical reboot was not tested.
- A short Internet interruption was induced with `adb shell svc data disable` and `adb shell svc wifi disable`; `dumpsys connectivity` reported `Active default network: none`. Both settings were restored to their original values (`mobile_data=1`, `wifi_on=1`), and the active default network returned (`105`). No SMS was sent and the local DB was untouched. The UI was behind the lock screen during this observation, so visual refresh of its indicator was not verified.
- Current app permissions and SIM state should be read again in final verification; earlier recorded properties were `Yemen Mobile`, SIM `LOADED,NOT_READY`, `LTE,Unknown`; those readings do not establish SMS availability.

## 72-hour stability run

The observation has a recorded baseline and is in progress, not complete. See `docs/PHASE2_72H_LOG.md`. Do not report 72-hour stability until actual end timestamp and all interruptions/crashes are recorded.

## Phase 0 historical baseline

Phase 0 build, lint, local tests, and instrumentation passed before Phase 1; original detailed tool output and warning counts remain in the previous report history. Project docs and decisions preserve the phase boundary.

## Phase 3 backend tests

Phase 3 results, exact PHP/Composer versions, failure/fix history, Docker limitation, and all executed commands are recorded in `docs/PHASE3_REPORT.md`. Final `artisan test --compact` result: 16 tests, 84 assertions, all passed. This includes actual `MessageStatus` unit tests and feature tests using SQLite in-memory plus queue fakes. Coverage includes operator authentication/disabled users, admin user management, project ownership, one-time hash-only keys, revoked key rejection, encrypted message fields, idempotency and conflict, atomic initial event/outbox creation, device heartbeat/claim/ack/status, invalid lifecycle transitions, production HTTP rejection, and idempotent outbox processing. No SMS was sent.

Historical pending checks from the Phase 3 write-up, not passes: Compose MySQL/Redis were not booted, and live proxy/Android integration was not run. On 2026-11-12 the production target changed to MariaDB and Laravel Database Queue. Those services were not exercised on Hostinger in this change.

## Phase 5 dashboard checks (2026-11-11)

Commands run from `backend/` using PHP 8.3.36:

```powershell
php artisan route:list --path=admin
php artisan test --compact tests\Feature\DashboardAccessTest.php
php artisan test --compact tests\Feature\CreateDashboardAdminCommandTest.php
php artisan view:cache
npm install --package-lock-only --ignore-scripts --no-audit --no-fund
npm ci --ignore-scripts --no-audit --no-fund
npm run build
```

Actual results: 17 admin routes listed; 9 dashboard feature tests / 78 assertions passed, covering auth/role/disabled status, five-attempt login throttling, project quota changes and audit, admin account control/self-lockout, one-time hashed credentials/no-store headers, key/device revocation, message privacy, cross-project revocation, and rendering all nine dashboard sections. The admin bootstrap CLI test file passed 2 tests / 12 assertions, including hidden password creation and weak-password rejection. A database-queue integration test passed on SQLite, confirming outbox dispatch and `queue:work --stop-when-empty` without Redis. The full backend suite passed 29 tests / 189 assertions. `view:cache` passed. npm lockfile resolution and clean install completed (55 packages), and Vite production build passed. Vite reports an optional `fontaine` package warning from Laravel font optimization; assets still built successfully.

Final follow-up checks: `php artisan test --compact` passed the full suite (29 tests, 189 assertions); `vendor/bin/pint --format agent` passed; `npm run build` passed again after final edits; and `php artisan view:cache` passed. `vendor/bin/pint --dirty --format agent` was attempted first but Pint reported `--dirty` requires Git; this workspace has no `.git`, so the full formatter was used instead. These tests do not represent live Hostinger MySQL/version, DNS/TLS, cron, or resource-limit evidence. No browser visual QA or Android/backend end-to-end request was run. The dashboard is not yet a verified live production service.

## Phase 4 current integration verification

Final command: `.\gradlew.bat build lint testDebugUnitTest connectedDebugAndroidTest --console=plain --no-daemon`. Result: `BUILD SUCCESSFUL`; 5 unit tests and 8 connected instrumentation tests passed on `SM-S928U`, Android 17/API 37. Device instrumentation includes Compose launch, SQLite v1 and v2 migrations, durable command records, and interrupted send→`UNKNOWN` reporting-only behavior. Lint completes with 22 non-fatal warnings (dependency/tool update notices, synchronous durable preference commits, KTX suggestions, and unused starter colors); zero lint errors. AndroidX runner logs a non-fatal `appops` failure (`No UID for androidx.test.services in user 0`) and then successfully runs all eight cases. Exact details and limitations are in [`PHASE4_REPORT.md`](PHASE4_REPORT.md).

Backend portable checks were rerun with PHP 8.3.36: `php artisan test --compact` passed 17 tests/93 assertions and `vendor/bin/pint --test` passed. These use the existing SQLite in-memory test config and do not verify production MySQL/Redis. Docker could not connect to `dockerDesktopLinuxEngine`; therefore no live deployment, server-to-phone HTTP integration, or end-to-end SMS request was run. No real SMS was sent.

Deferred on 2026-11-12 to the end of the project: 72-hour stability observation, physical reboot, and Internet reconnect validation. Still not production evidence: deployed HTTPS heartbeat/claim/ack/report, signed APK, and chosen distribution. MySQL 8 and Redis are no longer the required production services; MariaDB and Laravel Database Queue are the selected hosting path and were not exercised on Hostinger in this change. Google Play submission is blocked pending eligibility as a default SMS/Assistant handler for the manifest's `SEND_SMS` permission. Do not represent the generated unsigned APK or local API tests as production-upload readiness.

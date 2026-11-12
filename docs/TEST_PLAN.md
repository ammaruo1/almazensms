# Test Plan and Phase Results

## Deferred and out of scope (2026-11-12)

The owner deferred these checks to the end of the project. The procedures stay in this file and in `docs/PHASE2_72H_LOG.md`. They were not run in the 2026-11-12 change and are not passes:

- 72-hour stability observation
- physical boot recovery on the Samsung
- network interruption and reconnection

Out of the current scope, and not implemented: backups and restore, alerting, carrier-terms review, SaaS, billing, payments, and a commercial multi-customer product.

## Local tests added on 2026-11-12

Feature and unit tests were added or updated for per-key quotas, idempotent replay, OTP, OTP withdrawal/cancel/send-authorization, admin quota/OTP pages, and database-queue diagnostics. Focused OTP withdrawal tests and `OtpSendGateTest` were added on the `otp-secure-foundation` branch. Limited verification on 2026-11-12 (worktree `almazensms-otp-wt`, branch `otp-secure-foundation`): **`OtpSendGateTest` — PASS** (`.\gradlew.bat :app:testDebugUnitTest --tests "com.almazen.sms.OtpSendGateTest"`, 4 tests, 0 failures/errors/skips). **`OtpWithdrawalTest` — not run:** `php` is not on `PATH`; the only CLI found was Laragon `php-8.1.10`, while `backend/composer.json` requires `^8.3` and `vendor/bin/pint` refuses to start on 8.1. **`vendor/bin/pint --dirty` — not run** for the same reason. Full backend suite, 72-hour observation, and real SMS were not run. `OtpWithdrawalTest` includes `test_send_authorization_reserves_sending_before_cancellation_can_recall` and `test_cancellation_before_send_authorization_prevents_the_send_reservation` for atomic `sending` reservation vs cancel ordering; those cases remain unrun here. Do not treat this paragraph as a pass for the entire product. Earlier pass counts below are historical results from the dates in those sections.

### Android API 29+ background execution (added, not run this round)

| Suite | Files | Coverage intent |
| --- | --- | --- |
| JVM unit | `app/src/test/.../BackgroundExecutionPolicyTest.kt` (parameterized API 26, 29, 30, 31, 33, 34, 35, 36, 37), `BackgroundExecutionPolicyMinSdkTest.kt` | WorkManager-first strategy, no always-on FGS, expedited work from API 31, FGS/notification rules, operational limits text |
| Device | `app/src/androidTest/.../GatewayWorkSchedulerApiTest.kt`, `GatewayRecoveryCoordinatorTest.kt` | `enqueueNow` on API 29+ images, recovery coordinator behavior on the connected device |

`BackgroundExecutionPolicyTest.kt` and `GatewayWorkSchedulerApiTest.kt` are in this worktree. `BackgroundExecutionPolicyMinSdkTest.kt` and `GatewayRecoveryCoordinatorTest.kt` exist on main at HEAD `6f756a5` and are not in this worktree. This worktree patch does not include those two files. Their names stay because they are real main-HEAD references, not files introduced by the readiness change. None of these four were run for this documentation update.

Run at project end: `.\gradlew.bat testDebugUnitTest` and, with an API 29 emulator or the physical API 37 device, `.\gradlew.bat connectedDebugAndroidTest`.

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

## Dedicated-phone FCM follow-up (2026-11-12)

- Code path: high-priority FCM data messages post a privacy-safe notification and append expedited WorkManager sync; normal-priority messages append ordinary constrained work. There is no screen-on poll or foreground service.
- Android `:app:assembleDebug` compiled the current modified sources and produced an APK at `app/build/outputs/apk/debug/app-debug.apk`. The first compilation exposed a stale reference after the keep-alive service removal; it was corrected. An initial test attempt was blocked by Windows native-memory/pagefile pressure, but the later bounded single-worker verification below completed.
- `app/google-services.json` is absent at the time of this check; Firebase server credentials are also not supplied. Therefore FCM registration and delivery, background wake, and OTP delivery latency are unverified. The migration adding `devices.fcm_token` exists locally but is not deployed.
- No SMS was sent. At the time of the note, no Build/Lint/test success was claimed beyond APK assembly and Kotlin compile.

## Android reliability follow-up (2026-11-12)

- Source cleanup: removed stale user-facing claims about a screen-on five-second poll. The supported unattended path is FCM when configured, plus constrained WorkManager as a delayed fallback; Android/Samsung do not guarantee continuous execution.
- API compatibility: isolated the API 33 location-free service-state call behind an explicit SDK branch and annotated the guarded helper so Android 10 does not call that overload.
- Build: `.\gradlew.bat --no-daemon --max-workers=1 "-Dorg.gradle.jvmargs=-Xmx1024m -XX:MaxMetaspaceSize=512m -Dfile.encoding=UTF-8" :app:assembleDebug --console=plain` — **BUILD SUCCESSFUL**; debug APK produced.
- Unit tests and lint: the same bounded Gradle invocation with `:app:testDebugUnitTest lint` — **BUILD SUCCESSFUL**. XML reports list 6 unit-test suites, 88 tests, 0 failures/errors/skips. Lint completed with 32 warnings and 0 errors. A prior lint run found one NewApi error; after isolating the call, lint passed.
- Device check: `adb install -r app/build/outputs/apk/debug/app-debug.apk` returned `Success`; SM-S928U reported Android 17 / API 37, package process had a PID, and `MainActivity` was the resumed activity. Package inspection listed `GatewayFirebaseMessagingService` and no keep-alive Activity or always-on foreground service. This verifies installation/startup only, not background FCM delivery or SMS.
- Firebase client config (`app/google-services.json`) and server service-account credentials remain absent, and the production FCM-token migration/notifier has not been deployed. Therefore FCM wake, actual device claim/report, screen-off latency, reboot recovery, Internet interruption recovery, and 72-hour stability are **not verified**. No real SMS or OTP was sent in this check.
- Still required before relying on unattended OTP: deploy the FCM migration/notifier, provide matching Firebase configuration securely, verify token registration and high-priority push on SM-S928U with screen off, test expiry/cancel/no duplicate behavior without sending SMS, then separately perform an owner-approved test SMS. The deferred reboot, network outage/recovery, and 72-hour run remain at the final project gate.

## Background reliability additions (2026-11-12)

The JVM unit cases listed below were included in the bounded `:app:testDebugUnitTest` run above (92 total tests across 6 suites; zero failures/errors/skips). This does not mean the physical-device conditions were tested. Connected instrumentation for the new receiver/recovery behavior and the 72-hour observation remain deferred and are not passes.

JVM unit `app/src/test/java/com/almazen/sms/GatewayOperationalStatusTest.kt` (JVM suite included in the 92-test run above):

- `pausedWhenGatewayDisabled`
- `blockedWhenSmsPermissionMissing`
- `waitingWhenInternetUnavailable`
- `waitingWhenLastSyncIsStale`
- `readyWhenHealthy`
- `blockedWhenPhoneStatePermissionMissing`
- `blockedWhenSavedSimNotListed`
- `blockedWhenSelectedSimAbsent`
- `blockedWhenNoCellularService` (`OUT_OF_SERVICE`, `EMERGENCY_ONLY`, `POWER_OFF`)
- `waitingWhenInternetUnvalidated`
- `waitingWhenInternetStatusUnknown`
- `waitingWhenSimCardUnknown` (does not use the absent-SIM copy)
- `waitingWhenCellularServiceUnknown` (does not use the out-of-service copy)
- `blockedWhenSmsFeatureUnavailable`
- `blockedWhenReadableSimListOmitsSavedSim` (`simListReadable` true, empty list, headline «الشريحة المحفوظة غير ظاهرة»)
- `waitingWhenSavedSimMissingBecauseListWasNotRead` (`simListReadable` false, headline «تعذر قراءة قائمة الشرائح»)

JVM unit `app/src/test/java/com/almazen/sms/CellularServiceReadTest.kt` (JVM suite included in the 92-test run above):

- `android10ReportedInServiceIsNotConfirmed` (API 26 and 29–32, reported state 0 stays `UNKNOWN`)
- `android10LocationFreeFlagDoesNotConfirmInService`
- `android10NullOrUnknownCodeStaysUnknown`
- `android10ExplicitOutageStatesStayExplicit` (`OUT_OF_SERVICE`, `EMERGENCY_ONLY`, `POWER_OFF` still pass through)
- `api33LocationFreeInServiceIsAccepted` (API 33, 36, and 37 only when the location-free call flag is true)
- `api33WithoutLocationFreeCallDoesNotAcceptInService`
- `api33NullReadStaysUnknown`
- `unconfirmedAndroid10ServiceIsWaitingNotReady` (API 29 state 0 evaluates to `WAITING`, not `READY`)

These cases do not call `SmsManager` and do not send SMS. They were run as JVM tests, not executed on an Android 10 device or on SM-S928U. A returned `IN_SERVICE` from API 33+ code is not evidence that a handset produced that value.

Device `app/src/androidTest/java/com/almazen/sms/PackageUpdateReceiverTest.kt`: `interruptedRemoteAttemptBecomesUnknownWithoutClaimingSend` calls `SmsAttemptStore.markInterruptedAttemptsUnknown` directly on a local `SENDING` remote attempt and expects `UNKNOWN`. It does not invoke `PackageUpdateReceiver`, does not send `MY_PACKAGE_REPLACED`, does not enqueue WorkManager, and does not call the API.

Pause-before-claim and boot-failure-still-reschedules have no JVM seam in this worktree and were not run. The package-update failure path uses the same caught reschedule and was not run either. Physical force-stop, Doze delay, Samsung boot delivery, and on-device SIM behavior on SM-S928U are not evidenced by this change.

## Backend verification follow-up (2026-11-12)

- Installed portable PHP CLI 8.3.36 NTS VS16 x64 under the user-local developer tools directory; Laragon's PHP 8.1 was not changed. SHA-256 was checked against the official php.net download listing: `1a097d52b7a68622cdc723073b61cc70caf79c941218ff65b2d8441dbc6b10d4`.
- From `backend`, `php artisan test --compact` completed: **61 tests passed, 469 assertions**.
- `php vendor/bin/pint --test` completed successfully.
- These checks ran against the local test configuration. They do not establish that the new FCM migration/notifier is deployed or that Firebase push reaches the handset. No SMS was sent.
- Added an explicit replay assertion to `OtpWithdrawalTest`: after one successful OTP send authorization reserves `sending`, a second authorization returns `send=false` / `already_started`. Focused rerun: **6 tests passed, 64 assertions**; Pint passed again.

## Gateway status alerts (2026-11-12)

- Added transition-only local notifications for gateway failure and recovery; repeated identical states are suppressed, paused/initial states are silent, and notification bodies contain no SMS content or recipient. Added pure JVM tests for failure, recovery, and duplicate suppression.
- Verification: `:app:testDebugUnitTest lint :app:assembleDebug` — **BUILD SUCCESSFUL**. XML reports: **7 suites, 92 tests, 0 failures, 0 errors, 0 skipped**. Lint: **32 warnings, 0 errors**.
- Installed with `adb install -r app/build/outputs/apk/debug/app-debug.apk` (**Success**) and launched `MainActivity`; the app process returned PID `32188` on the connected SM-S928U. This is startup evidence only; a notification delivery under a real background outage was not simulated.
- No SMS was sent. Notification behavior still depends on the user granting Android notification permission; FCM and production outage delivery remain unverified until Firebase/server configuration is deployed.
- Follow-up: alert text now identifies the known category (internet, credentials, permission, SIM, cellular service, or server) and suggests an action. Re-verification after this change: `:app:testDebugUnitTest lint :app:assembleDebug` **BUILD SUCCESSFUL**, 7 suites / 92 tests / 0 failures or errors, lint 32 warnings / 0 errors. APK reinstalled successfully and app process observed on SM-S928U. No SMS sent.

## Release packaging and live endpoint spot check (2026-11-12)

- Added optional local signing configuration from ignored root `keystore.properties`; no signing key or passwords were generated or committed.
- `:app:assembleRelease` — **BUILD SUCCESSFUL**, output `app/build/outputs/apk/release/app-release-unsigned.apk`. `apksigner verify --print-certs` reported `DOES NOT VERIFY` / missing `META-INF/MANIFEST.MF`, confirming this is unsigned and not ready for internal distribution. The signing hook was rechecked with no local key file; output remains unsigned.
- Live HTTPS spot check: `GET https://api.almazen.online/up` returned **200** and `/login` returned **200**. This verifies public health/login routes only, not authenticated API, database migrations, queue processing, FCM, or handset delivery.
- Status-notification correctness follow-up: the sync engine now marks the gateway `connected` only after the backend heartbeat and local permission/SIM/cellular readiness checks all pass, preventing a recovery alert immediately before reporting a local failure. `:app:testDebugUnitTest lint :app:assembleDebug` — **BUILD SUCCESSFUL**, 7 suites / 92 tests / 0 failures/errors/skips, lint 32 warnings / 0 errors. Reinstalled on SM-S928U successfully; no SMS sent.

## Firebase Android configuration follow-up (2026-11-12)

- Owner supplied Firebase Android configuration for project `almazen-sms`; the package-matching client is `com.almazen.sms`. Stored at ignored path `app/google-services.json` (not tracked by Git).
- Gradle `processDebugGoogleServices` completed and selected the single matching Android client. `:app:testDebugUnitTest lint :app:assembleDebug` completed **BUILD SUCCESSFUL**. The generated app was installed and launched on SM-S928U.
- A local FCM token is saved in Android app preferences (token value intentionally not logged). After install/start, the persisted heartbeat timestamp advanced and sync state read `connected`, evidencing an API heartbeat. This does **not** prove the server persisted the token or that an FCM push was delivered.
- The gateway was observed enabled on the handset during the last check. No test push was sent, to avoid claiming or causing an unapproved SMS send. The server service-account credential, production migration/notifier deployment, server-side token persistence, actual background push, and SMS-free FCM wake test remain unverified. No SMS was intentionally sent in this check.
- Follow-up after owner supplied the Firebase JSON: `GatewayPush.remember` stores the FCM token and schedules a dedicated heartbeat-only worker. This path must not claim messages or invoke `SmsManager`, including when the gateway is enabled. (The previous implementation enqueued the normal sync worker; that could claim queued work when enabled and was replaced.)
- Phone permission spot-check after reinstall: Android reports `POST_NOTIFICATIONS` denied (`POST_NOTIFICATION: ignore`), while `READ_PHONE_STATE` and `SEND_SMS` runtime permissions are granted. Thus local outage/recovery notifications cannot appear until the owner grants notification permission in the app/system UI. No permission was granted by ADB. The owner stopped emulator-image download to prioritize direct handset installation; no connected instrumentation was run.
- Owner subsequently granted notification permission manually. A fresh read-only `dumpsys package com.almazen.sms` check confirms `POST_NOTIFICATIONS: granted=true`.
- Firebase follow-up (2026-11-12): ignored `app/google-services.json` exists and the installed app has a locally stored FCM token. Device preferences show `gateway_enabled=true`, `last_sync_state=connected`, and a recent `last_successful_heartbeat_at` timestamp. The backend `.env` has an empty `FIREBASE_CREDENTIALS`; a local test fixture is for project `almazen-test`, not the production project. Thus FCM token acquisition and an API heartbeat are observed, but production token persistence, Firebase service-account authentication, FCM delivery, and background wake remain unverified. No push was sent as part of this check.
- Safety correction after confirming the gateway was enabled: token refresh previously enqueued the normal message sync worker, which could claim and send a real queued message. It now enqueues `GatewayTokenRegistrationWorker`, which only sends a heartbeat containing the token and has no claim/report/SMS path. This source change has not been built or tested yet, per the deferred-validation instruction. No push or SMS was initiated intentionally during this follow-up; because the prior build/install occurred while the gateway was enabled, an automatic sync could have run if production work was queued, and that possibility was not checked.

## Admin test-send form (2026-11-12)

- Added an admin-only form on `/admin/messages` to select an active project and enter one E.164 recipient. It requires a user confirmation that the number is theirs or the owner consented, sends a fixed short test body, uses the existing MessageIntake/outbox/quota flow, and is limited to two requests per admin per ten minutes.
- Admin messages and test-send audit views display full number and body; message payloads remain encrypted at rest, and test audit details are encrypted before writing to audit metadata.
- PHP syntax checks (`php -l`) passed for `DashboardController.php`, `AuditLog.php`, `AppServiceProvider.php`, and `routes/web.php`. No feature tests, Laravel deploy, real SMS, or production request was run in this step.
- A deployment archive was prepared at `dist/almazen-sms-api-upload-v3-20261112.zip` with `vendor` and `public/index.php`; it excludes `.env`, storage runtime/log files, test credentials, and the test suite. Remote deployment was blocked because SSH key/password authentication was denied and the available Hostinger browser session was unauthenticated. The supplied BAT is scoped to `C:\laragon\www\shop` and ends by deploying `https://almazen.online/shop/`; it was not run.

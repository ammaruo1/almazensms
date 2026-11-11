# ALMAZEN SMS

ALMAZEN SMS contains an Android SIM gateway app and a separate Laravel backend foundation. The Android application is Kotlin + Jetpack Compose; the backend is PHP 8.3/Laravel with MySQL 8 and Redis Queue.

## Current phase status

- Phase 1 manual SMS proof of concept was accepted by the owner after a confirmed controlled SMS delivery.
- Phase 2 Android reliability was provisionally accepted. The 72-hour run, actual Samsung boot-recovery confirmation, and visible network-indicator check remain pending.
- Phase 3 backend foundation is implemented under [`backend/`](backend/). It accepts and durably records API message requests and supports project/device operations. The API itself does not send SMS; registered Android gateways execute authorized requests.
- Phase 4 Android/API gateway integration is implemented in this workspace. Automated API requests can be enabled explicitly on the phone and use conservative quotas, encrypted local credentials/payloads, durable local command state, and `UNKNOWN` recovery without automatic resend. End-to-end server traffic is not verified: the approved `almazen.online` host is an existing shared web root, the API subdomain is not provisioned, and the existing Laravel/MySQL/Redis runtime is not available there as-is.
- Phase 5 Arabic RTL operations dashboard is implemented in `backend/`: admin session login, users, projects/quotas, devices, API keys, message status search, usage, audit and diagnostics. Phase 6 OTP is not implemented. The dashboard has passed local feature tests and frontend asset build; it is not deployed or live-connected.

## Android build

From PowerShell at the repository root, use Android Studio Panda 3 or compatible tooling, JDK 21 for Gradle, and Android SDK 36.1:

```powershell
.\gradlew.bat build
.\gradlew.bat lint
.\gradlew.bat testDebugUnitTest
.\gradlew.bat connectedDebugAndroidTest
```

The connected tests require an authorized Android device. See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) and [docs/TEST_PLAN.md](docs/TEST_PLAN.md) for verified configuration and phase history. For safe manual sending and truthful result meanings, read [docs/OPERATIONS.md](docs/OPERATIONS.md).

## Backend

See [docs/OPERATIONS.md](docs/OPERATIONS.md) for local setup, dashboard administrator bootstrap and Android gateway setup; [docs/API.md](docs/API.md) and [backend/openapi.yaml](backend/openapi.yaml) for the contract; [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for dashboard boundaries; and [docs/TEST_PLAN.md](docs/TEST_PLAN.md) for actual test results and pending production checks.

Do not commit `.env`, passwords, API keys, device credentials, signing keys, phone numbers, or message content. Production use still requires a TLS-terminating host, trusted proxy configuration, secret management, MySQL/Redis verification, backups, monitoring, quotas and data-retention decisions.

## Project documents

- [Master specification](docs/MASTER_SPECIFICATION.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Decisions](docs/DECISIONS.md)
- [Open questions](docs/OPEN_QUESTIONS.md)
- [Test plan and actual results](docs/TEST_PLAN.md)
- [Operations](docs/OPERATIONS.md)
- [Phase 2 72-hour run log](docs/PHASE2_72H_LOG.md)
- [Phase 3 report](docs/PHASE3_REPORT.md)

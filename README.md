# ALMAZEN SMS

ALMAZEN SMS contains an Android SIM gateway app and a separate Laravel backend. The Android application is Kotlin + Jetpack Compose. The backend is PHP 8.3/Laravel. The accepted Hostinger deployment uses MariaDB and Laravel Database Queue. Redis is not required there.

## Current phase status

- Phase 1 manual SMS proof of concept was accepted by the owner after a confirmed controlled SMS delivery.
- Phase 2 Android reliability was provisionally accepted. On 2026-11-12 the owner deferred the 72-hour run, the real boot check, and the network-outage check until the end of the project.
- Phase 3 backend foundation is implemented under [`backend/`](backend/). It accepts and durably records API message requests and supports project/device operations. The API itself does not send SMS; registered Android gateways execute authorized requests.
- Phase 4 Android/API gateway integration is implemented locally. The phone can opt in to API-originated sends. End-to-end traffic against the live host was not completed in that phase, and the 2026-11-12 work was not published to Hostinger.
- The admin website sets a daily limit and a per-minute limit for each API key, and shows usage, remaining, and the effective quota. The project daily ceiling is an extra guard. OTP exists and stays off until an administrator enables it. SaaS, billing, and commercial multi-customer features are out of the current scope.
- Historical local test results are in [docs/TEST_PLAN.md](docs/TEST_PLAN.md). Tests added on 2026-11-12 were not run. The dashboard is not a live deployed service.

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

Do not commit `.env`, passwords, API keys, device credentials, signing keys, phone numbers, or message content. Production use still needs TLS, trusted proxies, and a private `APP_KEY`. Backups, alerts, and carrier-terms review are out of the current scope. Quotas are configured per API key on the website.

## Project documents

- [Master specification](docs/MASTER_SPECIFICATION.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Decisions](docs/DECISIONS.md)
- [Open questions](docs/OPEN_QUESTIONS.md)
- [Test plan and actual results](docs/TEST_PLAN.md)
- [Operations](docs/OPERATIONS.md)
- [Phase 2 72-hour run log](docs/PHASE2_72H_LOG.md)
- [Phase 3 report](docs/PHASE3_REPORT.md)

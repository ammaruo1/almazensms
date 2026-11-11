# Phase 3 — Backend Foundation Report

**Date:** 2026-11-11  
**Status:** Historical report of the Phase 3 delivery. Production infrastructure verification was pending when this report was written.

**Later note (2026-11-12):** Dashboard, per-API-key quotas, disabled-by-default OTP, and the MariaDB / Laravel Database Queue choice were added locally after this report. The sentences below that say Phase 4 had not started, and that no dashboard or OTP existed, were true for the Phase 3 gate. They are not the current scope. This report did not send SMS, and the 2026-11-12 work did not send SMS either. Do not read the later local work as a live Hostinger deployment.

## Scope and outcome

Added a separate `backend/` Laravel API without recreating the Android project or changing any Android source/build file. The API supports operator accounts and admin access management, project ownership, one-time API keys, device enrollment/revocation, message submission/status, device heartbeat/claim/acknowledgement/report, lifecycle events, rate limits, audit records, and a transactional SQL outbox dispatched through Laravel Redis Queue.

Message intake persists the encrypted payload, initial `queued` lifecycle event, and outbox event in a single database transaction. Idempotency is scoped to a project: same key and same request returns the original message; same key and different payload returns `409`. API keys and device bearer credentials are only returned at creation and stored as SHA-256 hashes. Operator tokens expire after 60 minutes and are pruned daily. Production API requests require HTTPS.

**Phase boundary:** no SMS was sent and no code calls Android `SmsManager`. `sent_to_network` is only a reported network handoff, not recipient delivery; `unknown` is unresolved and is not automatically queued for retry. No Dashboard, OTP, Laravel hosting, or Android/backend integration was added.

## Main files created or changed

### Backend application

- `backend/composer.json`, `backend/composer.lock`, `backend/.env.example`, `backend/bootstrap/app.php`, `backend/config/sanctum.php`, `backend/routes/api.php`, `backend/routes/console.php`, `backend/compose.yaml`, `backend/Dockerfile`.
- `backend/app/Enums/MessageStatus.php`, `backend/app/Services/MessageLifecycle.php`, `backend/app/Services/AuditLogger.php`.
- `backend/app/Models/{User,Project,ApiKey,Device,Message,MessageEvent,OutboxEvent,AuditLog}.php`.
- `backend/app/Http/Controllers/Api/V1/{AuthController,UserAdminController,ProjectController,ApiKeyController,DeviceController,MessageController,DeviceMessageController}.php`.
- `backend/app/Http/Middleware/{AuthenticateApiKey,AuthenticateDevice,RequireAdmin,RequireHttpsInProduction}.php`.
- `backend/app/Console/Commands/{CreateOperator,DispatchOutbox}.php`, `backend/app/Jobs/ProcessOutboxEvent.php`.
- `backend/database/migrations/2026_11_10_220823_create_personal_access_tokens_table.php` and `backend/database/migrations/2026_11_11_00000{1,2,3,4}_*.php` for projects/API keys/devices, messages/events/outbox, user role/status, and audit logs.
- `backend/tests/Feature/GatewayApiTest.php` and `backend/tests/Unit/MessageStatusTest.php`.
- API contract: `backend/openapi.yaml`.

Laravel's normal project scaffold files remain in `backend/`; it is a second subproject alongside the existing Android project, not a replacement for it. Local `backend/.env` is ignored and was not copied into documentation.

### Project documentation

- Updated root `README.md`, `docs/MASTER_SPECIFICATION.md`, `docs/ARCHITECTURE.md`, `docs/DECISIONS.md`, `docs/OPEN_QUESTIONS.md`, `docs/TEST_PLAN.md`, and `docs/OPERATIONS.md`.
- Added `docs/API.md` and this report.
- Confirmed and documented the owner's Phase 2 decision to retain SQLite in `docs/DECISIONS.md` D-012. The 72-hour, true device boot-recovery, and visible network interruption checks remain pending as previously agreed.

## Environment observed

- Portable PHP CLI: **8.3.36 NTS x64**; Composer PHAR: **2.11.1**. Neither PHP 8.3 nor Composer was available on the normal shell PATH initially; both were downloaded for this task outside the project. PHP 8.1 existed separately but does not satisfy this Laravel application's `^8.3` platform requirement.
- Resolved packages: Laravel Framework **13.42.0**, Sanctum **4.4.0**, Predis **3.7.1**, PHPUnit **12.5.43**. Composer declares PHP `^8.3`, `ext-pdo_mysql`, and test `ext-pdo_sqlite` explicitly.
- The Docker CLI was present, and Compose configuration could be rendered. `docker info` exited **1** because the Docker Desktop engine/daemon was not running. MySQL 8.4 and Redis 7 containers therefore could not be started or tested.
- `adb devices -l` showed no attached Android device during this Phase 3 verification. Android was not modified, and no Android device test or SMS send was attempted.
- This directory has no `.git` repository; `git status --short` exited 128. No repository was initialized.

## Commands run and actual results

Initial setup (PowerShell; commands were run from the workspace or `backend/` as indicated):

```powershell
composer create-project laravel/laravel backend '^13.0' --no-interaction --prefer-dist
php artisan install:api
composer require laravel/sanctum:^4.2 --no-interaction
composer require predis/predis:^3.2 --no-interaction
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

The first API scaffold command reported that the shell could not find `composer` because PHP/Composer were installed outside PATH. Composer was then invoked explicitly through the PHP 8.3 executable; Sanctum and its token migration/config were published successfully. No external hosted service was contacted.

Final validation commands (run in `backend/`, PHP executable selected from the temporary PHP 8.3 tool directory):

```powershell
$phpDir="$env:TEMP\almazen-phase3-tools\php83"
$env:PATH="$phpDir;$env:PATH"
& "$phpDir\php.exe" artisan test --compact
& "$phpDir\php.exe" vendor\bin\pint --test
& "$phpDir\php.exe" "$env:TEMP\almazen-phase3-tools\composer.phar" validate --strict
& "$phpDir\php.exe" "$env:TEMP\almazen-phase3-tools\composer.phar" check-platform-reqs --no-dev
$env:DB_CONNECTION="sqlite"; $env:DB_DATABASE=":memory:"
& "$phpDir\php.exe" artisan migrate:fresh --force
& "$phpDir\php.exe" artisan route:list --path=api/v1
& "$phpDir\php.exe" artisan schedule:list
docker compose config --quiet
docker info
```

OpenAPI syntax was checked with `python -c "import yaml; spec=yaml.safe_load(open('backend/openapi.yaml', encoding='utf-8')); print('OpenAPI YAML parse: PASS; version='+spec['openapi']+'; paths='+str(len(spec['paths'])))"`. Compose parsing used temporary non-secret `DB_PASSWORD`/`DB_ROOT_PASSWORD` process environment values only; these values were not saved or printed.

Results:

| Check | Actual result |
| --- | --- |
| `artisan test --compact` | **PASS:** 16 tests, 84 assertions, 0 failures/errors. SQLite in-memory and queue fakes. |
| `vendor/bin/pint --test` | **PASS:** formatting check passed after applying Pint to project PHP source. |
| `composer validate --strict` | **PASS:** `composer.json` valid; lock file synchronized. |
| `composer check-platform-reqs --no-dev` | **PASS:** PHP 8.3.36 and production requirements, including `ext-pdo_mysql`, satisfied. |
| `artisan migrate:fresh` | **PASS:** all 8 migrations applied to in-memory SQLite, including Sanctum tokens, gateway tables, user roles/status, and audit logs. This is not a MySQL driver test. |
| `artisan route:list --path=api/v1` | **PASS:** 19 API routes registered. |
| `artisan schedule:list` | **PASS:** outbox dispatcher every minute; expired Sanctum token cleanup daily. |
| OpenAPI YAML parse | **PASS:** YAML loaded as OpenAPI 3.1 with 17 documented paths. This is a syntax parse, not a full OpenAPI conformance validator. |
| `docker compose config --quiet` | **PASS:** Compose config parsed after correcting Compose interpolation of the health-check PHP variable. |
| `docker info` | **BLOCKED:** exit 1, Docker daemon unavailable; no image build or MySQL/Redis container test was run. |
| `adb devices -l` | No device listed at the time of check; no phone-side test was applicable to this backend-only change. |

Feature tests cover: login and disabled-user rejection; admin-only user changes and self-lockout protection; project/API-key owner scoping; SHA-256-only API key persistence and revocation; encrypted destination/body storage; idempotent replay and payload-conflict rejection; SQL message/event/outbox creation; cross-project status isolation; device heartbeat, claim, acknowledgement and lifecycle reports; attempt count; invalid transitions; unresolved `unknown` not being re-claimed; HTTPS enforcement in production mode; outbox publication/lease processing idempotency and future availability; and user audit rows.

During development, tests identified and corrected: an event UUID not included in its model's fillable fields; missing/short idempotency headers in early tests; repeated test credential fixtures violating unique constraints; and a test guard cache when switching operator tokens in one process. A Docker Compose config check initially warned about PHP's `$r` variable being interpreted as Compose interpolation; it was escaped and the final check emitted no warning. These intermediate failures were fixed before final validation.

## Known limits and risks

- The MySQL locking behavior and Redis queue handoff/recovery have not been verified against running services. SQLite tests do not establish MySQL concurrency safety. Run those checks before deployment.
- The transactional outbox provides durable publication attempts with a SQL lease and recoverable retry. A committed message remains claimable from SQL even if Redis is unavailable. This is not proof of exactly-once SMS and does not initiate sending in this phase.
- No MySQL/Redis credentials, domain, certificate, public host, trusted proxy list, hosting provider, production secret manager, backup/restore, monitoring, customer quotas, or retention/deletion policy were supplied. The Compose API is loopback-bound and intended as a local foundation. Production HTTPS behind a proxy depends on narrowly configured trusted-proxy settings.
- User/device/API-key management is an API foundation; there is no Dashboard. User creation is intentionally CLI-only. Lost one-time API key/device secrets must be revoked and recreated; a credential rotation workflow integrated with the Android client is deferred.
- Android app changes, Phase 4 message polling/reconciliation, live backend connectivity, and real SMS through the backend were not performed. No 24/7 reliability or recipient delivery guarantee is claimed.

## Phase gate

Phase 3's local foundation, automated feature/unit tests, and documented API contract are implemented and verified in the available environment. MySQL 8/Redis runtime validation and production deployment readiness remain explicit blockers for production use, but are not represented as completed. Work stops here. **Phase 4 requires the owner's explicit approval.**

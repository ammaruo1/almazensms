# Open Questions

## Current as of 2026-11-12

These owner decisions close or defer items in the older sections. Where an older bullet conflicts, this section wins. The older text stays as history.

- Hostinger shared hosting is accepted. Use MariaDB through Laravel's `mysql` driver and Laravel Database Queue. MySQL 8 and Redis are not required. The MariaDB version on the host was not verified from this workspace, and nothing was published to the server.
- Quotas are per API key, edited on the admin website. The project daily ceiling remains an extra guard.
- OTP is implemented locally and disabled until an administrator enables it. No OTP SMS was sent.
- SaaS, billing, payments, and a commercial multi-customer product are out of the current scope.
- The 72-hour run, real boot recovery, and network-outage observation are deferred to the end of the project. They are not deleted.
- Backups, alerts, and carrier-terms review are out of the current scope.
- Hostinger cron is not confirmed. Scheduler status stays unconfirmed until `scheduler:heartbeat` records a run.
- Still open: personal-data retention, Android signing and distribution, Google Play SMS-permission eligibility, and whether the host's cron and database limits are sufficient. Production credentials were not used here.

## Phase 1 proof

- Owner confirmed Phase 1 SMS was sent through Yemen Mobile and received by the intended handset; Phase 1 is accepted as complete.
- No recipient number or message content is retained in this workspace.
- Yemen Mobile plan, automated/commercial use terms, throughput limits, and delivery-report behavior remain unknown.

## Before production or later phases

- Define personal-data retention, deletion, and support procedures before storing production destinations/content.
- The continuous 72-hour Phase 2 observation is pending and must be completed before claiming long-run stability.
- The boot receiver is declared, but its execution was not confirmed by logcat after two device reboots on the Samsung. Instrumentation simulated the receiver directly; a persisted in-flight attempt was not carried across a real reboot. Confirm post-unlock broadcast delivery and DB reconciliation on this OEM before relying on it.
- A controlled Internet interruption/reconnection has not been induced. The app monitors network callbacks while foregrounded, but this path still needs device observation.
- The Phase 3 stack is selected as PHP 8.3/Laravel, MySQL 8, and Redis. Hosting/VPS, domain/DNS, TLS termination, and production credentials remain unspecified.
- This workspace is not a Git repository. Git status/history and tracked-file secret auditing are unavailable; no repository was created.

## Detection limitations to keep visible

- SIM presence or a carrier name does not establish SMS service, account balance, or successful transmission.
- A validated Internet connection is independent from cellular SMS service.
- Carrier/device delivery reports may be delayed, unsupported, or absent. The owner confirmed receipt, but no delivery-report callback was recorded for that message.

## Phase 3 backend decisions still needed before deployment

- Choose VPS/cloud host, domain/DNS, public TLS certificate and TLS-terminating proxy. Configure Laravel trusted proxies so production HTTPS enforcement recognizes forwarded HTTPS correctly.
- Supply unique production `APP_KEY`, database credentials, Redis authentication/network policy, and an operator account through the documented secret-management mechanism. Local `.env` is generated and ignored; never copy it into a repository or production without rotating its key.
- Define customer/project quotas, retention/deletion periods for encrypted phone numbers and message bodies, backup encryption/restore testing, monitoring and alerting. These controls are not production-ready in Phase 3.
- Decide how gateway device credentials are provisioned and recovered during Phase 4 integration. Current enrollment reveals a credential once; revocation and re-enrollment are available, but no in-place rotation workflow is integrated with Android yet.
- Select acceptable MySQL 8 and Redis 7 hosting, run migrations and queue/worker recovery tests against those real services, and validate MySQL row-lock behavior before relying on concurrent device claims.
- Configure SMS carrier terms/commercial-use review and conservative project-level quotas before accepting real customer traffic. The API does not transmit messages itself in this phase.

## Phase 4 integration and release decisions

- Select the API hosting architecture. `almazen.online` currently serves the existing store on Hostinger shared hosting; its root redirects to `/shop/`, `/shop/` redirects to the existing external storefront, and the `api.almazen.online` DNS record/docroot was not present during the read-only preflight. The account exposes PHP 8.3 CLI, but no Docker or local Redis service; the current backend requires MySQL 8 and Redis workers. Recommended: provision a VPS for the API on `api.almazen.online` and leave the existing shop hosting untouched. Alternative: explicitly approve adapting the backend to this shared host and its queue/DB limitations.
- If using a VPS, configure its DNS/TLS and provide deployment access using a secure credential channel. Existing SSH access is for the shared Hostinger account and does not prove VPS/API deployment readiness.
- Decide app distribution. The current manifest declares `SEND_SMS`; Google Play's current policy restricts SMS permissions to apps registered as the default SMS or Assistant handler. This app is not a default SMS handler, so it is not ready for Google Play submission as-is. Private/internal APK distribution can be evaluated separately.
- Provide a production Android signing keystore through a secure mechanism. The generated release APK is unsigned and cannot be uploaded as an installable release without signing.
- Run MySQL/Redis-backed tests and an HTTPS heartbeat/claim/ack/report check on the selected host, then complete the 72-hour operation run and real reboot/network-interruption observations. No test SMS was sent during Phase 4 validation.

## Phase 5 dashboard and remaining readiness decisions

- The local admin dashboard implementation and security tests are complete. Choose/provision a separate VPS or compatible API host and an API subdomain/TLS endpoint; do not use the current shop deployment script or public root.
- Provide production Android signing material through a secure local mechanism, then choose internal distribution packaging. Current release APK remains unsigned.
- Configure and verify actual MySQL 8/Redis Queue/Scheduler/worker runtime, TLS proxy trust, backups/restore, monitoring, secrets, retention, and real Android/backend lifecycle before live operation.
- Complete the pending 72-hour gateway observation and physical boot/recovery evidence. No project can guarantee uninterrupted 24/7 work under Android/OEM/carrier constraints.
- Phase 6 OTP was not part of the Phase 5 dashboard delivery. On 2026-11-12 OTP was added locally and remains disabled until an administrator enables it.

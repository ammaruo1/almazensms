# ALMAZEN SMS

## Master Engineering Specification & Codex Execution Contract

**Version:** 2.0\
**Date:** 2026-11-10\
**Status:** Authoritative project handoff document

------------------------------------------------------------------------

## 1. Mission

Build **ALMAZEN SMS**, a reliable Android SIM-based SMS gateway that
initially sends messages through a dedicated Samsung Android phone
containing a **Yemen Mobile** SIM. The phone remains connected to power
and the internet. A secure backend accepts API requests, queues
messages, assigns them to registered gateway devices, receives
delivery-related events, and exposes operational controls through a web
dashboard.

The first release is for controlled use by the project owner. The
architecture should allow a future commercial multi-customer SaaS
service, subject to carrier terms, applicable law, Android platform
rules, and measured capacity.

This is an engineering project, not a mockup. Prioritize correctness,
recoverability, observability, security, and honest status reporting
over feature count or visual polish.

## 2. Non-negotiable instructions to Codex

1.  **Inspect before changing.** Inspect the existing repository,
    Android Studio project, Gradle files, package/application IDs, SDK
    versions, Git status, available tests, and build environment before
    editing.
2.  Do not assume the project is empty or overwrite existing work.
    Preserve user files and existing working configuration.
3.  Create and maintain `docs/` documentation. This file is the source
    of truth; if code constraints require a change, document the reason
    and impact.
4.  Work in small, verifiable steps. After each meaningful change, run
    the relevant build, lint, and tests. Fix regressions before
    continuing.
5.  Never claim a build, test, device connection, SMS send, or delivery
    succeeded unless it was actually verified.
6.  Do not fabricate credentials, API endpoints, carrier capabilities,
    phone model, Android version, SIM status, or test results.
7.  Do not use Root, hidden APIs, Accessibility-based automation, device
    exploits, or methods that evade Android permission or
    background-execution restrictions.
8.  Do not request permissions that are not needed. Explain the purpose
    of each sensitive permission.
9.  Never send messages to arbitrary recipients during tests. Use only
    owner-controlled numbers or recipients who have explicitly
    consented.
10. Do not automatically resend messages with an ambiguous outcome. A
    retry can create duplicate SMS.
11. Keep secrets out of source control, logs, screenshots, test
    fixtures, and documentation. Provide `.env.example` or equivalent
    placeholders only.
12. If a required fact is unknown, record it as an open decision and
    continue with safe work that does not depend on it. Ask the owner
    only when genuinely blocked.
13. Do not move to a later phase while the current phase's exit criteria
    are unmet.
14. Do not replace the selected stack without presenting a concrete
    technical reason and asking for approval.

## 3. Current known project facts and unknowns

### Known

-   Product name: **ALMAZEN SMS**
-   Intended Android application ID/package: `com.almazen.sms` (inspect
    the actual project; do not silently rename if it differs).
-   Android app: Kotlin + Jetpack Compose.
-   Initial SIM network: Yemen Mobile.
-   Dedicated Samsung Android phone, kept connected to charger and
    internet.
-   Future inbound numbers may be available on Yemen Mobile, YOU, and
    Sabafon, but inbound SMS is not part of the first outbound-SMS proof
    of concept.
-   The user has Android Studio installed on Windows.
-   Reliability is the primary success criterion.
-   Future commercial/SaaS expansion is desired, but not at the expense
    of validating the SIM gateway first.

### Unknown --- do not guess

-   Exact Samsung model.
-   Android OS version and security patch.
-   SIM plan, SMS balance, carrier restrictions, permitted throughput,
    and whether commercial/automated use is allowed.
-   Whether the SIM supports delivery reports for the target networks.
-   Backend hosting/VPS details, domain/DNS status, credentials, and
    deployment environment.
-   Whether a physical Android device is currently connected and
    authorized for USB debugging.

Record these in `docs/OPEN_QUESTIONS.md`. Continue Phase 0 without them.

## 4. Target architecture

### 4.1 Android SIM Gateway

-   Kotlin, Jetpack Compose, Android SDK.
-   Android `SmsManager` for SMS transmission.
-   Explicit `PendingIntent` callbacks for sent and, where supported,
    delivery reports.
-   Room database for durable local queue and event history.
-   A carefully designed execution mechanism compatible with the actual
    target Android version. Evaluate foreground-service eligibility and
    current Android restrictions; use WorkManager for
    deferrable/recovery work, not as a promise of immediate execution.
-   HTTPS outbound connection from the phone to backend. No inbound port
    forwarding or fixed public IP is required.
-   Device registration, authentication, heartbeat, command
    polling/long-polling or another justified outbound protocol, and
    acknowledgement/event upload.
-   Android Keystore-backed protection for device credentials where
    feasible.
-   Clear UI showing device state, SIM/network state when accessible,
    queue counts, last heartbeat, and recent send outcomes.

### 4.2 Backend

Preferred initial stack: - Laravel on a currently supported PHP 8.x
release (the owner previously considered PHP 8.3; verify framework and
hosting compatibility before pinning). - MySQL 8-compatible database. -
Redis and Laravel queue workers where supported by the deployment
environment. - REST API over HTTPS. - Transactional persistence and a
durable queue/outbox design.

### 4.3 Dashboard

A web dashboard is a later phase. It should eventually provide: - Device
registration and status. - API projects/keys and revocation. - Message
submission, status, and search. - Usage and quota controls. - OTP
configuration and audit history. - Queue health, failure visibility, and
operational alerts.

### 4.4 Provider abstraction

Design a provider interface so a compliant external SMS provider can be
added later. Do not implement or activate external-provider fallback in
the first Android proof of concept.

## 5. Message lifecycle and reliability semantics

Use explicit, documented states. At minimum distinguish: - `queued`:
persisted by backend and awaiting dispatch. - `assigned`: assigned to a
gateway device. - `accepted_by_device`: device durably accepted the
command. - `sending`: Android send operation initiated. -
`sent_to_network`: Android sent callback indicates the operation was
handed to the network successfully. - `send_failed`: Android reports a
send failure. - `delivery_reported`: a supported carrier/device delivery
report was received. - `delivery_failed`: an explicit delivery failure
report was received. - `unknown`: outcome cannot safely be determined.

Important: - `sent_to_network` is **not** proof that the recipient
received the SMS. - A delivery report may not be supported or returned
by the carrier. - Never convert missing delivery reports into success. -
Never promise exactly-once SMS delivery. Network transmission and
backend acknowledgement cannot be made atomically. - Use client request
idempotency keys to prevent duplicate API submissions. - Use stable
message IDs and device-side deduplication to reduce duplicate
execution. - If a phone sends an SMS but loses connectivity before
reporting the result, reconcile as `unknown` unless reliable evidence
resolves it. Do not resend `unknown` automatically. - Any manual retry
of an unknown message must warn the operator about duplicate risk and
create a new attempt/audit record.

## 6. Android application requirements

### 6.1 First screens

1.  Setup/registration screen.
2.  Gateway status screen.
3.  Recent messages and their precise statuses.
4.  Diagnostics screen with permission, SIM, network,
    battery-optimization, and backend-connectivity checks.
5.  Settings for server URL and device identity, with secrets masked.

### 6.2 SMS permission and privacy

-   Request `SEND_SMS` only when the feature is about to be used and
    explain why.
-   Do not request `READ_SMS`, `RECEIVE_SMS`, contacts, call logs, or
    location unless a separately approved feature demonstrates a genuine
    need.
-   Do not silently read the user's inbox.
-   Display recipient and message content clearly before any manual test
    send.
-   Redact phone numbers and message bodies from routine logs; never log
    OTP values.

### 6.3 Sending engine

Build a single well-defined sending component. It must: - Validate
destination format and message content. - Check permission and
subscription/SIM availability. - Handle the appropriate SIM/subscription
selection where the device supports multiple SIMs. - Generate unique
attempt IDs. - Persist the attempt before initiating the platform
send. - Register and handle sent/delivery callbacks safely. - Handle
callback timeouts and process restarts without inventing success. -
Handle multipart messages and record segment count where possible. -
Prevent concurrent sends from racing or reordering the same message. -
Apply configurable rate limits only after actual carrier/device
testing. - Return typed outcomes, not ambiguous booleans.

Do not assume all Samsung firmware versions expose identical behavior.
Test on the actual target phone.

### 6.4 Background execution

Android background execution rules change across OS releases and target
SDKs. Codex must: - Check current official Android documentation for the
project's target SDK and service type requirements. - Use a visible
foreground service only where legally and technically appropriate, with
a truthful persistent notification. - Use WorkManager for deferred work,
cleanup, and recovery tasks. - Treat FCM as an optimization/notification
mechanism, not a guaranteed always-on transport. - Detect Doze, battery
optimization, reboot, app force-stop, revoked permission, no SIM, no
service, airplane mode, and loss of internet. - Provide clear recovery
instructions instead of promising uninterrupted 24/7 operation. - Never
instruct users to disable essential security protections.

## 7. Backend API and security requirements

Design versioned endpoints, for example `/api/v1/`, with a documented
OpenAPI specification.

Expected logical operations: - Device enrollment and credential
rotation. - Device heartbeat and capability/status report. - Fetch/claim
next message. - Acknowledge durable receipt. - Submit send attempt
result and delivery event. - Submit SMS request using project API key. -
Query message status. - Revoke device or API key.

Security: - HTTPS only. - Store API key hashes on the server; show raw
key only once at creation. - Use device-specific credentials with
rotation/revocation. - Apply authentication, authorization, rate limits,
request size limits, and input validation. - Protect against replay and
duplicate requests. - Use Laravel validation and policies; never trust
device-supplied customer/project IDs without authorization checks. -
Keep audit logs for sensitive actions. - Store OTP codes as secure
hashes, never plaintext. - Use cryptographically secure random
generation for OTPs. - Enforce expiry, attempt limits, resend cooldowns,
per-number and per-project rate limits, and abuse controls. - Do not
expose OTP values in dashboard logs or application logs. - Use
transactional outbox or an equivalent durable dispatch pattern so a
committed message cannot silently disappear between database persistence
and queue dispatch.

## 8. Data model --- conceptual starting point

Codex should refine the schema before migration creation. Expected
entities: - `users` / dashboard administrators (when dashboard phase
begins). - `projects` - `api_keys` (hashed secrets, scopes, status,
last-used timestamp) - `gateway_devices` (status, platform metadata,
credential reference, last heartbeat) - `messages` (project,
destination, content or protected payload, state, idempotency key,
timestamps) - `message_attempts` (device, attempt ID, result, error
code, timestamps) - `message_events` (append-only lifecycle events) -
`otp_challenges` (destination, code hash, purpose, expiry, attempts,
status) - `usage_records` / quota counters - `audit_logs`

Use indexes and unique constraints intentionally. Define retention,
deletion, and personal-data handling policies before production.

## 9. Operational monitoring

Monitor and expose: - Last heartbeat and device online/offline/stale
state. - SIM/network availability where detectable. - Backend
reachability. - Queue depth and oldest queued message age. - Send
success/failure/unknown rates. - Delivery-report availability rate. -
Average and percentile processing times. - Device app version and
Android version. - Storage pressure and repeated crashes.

A device being online does not mean the carrier can send SMS. Display
these as separate signals.

## 10. Testing and acceptance plan

### 10.1 Safety

Use only owner-controlled or explicitly consenting test recipients.
Start with a small number of messages. Never perform bulk unsolicited
messaging.

### 10.2 Proof-of-concept test sequence

1.  Verify build and install on the actual Samsung phone.
2.  Verify permission grant and SIM detection.
3.  Manually send 10 test SMS to controlled numbers.
4.  Send 30 controlled SMS through the app's code path, with
    conservative pacing.
5.  Test recipients on Yemen Mobile, YOU, and Sabafon if available and
    authorized.
6.  Test Arabic and English content.
7.  Test long/multipart SMS and record segment behavior.
8.  Run a 72-hour stability observation.
9.  Test three internet interruptions and two device reboots.
10. Test revoked permission, no SIM, no signal, airplane mode, low
    battery, and app process death.
11. Only after the above, run a limited 100-message distributed test
    with explicit operator oversight and carrier-compliant pacing.

### 10.3 Initial acceptance targets

These are test targets, not guarantees: - Every attempt has a durable
record and a truthful terminal or unresolved state. - Zero unintended
duplicate sends in the controlled test. - No silent loss of
backend-accepted messages. - Recovery after network interruption and
reboot is observable. - At least 98% recipient receipt in the controlled
100-message test **if carrier conditions permit**, with failures and
delivery-report limitations reported transparently. - Measure timing
distributions; an initial goal is 95% within 2 minutes and 98% within 5
minutes, but do not classify carrier delay as software success. -
72-hour run without an unhandled failure; all interruptions must be
detected and surfaced.

Do not manipulate results to meet targets. Report sample size, network,
device, time window, and limitations.

## 11. Development phases and mandatory gates

### Phase 0 --- Repository and environment foundation

-   Inspect current Android Studio project and Git status.
-   Confirm package/application ID, Kotlin/Compose versions, Gradle
    wrapper, compile SDK, target SDK, min SDK, JDK, and Android Studio
    compatibility.
-   Build the untouched project.
-   Create `docs/ARCHITECTURE.md`, `docs/OPEN_QUESTIONS.md`,
    `docs/DECISIONS.md`, and this specification in the repository.
-   Add a safe `.gitignore`; inspect for accidentally committed secrets.
-   Establish build/test commands and record baseline results. **Exit
    gate:** clean baseline build, documented environment, no destructive
    changes. Stop and report.

### Phase 1 --- SIM SMS proof of concept

-   Implement a minimal screen and isolated SMS sending engine.
-   Request SEND_SMS permission with explanation.
-   Send a single manually initiated test SMS to an owner-controlled
    number.
-   Capture sent callback accurately; delivery report separately if
    available.
-   Persist attempt and callback outcome locally. **Exit gate:**
    demonstrated on the real Samsung phone; attach logs with private
    data redacted. Stop and report.

### Phase 2 --- Android reliability

-   Room persistence, queue state machine, deduplication, controlled
    pacing, restart recovery, diagnostics, and compliant background
    execution.
-   Test all failure scenarios listed above. **Exit gate:** reliability
    report with measured evidence. Stop and report.

### Phase 3 --- Backend foundation

-   Laravel API, database migrations, authentication, device enrollment,
    heartbeat, message persistence, transactional outbox/queue, API
    documentation, tests. **Exit gate:** automated tests and documented
    API contract. Stop and report.

### Phase 4 --- Android/backend integration

-   Secure device registration, message claim/acknowledgement, result
    upload, reconnect/reconciliation behavior. **Exit gate:** end-to-end
    controlled SMS with traceable lifecycle. Stop and report.

### Phase 5 --- Dashboard

-   Admin authentication, device management, messages, search, API keys,
    usage, audit, and diagnostics. **Exit gate:** role and security
    tests. Stop and report.

### Phase 6 --- OTP service

-   Secure generation, hashed storage, expiry, attempts, cooldowns, rate
    limits, verification endpoint, and audit. **Exit gate:**
    abuse/security tests and end-to-end OTP validation. Stop and report.

### Phase 7 --- Production hardening

-   Backups, monitoring, alerting, retention, deployment, secrets
    management, incident procedures, load testing, and carrier
    compliance review. **Exit gate:** production readiness checklist
    signed off by owner.

### Phase 8 --- SaaS expansion (not part of initial MVP)

-   Multi-tenant isolation, billing, quotas, additional
    gateways/providers, customer onboarding, and commercial operations.
    Do not start without explicit approval.

## 12. Required repository documentation

Maintain: - `README.md` --- setup and developer quick start. -
`docs/MASTER_SPECIFICATION.md` --- this document. -
`docs/ARCHITECTURE.md` --- components, boundaries, data flow,
diagrams. - `docs/OPEN_QUESTIONS.md` --- unknowns and owner decisions. -
`docs/DECISIONS.md` --- architecture decision records. -
`docs/TEST_PLAN.md` --- test cases and actual results. -
`docs/OPERATIONS.md` --- setup, troubleshooting, recovery, deployment. -
`docs/API.md` or OpenAPI spec --- backend API contract.

## 13. Required Codex response format at each phase gate

At the end of each phase, report: 1. What you inspected. 2. What you
changed (exact file paths). 3. Commands actually run. 4. Build/test/lint
results (include failures). 5. Device tests actually performed, if any.
6. Security and reliability concerns. 7. Open questions requiring owner
input. 8. Whether the phase exit criteria passed. 9. The exact next
phase proposed --- do not begin it until the owner approves.

## 14. Immediate instruction --- start now

**Execute Phase 0 only.**

First inspect the existing workspace and current project. Do not
recreate the Android project. Do not implement SMS sending yet. Do not
create a backend yet. Do not add speculative dependencies. Validate the
current project, document the actual environment and architecture,
create the documentation files, run the baseline build, and provide the
Phase 0 report.

If the build fails, diagnose and fix only Phase 0/environment issues. If
a fix would require changing an architectural choice or SDK
compatibility decision, explain the trade-off and ask before applying
it.

The owner will review the Phase 0 report and explicitly authorize Phase
1.

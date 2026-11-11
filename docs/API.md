# ALMAZEN SMS API

The versioned Phase 3 API contract is [`backend/openapi.yaml`](../backend/openapi.yaml) (OpenAPI 3.1). The base path is `/api/v1`; the example host in the spec is deliberately invalid and must be replaced with the deployment's HTTPS domain.

## Authentication

- Operator endpoints use Sanctum `Authorization: Bearer …` tokens. Operators are created from the server CLI; there is no public user-registration route.
- Operator tokens expire after 60 minutes. Admin operators can list accounts and change roles/status; self-disable/self-demotion is rejected. User creation remains a server-side CLI action.
- Project message requests use `X-API-Key`. Keys are returned once on creation and stored as SHA-256 hashes.
- Device endpoints use `Authorization: Bearer azm_device_…`. Device credentials are also returned once and stored only as hashes. Revoking a device invalidates the credential.
- All production API routes reject plain HTTP. Configure TLS termination and Laravel trusted proxies before production traffic.

## Message submission

`POST /api/v1/messages` requires a valid project key, an E.164-shaped `to` value, a `body` up to 5000 characters, and `Idempotency-Key` (8–128 characters). It is rate limited to 10 requests per minute per key and a configurable project daily limit (default 20; maximum 1000 per UTC day). The daily quota reservation, message row, initial status event, and transactional outbox row are committed together. A replay with the same key and payload returns the existing message without consuming quota; reusing the key for a different payload returns `409 Conflict`.

The API encrypts the full destination and body at rest with Laravel `APP_KEY`. The `queued` response means persisted and pending, not sent. Device status `sent_to_network` is not evidence the recipient received the SMS. `unknown` is not automatically retried.

## Android gateway integration

The API provides message submission/status, operator/project/API-key/device management, device heartbeat, claim, acknowledgement and status-report operations. The Android app can poll/claim requests after an operator explicitly enables the gateway on the handset. A claim is replayable to the same device while it remains `assigned`, allowing recovery if the claim response is lost; Android durably encrypts and stores the command before acknowledging it. Delivery to Android remains subject to WorkManager and Android scheduling and is not immediate or continuous by guarantee.

The project quota defaults to 20 accepted messages per UTC day and can be configured up to 1000; API intake is limited to 10 requests/minute per project key. Android sends sequentially with a minimum one-minute gap. These are cost-control guardrails, not carrier throughput or delivery guarantees. A device-reported `sent_to_network` state is not proof of receipt, and `unknown` is never automatically resent. Treat the 20/day default as the starting limit until Yemen Mobile commercial terms and measured stability are confirmed.

There is no Dashboard or OTP service. Review [docs/PHASE3_REPORT.md](PHASE3_REPORT.md) for backend tests and [docs/PHASE4_REPORT.md](PHASE4_REPORT.md) for Android integration validation and deployment limitations. Production MySQL/Redis, public HTTPS and end-to-end delivery remain unverified.

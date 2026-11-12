# ALMAZEN SMS API

The versioned Phase 3 API contract is [`backend/openapi.yaml`](../backend/openapi.yaml) (OpenAPI 3.1). The base path is `/api/v1`; the example host in the spec is deliberately invalid and must be replaced with the deployment's HTTPS domain.

## Authentication

- Operator endpoints use Sanctum `Authorization: Bearer …` tokens. Operators are created from the server CLI; there is no public user-registration route.
- Operator tokens expire after 60 minutes. Admin operators can list accounts and change roles/status; self-disable/self-demotion is rejected. User creation remains a server-side CLI action.
- Project message requests use `X-API-Key`. Keys are returned once on creation and stored as SHA-256 hashes.
- Device endpoints use `Authorization: Bearer azm_device_…`. Device credentials are also returned once and stored only as hashes. Revoking a device invalidates the credential.
- All production API routes reject plain HTTP. Configure TLS termination and Laravel trusted proxies before production traffic.

## Message submission

`POST /api/v1/messages` requires a valid project key, an E.164-shaped `to` value, a `body` up to 5000 characters, and `Idempotency-Key` (8–128 characters). Each API key has a daily limit and a rolling per-minute limit, both edited on the admin website. New keys default to 20 messages per UTC day and 10 newly accepted requests per minute. The project `daily_message_limit` remains an extra ceiling shared by every key on that project. Effective daily remaining is the smaller of the key's remaining daily messages and the project's remaining ceiling. The per-minute counter includes only newly accepted messages for that key. Quota reservation, the message row, the initial status event, and the transactional outbox row commit together. A replay with the same key and payload returns the existing message without consuming quota; reusing the key for a different payload returns `409 Conflict`.

The API encrypts the full destination and body at rest with Laravel `APP_KEY`. The `queued` response means persisted and pending, not sent. Device status `sent_to_network` is not evidence the recipient received the SMS. `unknown` is not automatically retried.

## Android gateway integration

The API provides message submission/status, operator/project/API-key/device management, device heartbeat, claim, acknowledgement and status-report operations. The Android app can poll/claim requests after an operator explicitly enables the gateway on the handset. A claim is replayable to the same device while it remains `assigned`, allowing recovery if the claim response is lost; Android durably encrypts and stores the command before acknowledging it. Delivery to Android remains subject to WorkManager and Android scheduling and is not immediate or continuous by guarantee.

Android sends remote messages sequentially with a local minimum one-minute gap. That gap is device pacing, not the server quota. The server enforces the API key quota. A device-reported `sent_to_network` state is not proof of receipt, and `unknown` is never automatically resent. Carrier-terms review is out of the current scope.

## OTP

`POST /api/v1/otp/request`, `POST /api/v1/otp/verify`, and `POST /api/v1/otp/{challenge}/cancel` use the project API key. OTP is disabled until an administrator enables it for that project. A disabled request returns 403 and stores nothing. A request generates a 6-digit code with `random_int`, stores only an HMAC-SHA256 of the code, and sends the SMS through the same message intake as `POST /api/v1/messages`, including quota, encryption, and the outbox. The challenge row is written in that same transaction and must reference the accepted message. The OTP responses do not contain the code or the full phone number. Verify enforces expiry, the attempt limit, resend cooldown, and the same project and phone. Verify does not consume message quota.

A resend after the cooldown superseded the previous pending challenge, recalls the previous message while it is still `queued`, `assigned`, or `accepted_by_device`, and suppresses its pending outbox row. Expired challenges recalled the same way on verify or on claim scan. `POST /api/v1/otp/{challenge}/cancel` recalls the linked message with the same rules and returns `message_recalled`. Once send authorization atomically reserves the message as `sending`, cancel still closes the challenge but returns `409` with `message_recalled: false`. The handset cannot guarantee recall after that boundary; the SMS may already have been submitted, even if the server has not yet received the network handoff report.

Device `GET /api/v1/devices/messages/claim` skips unsendable OTP rows and sets `send_authorization_required: true` on OTP claims. Before calling `SmsManager`, Android must call `POST /api/v1/devices/messages/{messageId}/send-authorization`. For a valid OTP, that endpoint locks the challenge and message and atomically reserves the message as `sending` before returning `send: true`; cancellation after that point returns `message_recalled: false` and cannot promise recall. If the authorization response is lost, a repeated authorization is denied as `already_started`; Android records `UNKNOWN` and does not send again. If the server cannot be reached, Android must not start sending an OTP SMS.

The selected production queue is Laravel Database Queue on MariaDB. Redis is not required. Review [docs/PHASE3_REPORT.md](PHASE3_REPORT.md) and [docs/PHASE4_REPORT.md](PHASE4_REPORT.md) as historical reports. Live Hostinger deployment and end-to-end delivery remain unverified. The 2026-11-12 tests were added and not run.

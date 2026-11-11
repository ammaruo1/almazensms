# Phase 2 — 72-hour stability observation

## Protocol

- Test duration: 72 continuous hours with the Phase 2 debug build on Samsung `SM-S928U` / Android 17.
- Keep the handset on its normal charger and normal Internet connection. Do not disable security or battery protections.
- Do not send SMS during this run unless the owner separately approves each manual test. Background SMS is not implemented.
- Record app version/build hash, start/end timestamps, device/OS, charger/battery state, network changes, app stops/crashes, reboots, permission changes, and attempt state before/after interruptions. Redact phone numbers and message text.
- At 24-hour intervals, inspect local attempt history and diagnostics. Do not clear app data or reinstall during the run.
- For a reboot observation, note the time, allow Android to complete boot/unlock, then verify outstanding sends appear as `UNKNOWN` and no resend occurs. Android may defer/skip boot handling for force-stopped apps; launching the app also runs stale recovery.
- Record every observed event, including “none”; absence of events is meaningful only with the observation time and device state.

## Run record

Status: **RUNNING — follow-up observations pending**. Baseline began after final APK install and reboot check.

Device: Samsung `SM-S928U`, Android 17/API 37 (observed previously; re-confirm at start).

| Local time (Asia/Riyadh) | Elapsed | Observation / action | App/device result | Evidence (redacted) |
| --- | ---: | --- | --- | --- |
| 2026-11-11 00:41 | 0 h | Installed final debug APK after reboot and explicitly launched the app | Android 17/API 37; app foreground; battery 93%, USB-powered; Wi-Fi/VPN previously observed; no SMS sent during this run | ADB install/start, `dumpsys battery`, `dumpsys activity`, `getprop` |
| 2026-11-11 00:42 | <1 h | Disabled Wi-Fi and mobile data briefly, then restored both to their previous enabled state | ADB observed no active default network during interruption; active default network returned after restoration. No SMS sent; local DB unchanged. UI indication not visually checked because lock screen was showing. | `svc data`, `svc wifi`, `dumpsys connectivity`, `settings get global` |
| 2026-11-12 00:41 | 24 h | Check app, battery, network, history | Pending | Pending |
| 2026-11-13 00:41 | 48 h | Check app, battery, network, history | Pending | Pending |
| 2026-11-14 00:41 | 72 h | Final check and stop | Pending | Pending |

At baseline, ADB properties reported Yemen Mobile, SIM `LOADED,NOT_READY`, network type `LTE,Unknown`, airplane mode off. Device reboot completed; after reboot the app did not auto-launch and was opened manually. The final APK was installed and launched at the recorded start. No crash or SMS send has been observed during the run so far. No network interruption or permission change has been induced/recorded.

Conclusion: **No 72-hour reliability conclusion is available until all checkpoints are observed and recorded.**

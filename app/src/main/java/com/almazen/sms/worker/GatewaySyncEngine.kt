package com.almazen.sms.worker

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.content.ContextCompat
import com.almazen.sms.data.GatewayCredentialStore
import com.almazen.sms.data.GatewaySettings
import com.almazen.sms.data.RemoteCommandRecord
import com.almazen.sms.data.RemoteCommandStatus
import com.almazen.sms.data.SmsAttemptStore
import com.almazen.sms.device.GatewayDiagnostics
import com.almazen.sms.device.GatewaySnapshot
import com.almazen.sms.device.InternetStatus
import com.almazen.sms.device.MobileServiceStatus
import com.almazen.sms.device.SimCardStatus
import com.almazen.sms.device.SimStatus
import com.almazen.sms.domain.OtpSendDecision
import com.almazen.sms.domain.OtpSendGate
import com.almazen.sms.domain.SmsInput
import com.almazen.sms.domain.SmsInputValidation
import com.almazen.sms.domain.SmsInputValidator
import com.almazen.sms.network.GatewayApiClient
import com.almazen.sms.network.GatewayApiException
import com.almazen.sms.platform.AndroidSmsGateway
import com.almazen.sms.platform.SmsSendCoordinator
import com.almazen.sms.platform.SmsSendRequest
import com.almazen.sms.platform.SmsSendResult
import java.io.IOException

enum class GatewaySyncRunOutcome {
    SUCCESS,
    RETRY,
    FAILURE,
}

class GatewaySyncEngine(private val appContext: Context) {
    private val credentials = GatewayCredentialStore(appContext)

    /** Heartbeats the backend with the FCM token without touching the local queue or SMS sender. */
    fun registerPushToken(): GatewaySyncRunOutcome {
        val settings = runCatching { credentials.load() }.getOrNull() ?: return GatewaySyncRunOutcome.SUCCESS
        if (credentials.fcmToken().isNullOrBlank()) return GatewaySyncRunOutcome.SUCCESS
        return try {
            val snapshot = GatewayDiagnostics(appContext).snapshot()
            GatewayApiClient(settings).heartbeat(
                appVersion = appContext.packageManager.getPackageInfo(appContext.packageName, 0).versionName ?: "unknown",
                androidVersion = Build.VERSION.RELEASE ?: "unknown",
                apiLevel = Build.VERSION.SDK_INT,
                networkStatus = snapshot.internet.apiValue(),
                simStatus = snapshot.selectedSim(settings)?.apiValue() ?: "unknown",
                fcmToken = credentials.fcmToken(),
            )
            GatewaySyncRunOutcome.SUCCESS
        } catch (exception: GatewayApiException) {
            if (exception.httpStatus == 401 || exception.httpStatus == 403) {
                GatewaySyncRunOutcome.FAILURE
            } else {
                GatewaySyncRunOutcome.RETRY
            }
        } catch (_: IOException) {
            GatewaySyncRunOutcome.RETRY
        } catch (_: SecurityException) {
            GatewaySyncRunOutcome.FAILURE
        } catch (_: RuntimeException) {
            GatewaySyncRunOutcome.RETRY
        }
    }

    fun runOnce(): GatewaySyncRunOutcome {
        val settings = runCatching { credentials.load() }.getOrNull() ?: return GatewaySyncRunOutcome.SUCCESS
        if (settings.enabled) {
            com.almazen.sms.platform.GatewaySyncMonitor.startIfNeeded(appContext)
        }
        val api = GatewayApiClient(settings)

        return try {
            SmsAttemptStore(appContext).use { store ->
                store.markStaleAttemptsUnknown(System.currentTimeMillis() - CALLBACK_TIMEOUT_MS)
                val snapshot = GatewayDiagnostics(appContext).snapshot()
                api.heartbeat(
                    appVersion = appContext.packageManager.getPackageInfo(appContext.packageName, 0).versionName ?: "unknown",
                    androidVersion = Build.VERSION.RELEASE ?: "unknown",
                    apiLevel = Build.VERSION.SDK_INT,
                    networkStatus = snapshot.internet.apiValue(),
                    simStatus = snapshot.selectedSim(settings)?.apiValue() ?: "unknown",
                    fcmToken = credentials.fcmToken(),
                )
                uploadPendingReports(api, store)
                if (!settings.enabled) {
                    recordSyncState("gateway_paused")
                    return GatewaySyncRunOutcome.SUCCESS
                }
                if (!isReadyToSend(settings, snapshot)) {
                    recordBlockedSyncState(credentials, settings, snapshot)
                    return GatewaySyncRunOutcome.SUCCESS
                }
                // Report recovery only after backend reachability and all local send prerequisites are healthy.
                recordSyncState("connected")

                var command = store.pendingRemoteCommands()
                    .firstOrNull { it.status == RemoteCommandStatus.RECEIVED || it.status == RemoteCommandStatus.ACCEPTED_BY_DEVICE }

                if (command == null) {
                    if (credentials.load()?.enabled == false) return GatewaySyncRunOutcome.SUCCESS
                    val remote = api.claim() ?: return GatewaySyncRunOutcome.SUCCESS
                    store.saveRemoteCommand(
                        remote.message.id,
                        credentials.encryptPayload(remote.message.destination),
                        credentials.encryptPayload(remote.message.body),
                        sendAuthorizationRequired = remote.sendAuthorizationRequired,
                    )
                    command = store.remoteCommand(remote.message.id) ?: return GatewaySyncRunOutcome.RETRY
                }

                sendCommand(command, api, store)
            }
        } catch (exception: GatewayApiException) {
            if (exception.httpStatus == 401 || exception.httpStatus == 403) {
                credentials.setEnabled(false)
                recordSyncState("credential_rejected")
                GatewayWorkScheduler.disable(appContext)
                GatewaySyncRunOutcome.FAILURE
            } else {
                recordSyncState("backend_error_${exception.httpStatus}")
                GatewaySyncRunOutcome.RETRY
            }
        } catch (_: IOException) {
            recordSyncState("network_error")
            GatewaySyncRunOutcome.RETRY
        } catch (_: SecurityException) {
            recordSyncState("permission_unavailable")
            GatewaySyncRunOutcome.SUCCESS
        } catch (_: RuntimeException) {
            recordSyncState("local_error")
            GatewaySyncRunOutcome.RETRY
        }
    }

    private fun sendCommand(
        command: RemoteCommandRecord,
        api: GatewayApiClient,
        store: SmsAttemptStore,
    ): GatewaySyncRunOutcome {
        if (command.status == RemoteCommandStatus.RECEIVED) {
            if (credentials.load()?.enabled == false) return GatewaySyncRunOutcome.SUCCESS
            api.acknowledge(command.id)
            if (!store.markRemoteAccepted(command.id)) return GatewaySyncRunOutcome.RETRY
        }
        val latestSettings = credentials.load() ?: return GatewaySyncRunOutcome.SUCCESS
        if (!latestSettings.enabled) return GatewaySyncRunOutcome.SUCCESS

        val payload = runCatching {
            credentials.decryptPayload(command.encryptedDestination) to
                credentials.decryptPayload(command.encryptedBody)
        }.getOrNull() ?: run {
            store.markRemoteStatus(command.id, RemoteCommandStatus.FAILED, "send_failed")
            api.report(command.id, "send_failed", "device_payload_unavailable")
            store.markRemoteReported(command.id, "send_failed")
            return GatewaySyncRunOutcome.SUCCESS
        }
        val (destination, body) = payload
        val valid = SmsInputValidator.validate(SmsInput(destination, body))
        if (valid !is SmsInputValidation.Valid) {
            store.markRemoteStatus(command.id, RemoteCommandStatus.FAILED, "send_failed")
            api.report(command.id, "send_failed", "invalid_device_payload")
            store.markRemoteReported(command.id, "send_failed")
            return GatewaySyncRunOutcome.SUCCESS
        }

        val snapshot = GatewayDiagnostics(appContext).snapshot()
        if (!isReadyToSend(latestSettings, snapshot)) return GatewaySyncRunOutcome.SUCCESS
        if (SmsSendCoordinator.isSending()) return GatewaySyncRunOutcome.RETRY
        val now = System.currentTimeMillis()
        if (now - credentials.lastRemoteSendStartedAt() < MIN_REMOTE_SEND_INTERVAL_MS) return GatewaySyncRunOutcome.RETRY

        val sim = snapshot.sims.firstOrNull { it.subscriptionId == latestSettings.subscriptionId } ?: return GatewaySyncRunOutcome.SUCCESS
        val gateway = AndroidSmsGateway(appContext)
        val partCount = runCatching { gateway.estimateParts(body, sim.subscriptionId) }.getOrDefault(1)

        val authorization = if (command.sendAuthorizationRequired) {
            runCatching { api.sendAuthorization(command.id) }.getOrNull()
        } else {
            null
        }
        when (OtpSendGate.decide(command.sendAuthorizationRequired, authorization)) {
            OtpSendDecision.RetryLater -> return GatewaySyncRunOutcome.RETRY
            OtpSendDecision.Deny -> {
                if (authorization?.reason == "already_started") {
                    store.markRemoteStatus(command.id, RemoteCommandStatus.UNKNOWN, "unknown")
                    uploadPendingReports(api, store)
                } else {
                    store.markRemoteStatus(command.id, RemoteCommandStatus.FAILED, null)
                }
                return GatewaySyncRunOutcome.SUCCESS
            }
            OtpSendDecision.Proceed -> Unit
        }

        credentials.recordRemoteSendStartedAt(now)
        return when (gateway.send(SmsSendRequest(
            destination = valid.normalizedDestination,
            body = body,
            subscriptionId = sim.subscriptionId,
            simSlot = sim.slot,
            carrierName = sim.carrier,
            partCountForReview = partCount,
            remoteMessageId = command.id,
        ))) {
            is SmsSendResult.Started -> {
                GatewayWorkScheduler.enqueueDrain(appContext)
                GatewaySyncRunOutcome.SUCCESS
            }
            is SmsSendResult.Rejected -> {
                store.markRemoteStatus(command.id, RemoteCommandStatus.FAILED, "send_failed")
                uploadPendingReports(api, store)
                GatewaySyncRunOutcome.SUCCESS
            }
            is SmsSendResult.Failed -> {
                uploadPendingReports(api, store)
                GatewaySyncRunOutcome.SUCCESS
            }
        }
    }

    private fun uploadPendingReports(api: GatewayApiClient, store: SmsAttemptStore) {
        store.pendingRemoteCommands().forEach { command ->
            val status = command.pendingReportStatus ?: return@forEach
            try {
                api.report(command.id, status, detailCode = "android_result")
                store.markRemoteReported(command.id, status)
            } catch (exception: GatewayApiException) {
                // Stale local rows must not block new claims behind a permanent backend_error loop.
                if (exception.httpStatus in STALE_REPORT_HTTP_CODES) {
                    store.markRemoteReported(command.id, status)
                }
            }
        }
    }

    private fun recordBlockedSyncState(
        credentials: GatewayCredentialStore,
        settings: GatewaySettings,
        snapshot: GatewaySnapshot,
    ) {
        val state = when {
            ContextCompat.checkSelfPermission(appContext, Manifest.permission.SEND_SMS) != PackageManager.PERMISSION_GRANTED ->
                "sms_permission_denied"
            !snapshot.phoneStatePermissionGranted -> "phone_state_denied"
            !snapshot.simListReadable -> "sim_list_unreadable"
            snapshot.sims.none { it.subscriptionId == settings.subscriptionId } -> "sim_missing"
            else -> {
                val sim = snapshot.sims.first { it.subscriptionId == settings.subscriptionId }
                when {
                    sim.card != SimCardStatus.READY -> "sim_not_ready"
                    sim.service == MobileServiceStatus.UNKNOWN -> "cellular_unconfirmed"
                    sim.service == MobileServiceStatus.OUT_OF_SERVICE ||
                        sim.service == MobileServiceStatus.EMERGENCY_ONLY ||
                        sim.service == MobileServiceStatus.POWER_OFF -> "cellular_unavailable"
                    snapshot.internet != InternetStatus.CONNECTED -> "waiting_for_internet"
                    else -> "device_not_ready"
                }
            }
        }
        recordSyncState(state)
    }

    private fun recordSyncState(state: String) {
        val previous = credentials.recordSyncState(state)
        val notification = com.almazen.sms.domain.GatewayStatusTransition.notificationFor(previous, state) ?: return
        com.almazen.sms.worker.GatewayStatusNotification.showGatewayTransition(
            appContext,
            state = state,
            recovered = notification == com.almazen.sms.domain.GatewayStatusTransition.Notification.RECOVERED,
        )
    }

    private fun isReadyToSend(settings: GatewaySettings, snapshot: GatewaySnapshot): Boolean {
        if (ContextCompat.checkSelfPermission(appContext, Manifest.permission.SEND_SMS) != PackageManager.PERMISSION_GRANTED) return false
        if (!snapshot.smsFeatureAvailable || !snapshot.phoneStatePermissionGranted || !snapshot.simListReadable) return false
        val sim = snapshot.sims.firstOrNull { it.subscriptionId == settings.subscriptionId } ?: return false
        if (sim.card != SimCardStatus.READY) return false
        return sim.service == MobileServiceStatus.IN_SERVICE
    }

    private fun InternetStatus.apiValue(): String = when (this) {
        InternetStatus.CONNECTED -> "connected"
        InternetStatus.NO_INTERNET -> "no_internet"
        InternetStatus.DISCONNECTED -> "disconnected"
        InternetStatus.UNKNOWN -> "unknown"
    }

    private fun SimStatus.apiValue(): String = when (card) {
        SimCardStatus.READY -> "ready"
        SimCardStatus.ABSENT -> "absent"
        SimCardStatus.NOT_READY, SimCardStatus.PIN_REQUIRED, SimCardStatus.PUK_REQUIRED,
        SimCardStatus.NETWORK_LOCKED, SimCardStatus.DISABLED, SimCardStatus.ERROR, SimCardStatus.UNKNOWN -> "not_ready"
    }

    private fun GatewaySnapshot.selectedSim(settings: GatewaySettings) =
        sims.firstOrNull { it.subscriptionId == settings.subscriptionId }

    companion object {
        private const val CALLBACK_TIMEOUT_MS = 2 * 60 * 1000L
        private const val MIN_REMOTE_SEND_INTERVAL_MS = 5 * 1000L
        private val STALE_REPORT_HTTP_CODES = setOf(404, 409, 410, 422)
    }
}

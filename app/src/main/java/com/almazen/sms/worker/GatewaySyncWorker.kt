package com.almazen.sms.worker

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.content.ContextCompat
import androidx.work.Worker
import androidx.work.WorkerParameters
import com.almazen.sms.data.GatewayCredentialStore
import com.almazen.sms.data.GatewaySettings
import com.almazen.sms.data.RemoteCommandRecord
import com.almazen.sms.data.RemoteCommandStatus
import com.almazen.sms.data.SmsAttemptStore
import com.almazen.sms.device.GatewayDiagnostics
import com.almazen.sms.device.InternetStatus
import com.almazen.sms.device.MobileServiceStatus
import com.almazen.sms.device.SimCardStatus
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

class GatewaySyncWorker(context: Context, parameters: WorkerParameters) : Worker(context, parameters) {
    private val credentials = GatewayCredentialStore(context.applicationContext)

    override fun doWork(): Result {
        val settings = runCatching { credentials.load() }.getOrNull() ?: return Result.success()
        val api = GatewayApiClient(settings)

        return try {
            SmsAttemptStore(applicationContext).use { store ->
                store.markStaleAttemptsUnknown(System.currentTimeMillis() - CALLBACK_TIMEOUT_MS)
                val snapshot = GatewayDiagnostics(applicationContext).snapshot()
                api.heartbeat(
                    appVersion = applicationContext.packageManager.getPackageInfo(applicationContext.packageName, 0).versionName ?: "unknown",
                    androidVersion = Build.VERSION.RELEASE ?: "unknown",
                    apiLevel = Build.VERSION.SDK_INT,
                    networkStatus = snapshot.internet.apiValue(),
                    simStatus = snapshot.selectedSim(settings)?.apiValue() ?: "unknown"
                )
                credentials.recordSyncState("connected")
                uploadPendingReports(api, store)
                if (!settings.enabled || !isReadyToSend(settings, snapshot)) return Result.success()

                var command = store.pendingRemoteCommands()
                    .firstOrNull { it.status == RemoteCommandStatus.RECEIVED || it.status == RemoteCommandStatus.ACCEPTED_BY_DEVICE }

                if (command == null) {
                    val remote = api.claim() ?: return Result.success()
                    store.saveRemoteCommand(
                        remote.message.id,
                        credentials.encryptPayload(remote.message.destination),
                        credentials.encryptPayload(remote.message.body),
                        sendAuthorizationRequired = remote.sendAuthorizationRequired,
                    )
                    command = store.remoteCommand(remote.message.id) ?: return Result.retry()
                }

                sendCommand(command, api, store)
            }
        } catch (exception: GatewayApiException) {
            if (exception.httpStatus == 401 || exception.httpStatus == 403) {
                credentials.setEnabled(false)
                credentials.recordSyncState("credential_rejected")
                GatewayWorkScheduler.disable(applicationContext)
                Result.failure()
            } else {
                credentials.recordSyncState("backend_error")
                Result.retry()
            }
        } catch (_: IOException) {
            credentials.recordSyncState("network_error")
            Result.retry()
        } catch (_: SecurityException) {
            credentials.recordSyncState("permission_unavailable")
            Result.success()
        } catch (_: RuntimeException) {
            credentials.recordSyncState("local_error")
            Result.retry()
        }
    }

    private fun sendCommand(
        command: RemoteCommandRecord,
        api: GatewayApiClient,
        store: SmsAttemptStore
    ): Result {
        if (command.status == RemoteCommandStatus.RECEIVED) {
            api.acknowledge(command.id)
            if (!store.markRemoteAccepted(command.id)) return Result.retry()
        }
        val latestSettings = credentials.load() ?: return Result.success()
        if (!latestSettings.enabled) return Result.success()

        val payload = runCatching {
            credentials.decryptPayload(command.encryptedDestination) to
                credentials.decryptPayload(command.encryptedBody)
        }.getOrNull() ?: run {
            store.markRemoteStatus(command.id, RemoteCommandStatus.FAILED, "send_failed")
            api.report(command.id, "send_failed", "device_payload_unavailable")
            store.markRemoteReported(command.id, "send_failed")
            return Result.success()
        }
        val (destination, body) = payload
        val valid = SmsInputValidator.validate(SmsInput(destination, body))
        if (valid !is SmsInputValidation.Valid) {
            store.markRemoteStatus(command.id, RemoteCommandStatus.FAILED, "send_failed")
            api.report(command.id, "send_failed", "invalid_device_payload")
            store.markRemoteReported(command.id, "send_failed")
            return Result.success()
        }

        val snapshot = GatewayDiagnostics(applicationContext).snapshot()
        if (!isReadyToSend(latestSettings, snapshot)) return Result.success()
        if (SmsSendCoordinator.isSending()) return Result.retry()
        val now = System.currentTimeMillis()
        if (now - credentials.lastRemoteSendStartedAt() < MIN_REMOTE_SEND_INTERVAL_MS) return Result.retry()

        val sim = snapshot.sims.firstOrNull { it.subscriptionId == latestSettings.subscriptionId } ?: return Result.success()
        val gateway = AndroidSmsGateway(applicationContext)
        val partCount = runCatching { gateway.estimateParts(body, sim.subscriptionId) }.getOrDefault(1)

        val authorization = if (command.sendAuthorizationRequired) {
            runCatching { api.sendAuthorization(command.id) }.getOrNull()
        } else {
            null
        }
        when (OtpSendGate.decide(command.sendAuthorizationRequired, authorization)) {
            OtpSendDecision.RetryLater -> return Result.retry()
            OtpSendDecision.Deny -> {
                if (authorization?.reason == "already_started") {
                    // The previous authorization may have reached the server while its
                    // response was lost. Preserve ambiguity and never issue SMS again.
                    store.markRemoteStatus(command.id, RemoteCommandStatus.UNKNOWN, "unknown")
                    uploadPendingReports(api, store)
                } else {
                    store.markRemoteStatus(command.id, RemoteCommandStatus.FAILED, null)
                }
                return Result.success()
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
            remoteMessageId = command.id
        ))) {
            is SmsSendResult.Started -> {
                GatewayWorkScheduler.enqueueDrain(applicationContext)
                Result.success()
            }
            is SmsSendResult.Rejected -> {
                store.markRemoteStatus(command.id, RemoteCommandStatus.FAILED, "send_failed")
                uploadPendingReports(api, store)
                Result.success()
            }
            is SmsSendResult.Failed -> {
                uploadPendingReports(api, store)
                Result.success()
            }
        }
    }

    private fun uploadPendingReports(api: GatewayApiClient, store: SmsAttemptStore) {
        store.pendingRemoteCommands().forEach { command ->
            val status = command.pendingReportStatus ?: return@forEach
            api.report(command.id, status, detailCode = "android_result")
            store.markRemoteReported(command.id, status)
        }
    }

    private fun isReadyToSend(settings: GatewaySettings, snapshot: com.almazen.sms.device.GatewaySnapshot): Boolean {
        if (ContextCompat.checkSelfPermission(applicationContext, Manifest.permission.SEND_SMS) != PackageManager.PERMISSION_GRANTED) return false
        if (!snapshot.smsFeatureAvailable || !snapshot.phoneStatePermissionGranted) return false
        val sim = snapshot.sims.firstOrNull { it.subscriptionId == settings.subscriptionId } ?: return false
        if (sim.card != SimCardStatus.READY) return false
        return sim.service != MobileServiceStatus.OUT_OF_SERVICE &&
            sim.service != MobileServiceStatus.EMERGENCY_ONLY &&
            sim.service != MobileServiceStatus.POWER_OFF
    }

    private fun InternetStatus.apiValue(): String = when (this) {
        InternetStatus.CONNECTED -> "connected"
        InternetStatus.NO_INTERNET -> "no_internet"
        InternetStatus.DISCONNECTED -> "disconnected"
        InternetStatus.UNKNOWN -> "unknown"
    }

    private fun com.almazen.sms.device.SimStatus.apiValue(): String = when (card) {
        SimCardStatus.READY -> "ready"
        SimCardStatus.ABSENT -> "absent"
        SimCardStatus.NOT_READY, SimCardStatus.PIN_REQUIRED, SimCardStatus.PUK_REQUIRED,
        SimCardStatus.NETWORK_LOCKED, SimCardStatus.DISABLED, SimCardStatus.ERROR, SimCardStatus.UNKNOWN -> "not_ready"
    }

    private fun com.almazen.sms.device.GatewaySnapshot.selectedSim(settings: GatewaySettings) =
        sims.firstOrNull { it.subscriptionId == settings.subscriptionId }

    companion object {
        private const val CALLBACK_TIMEOUT_MS = 2 * 60 * 1000L
        private const val MIN_REMOTE_SEND_INTERVAL_MS = 60 * 1000L
    }
}

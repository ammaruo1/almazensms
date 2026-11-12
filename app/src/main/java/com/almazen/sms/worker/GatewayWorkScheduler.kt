package com.almazen.sms.worker

import android.content.Context
import android.os.Build
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.OutOfQuotaPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import com.almazen.sms.domain.BackgroundExecutionPolicy
import java.util.concurrent.TimeUnit

object GatewayWorkScheduler {
    private const val PERIODIC_WORK = "almazen-gateway-periodic"
    private const val IMMEDIATE_WORK = "almazen-gateway-immediate"
    private const val DRAIN_WORK = "almazen-gateway-drain"
    private const val TOKEN_REGISTRATION_WORK = "almazen-fcm-token-registration"

    fun enable(context: Context) {
        schedulePeriodic(context)
        enqueueNow(context)
        com.almazen.sms.platform.GatewaySyncMonitor.startIfNeeded(context)
    }

    fun restorePeriodicIfEnabled(context: Context) {
        val enabled = runCatching {
            com.almazen.sms.data.GatewayCredentialStore(context).load()?.enabled == true
        }.getOrDefault(false)
        if (enabled) {
            schedulePeriodic(context)
        }
    }

    private fun schedulePeriodic(context: Context) {
        WorkManager.getInstance(context.applicationContext).enqueueUniquePeriodicWork(
            PERIODIC_WORK,
            ExistingPeriodicWorkPolicy.UPDATE,
            PeriodicWorkRequestBuilder<GatewaySyncWorker>(
                BackgroundExecutionPolicy.minimumPeriodicIntervalMinutes(Build.VERSION.SDK_INT).toLong(),
                TimeUnit.MINUTES
            )
                .setConstraints(networkConstraint())
                .build()
        )
    }

    fun enqueueNow(context: Context) {
        enqueueSync(context, expedited = true, policy = ExistingWorkPolicy.KEEP)
    }

    /** Sends a heartbeat carrying the FCM token, without claiming or sending queued messages. */
    fun enqueueTokenRegistration(context: Context) {
        val request = OneTimeWorkRequestBuilder<GatewayTokenRegistrationWorker>()
            .setConstraints(networkConstraint())
            .build()
        WorkManager.getInstance(context.applicationContext).enqueueUniqueWork(
            TOKEN_REGISTRATION_WORK,
            ExistingWorkPolicy.KEEP,
            request,
        )
    }

    /** Called immediately from FCM. Only high-priority pushes request expedited execution. */
    fun enqueuePushTriggeredSync(context: Context, highPriority: Boolean) {
        enqueueSync(
            context,
            expedited = highPriority,
            policy = ExistingWorkPolicy.APPEND_OR_REPLACE,
        )
    }

    private fun enqueueSync(context: Context, expedited: Boolean, policy: ExistingWorkPolicy) {
        val builder = OneTimeWorkRequestBuilder<GatewaySyncWorker>()
            .setConstraints(networkConstraint())
        if (expedited && BackgroundExecutionPolicy.canRequestExpeditedWork(Build.VERSION.SDK_INT)) {
            builder.setExpedited(OutOfQuotaPolicy.RUN_AS_NON_EXPEDITED_WORK_REQUEST)
        }
        val request = builder.build()
        WorkManager.getInstance(context.applicationContext).enqueueUniqueWork(IMMEDIATE_WORK, policy, request)
    }

    fun enqueueDrain(context: Context) {
        val request = OneTimeWorkRequestBuilder<GatewaySyncWorker>()
            .setInitialDelay(5, TimeUnit.SECONDS)
            .setConstraints(networkConstraint())
            .build()
        WorkManager.getInstance(context.applicationContext).enqueueUniqueWork(DRAIN_WORK, ExistingWorkPolicy.APPEND_OR_REPLACE, request)
    }

    fun disable(context: Context) {
        val workManager = WorkManager.getInstance(context.applicationContext)
        workManager.cancelUniqueWork(PERIODIC_WORK)
        workManager.cancelUniqueWork(IMMEDIATE_WORK)
        workManager.cancelUniqueWork(DRAIN_WORK)
        com.almazen.sms.platform.GatewaySyncMonitor.stop(context)
    }

    private fun networkConstraint() = Constraints.Builder()
        .setRequiredNetworkType(NetworkType.CONNECTED)
        .build()
}

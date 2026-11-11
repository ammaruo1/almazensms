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

    fun enable(context: Context) {
        val workManager = WorkManager.getInstance(context.applicationContext)
        workManager.enqueueUniquePeriodicWork(
            PERIODIC_WORK,
            ExistingPeriodicWorkPolicy.UPDATE,
            PeriodicWorkRequestBuilder<GatewaySyncWorker>(
                BackgroundExecutionPolicy.minimumPeriodicIntervalMinutes(Build.VERSION.SDK_INT).toLong(),
                TimeUnit.MINUTES
            )
                .setConstraints(networkConstraint())
                .build()
        )
        enqueueNow(context)
    }

    fun enqueueNow(context: Context) {
        val builder = OneTimeWorkRequestBuilder<GatewaySyncWorker>()
            .setConstraints(networkConstraint())
        if (BackgroundExecutionPolicy.canRequestExpeditedWork(Build.VERSION.SDK_INT)) {
            builder.setExpedited(OutOfQuotaPolicy.RUN_AS_NON_EXPEDITED_WORK_REQUEST)
        }
        val request = builder.build()
        WorkManager.getInstance(context.applicationContext).enqueueUniqueWork(IMMEDIATE_WORK, ExistingWorkPolicy.KEEP, request)
    }

    fun enqueueDrain(context: Context) {
        val request = OneTimeWorkRequestBuilder<GatewaySyncWorker>()
            .setInitialDelay(1, TimeUnit.MINUTES)
            .setConstraints(networkConstraint())
            .build()
        WorkManager.getInstance(context.applicationContext).enqueueUniqueWork(DRAIN_WORK, ExistingWorkPolicy.APPEND_OR_REPLACE, request)
    }

    fun disable(context: Context) {
        val workManager = WorkManager.getInstance(context.applicationContext)
        workManager.cancelUniqueWork(PERIODIC_WORK)
        workManager.cancelUniqueWork(IMMEDIATE_WORK)
        workManager.cancelUniqueWork(DRAIN_WORK)
    }

    private fun networkConstraint() = Constraints.Builder()
        .setRequiredNetworkType(NetworkType.CONNECTED)
        .build()
}

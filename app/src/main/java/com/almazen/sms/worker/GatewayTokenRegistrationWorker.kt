package com.almazen.sms.worker

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters

/** Registers a refreshed FCM token via heartbeat only; this worker must never claim SMS work. */
class GatewayTokenRegistrationWorker(context: Context, parameters: WorkerParameters) : Worker(context, parameters) {
    override fun doWork(): Result = when (GatewaySyncEngine(applicationContext).registerPushToken()) {
        GatewaySyncRunOutcome.SUCCESS -> Result.success()
        GatewaySyncRunOutcome.RETRY -> Result.retry()
        GatewaySyncRunOutcome.FAILURE -> Result.failure()
    }
}

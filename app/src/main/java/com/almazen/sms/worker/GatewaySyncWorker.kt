package com.almazen.sms.worker

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters

class GatewaySyncWorker(context: Context, parameters: WorkerParameters) : Worker(context, parameters) {
    override fun doWork(): Result = when (GatewaySyncEngine(applicationContext).runOnce()) {
        GatewaySyncRunOutcome.SUCCESS -> Result.success()
        GatewaySyncRunOutcome.RETRY -> Result.retry()
        GatewaySyncRunOutcome.FAILURE -> Result.failure()
    }
}

package com.almazen.sms.platform

import android.content.Context
import android.util.Log
import com.almazen.sms.data.GatewayCredentialStore
import com.almazen.sms.data.SmsAttemptStore
import com.almazen.sms.worker.GatewayWorkScheduler

/**
 * Reconciles local attempt state after boot or app process start and re-schedules deferrable sync when opted in.
 * Does not start a foreground service or promise continuous operation.
 */
object GatewayRecoveryCoordinator {
    private const val TAG = "AlmazenGatewayRecovery"
    private const val STALE_CALLBACK_MS = 2 * 60 * 1000L

    /** After BOOT_COMPLETED: mark in-flight sends unknown and optionally enqueue WorkManager. */
    fun reconcileAfterBoot(context: Context) {
        val appContext = context.applicationContext
        val interrupted = runCatching {
            SmsAttemptStore(appContext).use { it.markInterruptedAttemptsUnknown() }
        }.getOrElse {
            Log.w(TAG, "Boot reconciliation could not update local attempt state")
            return
        }
        Log.i(TAG, "Boot reconciliation completed; interrupted attempts=$interrupted")
        rescheduleGatewaySyncIfEnabled(appContext)
    }

    /** On cold start / UI entry: stale callbacks + WorkManager nudge when gateway is enabled. */
    fun reconcileOnAppStart(context: Context) {
        val appContext = context.applicationContext
        runCatching {
            SmsAttemptStore(appContext).use {
                it.markStaleAttemptsUnknown(System.currentTimeMillis() - STALE_CALLBACK_MS)
            }
        }.onFailure { Log.w(TAG, "Startup reconciliation could not refresh attempt state") }
        rescheduleGatewaySyncIfEnabled(appContext)
    }

    private fun rescheduleGatewaySyncIfEnabled(context: Context) {
        val enabled = runCatching { GatewayCredentialStore(context).load()?.enabled == true }
            .getOrDefault(false)
        if (!enabled) return
        runCatching { GatewayWorkScheduler.enqueueNow(context) }
            .onFailure { Log.w(TAG, "Could not enqueue gateway sync after recovery") }
    }
}

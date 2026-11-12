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
        runCatching {
            SmsAttemptStore(appContext).use { it.markInterruptedAttemptsUnknown() }
        }.onSuccess { interrupted ->
            Log.i(TAG, "Boot reconciliation completed; interrupted attempts=$interrupted")
        }.onFailure {
            Log.w(TAG, "Boot reconciliation could not update local attempt state")
        }
        rescheduleGatewaySyncIfEnabled(appContext, restorePeriodic = true)
        GatewaySyncMonitor.startIfNeeded(appContext)
    }

    /** On cold start / UI entry: stale callbacks + WorkManager nudge when gateway is enabled. */
    fun reconcileOnAppStart(context: Context) {
        val appContext = context.applicationContext
        runCatching {
            SmsAttemptStore(appContext).use {
                it.markStaleAttemptsUnknown(System.currentTimeMillis() - STALE_CALLBACK_MS)
            }
        }.onFailure { Log.w(TAG, "Startup reconciliation could not refresh attempt state") }
        rescheduleGatewaySyncIfEnabled(appContext, restorePeriodic = true)
        GatewaySyncMonitor.startIfNeeded(appContext)
    }

    /** After an app update the process was killed, so in-flight sends are UNKNOWN and are not resent. */
    fun reconcileAfterPackageUpdate(context: Context) {
        val appContext = context.applicationContext
        runCatching {
            SmsAttemptStore(appContext).use { it.markInterruptedAttemptsUnknown() }
        }.onSuccess { interrupted ->
            Log.i(TAG, "Update reconciliation completed; interrupted attempts=$interrupted")
        }.onFailure {
            Log.w(TAG, "Update reconciliation could not update local attempt state")
        }
        rescheduleGatewaySyncIfEnabled(appContext, restorePeriodic = true)
        GatewaySyncMonitor.startIfNeeded(appContext)
    }

    private fun rescheduleGatewaySyncIfEnabled(context: Context, restorePeriodic: Boolean = false) {
        val enabled = runCatching { GatewayCredentialStore(context).load()?.enabled == true }
            .getOrDefault(false)
        if (!enabled) {
            GatewaySyncMonitor.stop(context)
            return
        }
        if (restorePeriodic) {
            runCatching { GatewayWorkScheduler.restorePeriodicIfEnabled(context) }
                .onFailure { Log.w(TAG, "Could not restore periodic gateway work") }
        }
        runCatching { GatewayWorkScheduler.enqueueNow(context) }
            .onFailure { Log.w(TAG, "Could not enqueue gateway sync after recovery") }
    }
}

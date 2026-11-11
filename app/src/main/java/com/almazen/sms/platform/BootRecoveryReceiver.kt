package com.almazen.sms.platform

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
/** Marks interrupted sends unresolved after boot and nudges WorkManager when the gateway was enabled. */
class BootRecoveryReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED) return
        runCatching { GatewayRecoveryCoordinator.reconcileAfterBoot(context) }
            .onFailure { Log.w(TAG, "Boot recovery failed") }
    }

    private companion object { const val TAG = "AlmazenBootRecovery" }
}

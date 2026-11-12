package com.almazen.sms.platform

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log

class PackageUpdateReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_MY_PACKAGE_REPLACED) return
        runCatching { GatewayRecoveryCoordinator.reconcileAfterPackageUpdate(context) }
            .onFailure { Log.w(TAG, "Package update recovery failed") }
    }

    private companion object { const val TAG = "AlmazenPkgUpdate" }
}

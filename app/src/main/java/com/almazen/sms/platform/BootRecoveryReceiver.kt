package com.almazen.sms.platform

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import com.almazen.sms.data.SmsAttemptStore

/** Marks interrupted sends unresolved after Android has completed boot and user unlock. */
class BootRecoveryReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED) return
        runCatching {
            SmsAttemptStore(context.applicationContext).use { it.markInterruptedAttemptsUnknown() }
        }.onSuccess { count ->
            Log.i(TAG, "Boot reconciliation completed; interrupted attempts=$count")
        }.onFailure {
            Log.w(TAG, "Boot reconciliation could not update local attempt state")
        }
    }

    private companion object { const val TAG = "AlmazenBootRecovery" }
}

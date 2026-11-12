package com.almazen.sms.platform

import android.content.Context
import com.almazen.sms.data.GatewayCredentialStore
import com.almazen.sms.worker.GatewayWorkScheduler
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging

object GatewayPush {
    fun isConfigured(context: Context): Boolean =
        context.applicationContext.resources.getIdentifier("google_app_id", "string", context.packageName) != 0

    fun initialize(context: Context) {
        val appContext = context.applicationContext
        if (!isConfigured(appContext)) return
        runCatching {
            if (FirebaseApp.getApps(appContext).isEmpty()) {
                FirebaseApp.initializeApp(appContext)
            }
            FirebaseMessaging.getInstance().token.addOnSuccessListener { token ->
                if (!token.isNullOrBlank()) remember(appContext, token)
            }
        }
    }

    fun remember(context: Context, token: String) {
        val appContext = context.applicationContext
        GatewayCredentialStore(appContext).saveFcmToken(token)
        // Register the token through a heartbeat-only worker. Token refresh must never trigger a
        // message claim, because an enabled gateway may have queued production messages.
        if (GatewayCredentialStore(appContext).load() != null) {
            GatewayWorkScheduler.enqueueTokenRegistration(appContext)
        }
    }
}

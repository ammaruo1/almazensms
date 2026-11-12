package com.almazen.sms.worker

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.Manifest
import androidx.core.content.ContextCompat
import androidx.core.app.NotificationCompat
import com.almazen.sms.R
import com.almazen.sms.MainActivity

object GatewayStatusNotification {
    private const val ALERT_CHANNEL_ID = "almazen_gateway_alert"
    private const val INCOMING_REQUEST_NOTIFICATION_ID = 4104
    private const val STATUS_NOTIFICATION_ID = 4105

    fun ensureChannels(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        manager.createNotificationChannel(
            NotificationChannel(ALERT_CHANNEL_ID, context.getString(R.string.gateway_alert_channel_name), NotificationManager.IMPORTANCE_HIGH).apply {
                description = context.getString(R.string.gateway_alert_channel_description)
            },
        )
    }

    /** Posts a privacy-safe, user-visible notice for high-priority FCM wake messages. */
    fun showIncomingRequest(context: Context) {
        ensureChannels(context)
        val open = PendingIntent.getActivity(
            context,
            INCOMING_REQUEST_NOTIFICATION_ID,
            Intent(context, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val notification = NotificationCompat.Builder(context, ALERT_CHANNEL_ID)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle(context.getString(R.string.gateway_incoming_request_title))
            .setContentText(context.getString(R.string.gateway_incoming_request_body))
            .setContentIntent(open)
            .setAutoCancel(true)
            .setOnlyAlertOnce(true)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .build()
        context.getSystemService(NotificationManager::class.java)
            ?.notify(INCOMING_REQUEST_NOTIFICATION_ID, notification)
    }

    /** Posts only on a status transition; repeated failed syncs do not produce notification spam. */
    fun showGatewayTransition(context: Context, state: String, recovered: Boolean) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) return
        ensureChannels(context)
        val open = PendingIntent.getActivity(
            context,
            STATUS_NOTIFICATION_ID,
            Intent(context, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val notification = NotificationCompat.Builder(context, ALERT_CHANNEL_ID)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle(context.getString(if (recovered) R.string.gateway_recovered_title else R.string.gateway_attention_title))
            .setContentText(context.getString(if (recovered) R.string.gateway_recovered_body else attentionBody(state)))
            .setContentIntent(open)
            .setAutoCancel(true)
            .setOnlyAlertOnce(true)
            .setCategory(NotificationCompat.CATEGORY_STATUS)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .build()
        context.getSystemService(NotificationManager::class.java)?.notify(STATUS_NOTIFICATION_ID, notification)
    }

    private fun attentionBody(state: String): Int = when (state) {
        "network_error", "waiting_for_internet" -> R.string.gateway_attention_internet
        "credential_rejected" -> R.string.gateway_attention_credentials
        "permission_unavailable", "sms_permission_denied", "phone_state_denied" -> R.string.gateway_attention_permission
        "sim_missing", "sim_not_ready", "sim_list_unreadable" -> R.string.gateway_attention_sim
        "cellular_unconfirmed", "cellular_unavailable" -> R.string.gateway_attention_cellular
        "backend_error" -> R.string.gateway_attention_server
        else -> R.string.gateway_attention_body
    }

}

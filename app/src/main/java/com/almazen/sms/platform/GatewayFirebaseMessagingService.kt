package com.almazen.sms.platform

import com.almazen.sms.data.GatewayCredentialStore
import com.almazen.sms.worker.GatewayStatusNotification
import com.almazen.sms.worker.GatewayWorkScheduler
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

class GatewayFirebaseMessagingService : FirebaseMessagingService() {
    override fun onNewToken(token: String) {
        GatewayPush.remember(this, token)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        if (GatewayCredentialStore(this).load()?.enabled != true) return
        val messageId = message.data[DATA_MESSAGE_ID]
        if (messageId == null || !MESSAGE_ID_PATTERN.matches(messageId)) return
        GatewayStatusNotification.showIncomingRequest(this)
        val highPriority = message.priority == RemoteMessage.PRIORITY_HIGH
        GatewayWorkScheduler.enqueuePushTriggeredSync(this, highPriority)
    }

    private companion object {
        const val DATA_MESSAGE_ID = "message_id"
        val MESSAGE_ID_PATTERN = Regex("^[0-9a-fA-F-]{36}$")
    }
}

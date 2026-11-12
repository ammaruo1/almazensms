package com.almazen.sms.domain

/** Deduplicates local alerts while still notifying when a failed gateway recovers. */
object GatewayStatusTransition {
    fun notificationFor(previous: String?, current: String): Notification? {
        if (previous == current || current == "gateway_paused") return null
        return when (current) {
            "connected" -> if (previous != null && previous != "not_configured") Notification.RECOVERED else null
            "not_configured" -> null
            else -> Notification.NEEDS_ATTENTION
        }
    }

    enum class Notification { NEEDS_ATTENTION, RECOVERED }
}

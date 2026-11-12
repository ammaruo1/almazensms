package com.almazen.sms

import com.almazen.sms.domain.GatewayStatusTransition
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class GatewayStatusTransitionTest {
    @Test
    fun repeatedFailureDoesNotNotifyTwice() {
        assertNull(GatewayStatusTransition.notificationFor("network_error", "network_error"))
    }

    @Test
    fun failureTransitionNotifiesAttention() {
        assertEquals(
            GatewayStatusTransition.Notification.NEEDS_ATTENTION,
            GatewayStatusTransition.notificationFor("connected", "network_error"),
        )
    }

    @Test
    fun connectionRecoveryNotifiesAfterFailure() {
        assertEquals(
            GatewayStatusTransition.Notification.RECOVERED,
            GatewayStatusTransition.notificationFor("network_error", "connected"),
        )
    }

    @Test
    fun initialConnectionAndPauseAreSilent() {
        assertNull(GatewayStatusTransition.notificationFor(null, "connected"))
        assertNull(GatewayStatusTransition.notificationFor("network_error", "gateway_paused"))
    }
}

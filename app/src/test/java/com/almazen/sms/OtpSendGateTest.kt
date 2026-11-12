package com.almazen.sms

import com.almazen.sms.domain.OtpSendDecision
import com.almazen.sms.domain.OtpSendGate
import com.almazen.sms.domain.SendAuthorizationResult
import org.junit.Assert.assertEquals
import org.junit.Test

class OtpSendGateTest {
    @Test
    fun nonOtpCommandsProceedWithoutServerCheck() {
        assertEquals(OtpSendDecision.Proceed, OtpSendGate.decide(requiresAuthorization = false, authorization = null))
    }

    @Test
    fun otpProceedsWhenServerAuthorizesSend() {
        assertEquals(
            OtpSendDecision.Proceed,
            OtpSendGate.decide(requiresAuthorization = true, authorization = SendAuthorizationResult(send = true, reason = "authorized"))
        )
    }

    @Test
    fun otpDeniedWhenServerRejectsSend() {
        assertEquals(
            OtpSendDecision.Deny,
            OtpSendGate.decide(requiresAuthorization = true, authorization = SendAuthorizationResult(send = false, reason = "expired"))
        )
    }

    @Test
    fun otpWaitsWhenServerCannotBeReached() {
        assertEquals(OtpSendDecision.RetryLater, OtpSendGate.decide(requiresAuthorization = true, authorization = null))
    }
}

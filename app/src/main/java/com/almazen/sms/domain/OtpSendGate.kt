package com.almazen.sms.domain

data class SendAuthorizationResult(val send: Boolean, val reason: String)

sealed class OtpSendDecision {
    data object Proceed : OtpSendDecision()

    data object Deny : OtpSendDecision()

    data object RetryLater : OtpSendDecision()
}

object OtpSendGate {
    fun decide(requiresAuthorization: Boolean, authorization: SendAuthorizationResult?): OtpSendDecision {
        if (!requiresAuthorization) {
            return OtpSendDecision.Proceed
        }
        if (authorization == null) {
            return OtpSendDecision.RetryLater
        }

        return if (authorization.send) OtpSendDecision.Proceed else OtpSendDecision.Deny
    }
}

package com.almazen.sms

import com.almazen.sms.domain.SmsAttemptStatus
import com.almazen.sms.domain.SmsAttemptStatusReducer
import com.almazen.sms.domain.SmsInput
import com.almazen.sms.domain.SmsInputValidation
import com.almazen.sms.domain.SmsInputValidator
import com.almazen.sms.domain.SmsPartResult
import com.almazen.sms.domain.GatewayUrlPolicy
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class SmsDomainTest {
    @Test
    fun validatorRejectsEmptyAndMalformedInput() {
        assertEquals(
            SmsInputValidation.Invalid(SmsInputValidation.Reason.EMPTY_DESTINATION),
            SmsInputValidator.validate(SmsInput("  ", "hello"))
        )
        assertEquals(
            SmsInputValidation.Invalid(SmsInputValidation.Reason.EMPTY_BODY),
            SmsInputValidator.validate(SmsInput("967771234567", "  "))
        )
        assertTrue(SmsInputValidator.validate(SmsInput("+967 77-12", "hello")) is SmsInputValidation.Valid)
        assertEquals(
            SmsInputValidation.Invalid(SmsInputValidation.Reason.INVALID_DESTINATION),
            SmsInputValidator.validate(SmsInput("+967+771234567", "hello"))
        )
    }

    @Test
    fun sentMeansAcceptedByPlatformAndNotDelivery() {
        assertEquals(SmsAttemptStatus.SENT, SmsAttemptStatusReducer.reduce(listOf(SmsPartResult(sent = true))))
        assertEquals(SmsAttemptStatus.DELIVERED, SmsAttemptStatusReducer.reduce(listOf(SmsPartResult(sent = true, delivered = true))))
    }

    @Test
    fun partialMultipartFailureIsFailedAndMissingCallbackIsUnknown() {
        assertEquals(
            SmsAttemptStatus.SEND_FAILED,
            SmsAttemptStatusReducer.reduce(listOf(SmsPartResult(sent = true), SmsPartResult(sent = false)))
        )
        assertEquals(
            SmsAttemptStatus.UNKNOWN,
            SmsAttemptStatusReducer.reduce(listOf(SmsPartResult()), timedOut = true)
        )
    }

    @Test
    fun masksRecipientAndNeverIncludesBody() {
        assertEquals("•••• 567", SmsInputValidator.maskDestination("+967771234567"))
    }

    @Test
    fun gatewayEndpointRequiresHttpsHostAndNoEmbeddedSecretsOrApiPath() {
        assertEquals("https://sms.example.com", GatewayUrlPolicy.validate(" https://sms.example.com/ "))
        listOf(
            "http://sms.example.com",
            "https://user:password@sms.example.com",
            "https://sms.example.com/api/v1",
            "https://sms.example.com?token=secret"
        ).forEach { endpoint ->
            try {
                GatewayUrlPolicy.validate(endpoint)
                throw AssertionError("Expected invalid endpoint to be rejected: ${endpoint.substringBefore('?')}")
            } catch (_: IllegalArgumentException) {
                // Expected: endpoints must not downgrade transport or carry credentials in the URL.
            }
        }
    }
}

package com.almazen.sms.domain

import java.util.Locale

enum class SmsAttemptStatus {
    SENDING,
    SENT,
    DELIVERED,
    SEND_FAILED,
    DELIVERY_FAILED,
    UNKNOWN
}

data class SmsAttempt(
    val id: String,
    val maskedRecipient: String,
    val messageBody: String?,
    val status: SmsAttemptStatus,
    val createdAt: Long,
    val simSlot: Int,
    val carrierName: String,
    val partCount: Int,
    val detail: String?
)

data class SmsAttemptEvent(
    val attemptId: String,
    val occurredAt: Long,
    val type: String,
    val detail: String?
)

data class SmsPartResult(
    val sent: Boolean? = null,
    val delivered: Boolean? = null
)

data class SmsInput(
    val destination: String,
    val body: String
)

sealed interface SmsInputValidation {
    data class Valid(val normalizedDestination: String) : SmsInputValidation
    data class Invalid(val reason: Reason) : SmsInputValidation

    enum class Reason { EMPTY_DESTINATION, INVALID_DESTINATION, EMPTY_BODY }
}

object SmsInputValidator {
    fun validate(input: SmsInput): SmsInputValidation {
        val destination = input.destination.trim()
        if (destination.isEmpty()) return SmsInputValidation.Invalid(SmsInputValidation.Reason.EMPTY_DESTINATION)
        if (input.body.isBlank()) return SmsInputValidation.Invalid(SmsInputValidation.Reason.EMPTY_BODY)
        if (destination.any { !(it.isDigit() || it == '+' || it == ' ' || it == '-' || it == '(' || it == ')') }) {
            return SmsInputValidation.Invalid(SmsInputValidation.Reason.INVALID_DESTINATION)
        }
        if (destination.drop(1).contains('+') || destination.count { it.isDigit() } !in 3..20) {
            return SmsInputValidation.Invalid(SmsInputValidation.Reason.INVALID_DESTINATION)
        }
        val normalized = destination.filter { it.isDigit() || it == '+' }
            .map { character -> if (character.isDigit()) character.digitToInt().toString() else character.toString() }
            .joinToString(separator = "")
        return SmsInputValidation.Valid(normalized)
    }

    fun maskDestination(destination: String): String {
        val digits = destination.filter(Char::isDigit)
        if (digits.isEmpty()) return "••••"
        val suffix = digits.takeLast(3)
        return "•••• ${suffix}"
    }
}

object SmsAttemptStatusReducer {
    fun reduce(parts: List<SmsPartResult>, timedOut: Boolean = false): SmsAttemptStatus {
        if (parts.isEmpty()) return if (timedOut) SmsAttemptStatus.UNKNOWN else SmsAttemptStatus.SENDING

        if (parts.any { it.delivered == false }) return SmsAttemptStatus.DELIVERY_FAILED
        if (parts.all { it.delivered == true }) return SmsAttemptStatus.DELIVERED

        val sentResults = parts.map { it.sent }
        if (sentResults.any { it == false }) return SmsAttemptStatus.SEND_FAILED
        if (sentResults.all { it == true }) return SmsAttemptStatus.SENT

        return if (timedOut) SmsAttemptStatus.UNKNOWN else SmsAttemptStatus.SENDING
    }
}

fun SmsAttemptStatus.displayLabel(): String = when (this) {
    SmsAttemptStatus.SENDING -> "قيد الإرسال"
    SmsAttemptStatus.SENT -> "أُرسلت إلى الشبكة"
    SmsAttemptStatus.DELIVERED -> "تم تأكيد الوصول"
    SmsAttemptStatus.SEND_FAILED -> "فشل الإرسال"
    SmsAttemptStatus.DELIVERY_FAILED -> "فشل التسليم"
    SmsAttemptStatus.UNKNOWN -> "النتيجة غير مؤكدة"
}

fun SmsAttemptStatus.shortLabel(): String = when (this) {
    SmsAttemptStatus.SENDING -> "SENDING"
    SmsAttemptStatus.SENT -> "SENT"
    SmsAttemptStatus.DELIVERED -> "DELIVERED"
    SmsAttemptStatus.SEND_FAILED, SmsAttemptStatus.DELIVERY_FAILED -> "FAILED"
    SmsAttemptStatus.UNKNOWN -> "UNKNOWN"
}

fun String.sanitizedCarrierName(): String = trim().take(48).ifBlank { "شبكة غير معروفة" }

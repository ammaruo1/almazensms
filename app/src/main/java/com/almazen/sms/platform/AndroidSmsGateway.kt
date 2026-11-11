package com.almazen.sms.platform

import android.Manifest
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.telephony.SmsManager
import androidx.core.content.ContextCompat
import androidx.core.net.toUri
import com.almazen.sms.data.SmsAttemptStore
import com.almazen.sms.domain.SmsAttemptStatus
import com.almazen.sms.domain.SmsInputValidator
import com.almazen.sms.domain.sanitizedCarrierName
import java.util.UUID

data class SmsSendRequest(
    val destination: String,
    val body: String,
    val subscriptionId: Int,
    val simSlot: Int,
    val carrierName: String,
    val partCountForReview: Int = 1,
    val remoteMessageId: String? = null
)

sealed interface SmsSendResult {
    data class Started(val attemptId: String, val partCount: Int) : SmsSendResult
    data class Rejected(val reason: String) : SmsSendResult
    data class Failed(val attemptId: String, val status: SmsAttemptStatus, val reason: String) : SmsSendResult
}

class AndroidSmsGateway(private val context: Context) {
    fun estimateParts(body: String, subscriptionId: Int): Int =
        smsManager(subscriptionId).divideMessage(body).size.coerceAtLeast(1)

    fun send(request: SmsSendRequest): SmsSendResult {
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.SEND_SMS) != PackageManager.PERMISSION_GRANTED) {
            return SmsSendResult.Rejected("إذن إرسال SMS غير ممنوح.")
        }
        val supportsSms = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            context.packageManager.hasSystemFeature(PackageManager.FEATURE_TELEPHONY_MESSAGING)
        } else {
            context.packageManager.hasSystemFeature(PackageManager.FEATURE_TELEPHONY)
        }
        if (!supportsSms) {
            return SmsSendResult.Rejected("هذا الجهاز لا يعلن دعم إرسال SMS.")
        }
        val normalized = SmsInputValidator.validate(com.almazen.sms.domain.SmsInput(request.destination, request.body))
        if (normalized !is com.almazen.sms.domain.SmsInputValidation.Valid) {
            return SmsSendResult.Rejected("رقم المستلم أو نص الرسالة غير صالح.")
        }
        if (!SmsSendCoordinator.begin()) return SmsSendResult.Rejected("انتظر انتهاء المحاولة الحالية قبل إرسال رسالة أخرى.")

        val id = UUID.randomUUID().toString()
        val carrier = request.carrierName.sanitizedCarrierName()
        val parts = try {
            smsManager(request.subscriptionId).divideMessage(request.body).toList().ifEmpty { listOf(request.body) }
        } catch (_: RuntimeException) {
            SmsSendCoordinator.finish()
            return SmsSendResult.Rejected("تعذر تقسيم الرسالة بواسطة نظام Android.")
        }

        try {
            SmsAttemptStore(context).use { store ->
                store.createAttempt(
                    id = id,
                    maskedRecipient = SmsInputValidator.maskDestination(normalized.normalizedDestination),
                    simSlot = request.simSlot,
                    carrierName = carrier,
                    partCount = parts.size,
                    remoteMessageId = request.remoteMessageId
                )
            }
        } catch (_: RuntimeException) {
            SmsSendCoordinator.finish()
            return SmsSendResult.Rejected("تعذر حفظ سجل المحاولة؛ لم يبدأ الإرسال.")
        }

        return try {
            val manager = smsManager(request.subscriptionId)
            val sentIntents = ArrayList<PendingIntent>(parts.size)
            val deliveryIntents = ArrayList<PendingIntent>(parts.size)
            parts.indices.forEach { partIndex ->
                sentIntents += callbackPendingIntent(id, partIndex, CALLBACK_SENT)
                deliveryIntents += callbackPendingIntent(id, partIndex, CALLBACK_DELIVERY)
            }
            manager.sendMultipartTextMessage(
                normalized.normalizedDestination,
                null,
                ArrayList(parts),
                sentIntents,
                deliveryIntents
            )
            SmsSendResult.Started(id, parts.size)
        } catch (_: SecurityException) {
            val detail = "رفض Android عملية الإرسال أو الصلاحية."
            updateFailure(id, SmsAttemptStatus.SEND_FAILED, detail)
            SmsSendResult.Failed(id, SmsAttemptStatus.SEND_FAILED, detail)
        } catch (_: IllegalArgumentException) {
            val detail = "رفض Android عنوان المستلم أو محتوى الرسالة."
            updateFailure(id, SmsAttemptStatus.SEND_FAILED, detail)
            SmsSendResult.Failed(id, SmsAttemptStatus.SEND_FAILED, detail)
        } catch (_: UnsupportedOperationException) {
            val detail = "ميزة الإرسال غير مدعومة على الجهاز أو الشريحة المختارة."
            updateFailure(id, SmsAttemptStatus.SEND_FAILED, detail)
            SmsSendResult.Failed(id, SmsAttemptStatus.SEND_FAILED, detail)
        } catch (_: RuntimeException) {
            val detail = "تعذر تحديد نتيجة بدء الإرسال؛ تعامل معها كغير مؤكدة."
            updateFailure(id, SmsAttemptStatus.UNKNOWN, detail)
            SmsSendResult.Failed(id, SmsAttemptStatus.UNKNOWN, detail)
        }
    }

    private fun updateFailure(id: String, status: SmsAttemptStatus, detail: String) {
        runCatching { SmsAttemptStore(context).use { it.markInvocationFailure(id, status, detail) } }
        SmsSendCoordinator.finish()
    }

    private fun smsManager(subscriptionId: Int): SmsManager {
        val base = context.getSystemService(SmsManager::class.java)
            ?: throw UnsupportedOperationException("SmsManager unavailable")
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            base.createForSubscriptionId(subscriptionId)
        } else {
            @Suppress("DEPRECATION")
            SmsManager.getSmsManagerForSubscriptionId(subscriptionId)
        }
    }

    private fun callbackPendingIntent(attemptId: String, partIndex: Int, action: String): PendingIntent {
        val intent = Intent(context, SmsCallbackReceiver::class.java)
            .setAction(action)
            .setData("almazen-sms://attempt/$attemptId/$partIndex/${action.substringAfterLast('.')}".toUri())
            .putExtra(EXTRA_ATTEMPT_ID, attemptId)
            .putExtra(EXTRA_PART_INDEX, partIndex)
        return PendingIntent.getBroadcast(
            context,
            partIndex,
            intent,
            // SmsDispatcher fills in the delivery PDU and format at callback time.
            // Keep the PendingIntent explicit and uniquely scoped while allowing those extras.
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE
        )
    }

    companion object {
        const val CALLBACK_SENT = "com.almazen.sms.action.SMS_SENT"
        const val CALLBACK_DELIVERY = "com.almazen.sms.action.SMS_DELIVERY"
        const val EXTRA_ATTEMPT_ID = "com.almazen.sms.extra.ATTEMPT_ID"
        const val EXTRA_PART_INDEX = "com.almazen.sms.extra.PART_INDEX"
    }
}

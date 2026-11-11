package com.almazen.sms.platform

import android.app.Activity
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.telephony.SmsManager
import android.telephony.SmsMessage
import com.almazen.sms.data.SmsAttemptStore
import com.almazen.sms.worker.GatewayWorkScheduler
import java.util.concurrent.Executors

class SmsCallbackReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val attemptId = intent.getStringExtra(AndroidSmsGateway.EXTRA_ATTEMPT_ID) ?: return
        val partIndex = intent.getIntExtra(AndroidSmsGateway.EXTRA_PART_INDEX, -1)
        if (partIndex < 0) return
        val pendingResult = goAsync()
        executor.execute {
            try {
                var shouldSync = false
                SmsAttemptStore(context.applicationContext).use { store ->
                    when (intent.action) {
                        AndroidSmsGateway.CALLBACK_SENT -> {
                            val success = resultCode == Activity.RESULT_OK
                            val detail = if (success) null else sendErrorMessage(resultCode, intent)
                            store.recordSentPart(attemptId, partIndex, success, detail)
                            if (store.allSentCallbacksReceived(attemptId)) SmsSendCoordinator.finish()
                        }
                        AndroidSmsGateway.CALLBACK_DELIVERY -> {
                            val delivered = readDeliveryReport(intent)
                            if (delivered != null) store.recordDeliveryPart(attemptId, partIndex, delivered)
                        }
                    }
                    shouldSync = store.hasPendingRemoteReports()
                }
                if (shouldSync) GatewayWorkScheduler.enqueueNow(context.applicationContext)
            } finally {
                pendingResult.finish()
            }
        }
    }

    private fun readDeliveryReport(intent: Intent): Boolean? = try {
        val pdu = intent.getByteArrayExtra(EXTRA_PDU) ?: return null
        val format = intent.getStringExtra(EXTRA_FORMAT) ?: return null
        val message = SmsMessage.createFromPdu(pdu, format) ?: return null
        when {
            message.status == 0 -> true
            format == SmsMessage.FORMAT_3GPP && message.status in 32..63 -> null
            format == SmsMessage.FORMAT_3GPP2 && ((message.status ushr 24) and 0x03) == 2 -> null
            format == SmsMessage.FORMAT_3GPP && message.status in 1..127 -> false
            format == SmsMessage.FORMAT_3GPP2 -> false
            else -> null
        }
    } catch (_: RuntimeException) {
        null
    }

    private fun sendErrorMessage(code: Int, intent: Intent): String = when (code) {
        SmsManager.RESULT_ERROR_RADIO_OFF -> "راديو الهاتف غير متاح."
        SmsManager.RESULT_ERROR_NO_SERVICE -> "لا توجد خدمة شبكة للإرسال."
        SmsManager.RESULT_ERROR_NULL_PDU -> "لم يستطع Android إنشاء الرسالة."
        SmsManager.RESULT_ERROR_LIMIT_EXCEEDED -> "رفض Android الإرسال بسبب حد الرسائل."
        SmsManager.RESULT_ERROR_FDN_CHECK_FAILURE -> "رفضت إعدادات الاتصال الثابت هذا الرقم."
        else -> "فشل الإرسال لدى Android (رمز $code)."
    }

    companion object {
        private val executor = Executors.newSingleThreadExecutor()
        private const val EXTRA_PDU = "pdu"
        private const val EXTRA_FORMAT = "format"
    }
}

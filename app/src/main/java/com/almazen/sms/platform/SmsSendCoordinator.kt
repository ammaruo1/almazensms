package com.almazen.sms.platform

import java.util.concurrent.atomic.AtomicBoolean

object SmsSendCoordinator {
    private val sending = AtomicBoolean(false)
    @Volatile private var startedAt = 0L

    fun begin(): Boolean = sending.compareAndSet(false, true).also { acquired ->
        if (acquired) startedAt = System.currentTimeMillis()
    }
    fun finish() { startedAt = 0L; sending.set(false) }
    fun expireIfTimedOut(timeoutMs: Long = 2 * 60 * 1000L) {
        if (sending.get() && System.currentTimeMillis() - startedAt > timeoutMs) finish()
    }
    fun isSending(): Boolean = sending.get()
}

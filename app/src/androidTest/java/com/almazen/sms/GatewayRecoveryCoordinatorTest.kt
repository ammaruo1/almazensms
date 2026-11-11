package com.almazen.sms

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.almazen.sms.domain.SmsAttemptStatus
import com.almazen.sms.platform.GatewayRecoveryCoordinator
import com.almazen.sms.data.SmsAttemptStore
import org.junit.Assert.assertEquals
import org.junit.Test
import java.util.UUID

/**
 * Runs on the connected device/emulator (including API 29+ images when used in CI).
 */
class GatewayRecoveryCoordinatorTest {
    @Test
    fun startupRecoveryMarksStaleSendingUnknown() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        context.deleteDatabase("almazen_sms_attempts.db")
        val id = UUID.randomUUID().toString()
        val staleCutoff = System.currentTimeMillis() - (3 * 60 * 1000L)
        SmsAttemptStore(context).use {
            it.createAttempt(id, "•••• 567", 0, "Carrier", 1, createdAt = staleCutoff)
        }

        GatewayRecoveryCoordinator.reconcileOnAppStart(context)

        SmsAttemptStore(context).use { store ->
            assertEquals(SmsAttemptStatus.UNKNOWN, store.listRecent().single().status)
        }
        context.deleteDatabase("almazen_sms_attempts.db")
    }
}

package com.almazen.sms

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.almazen.sms.data.RemoteCommandStatus
import com.almazen.sms.data.SmsAttemptStore
import com.almazen.sms.domain.SmsAttemptStatus
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import java.util.UUID

@RunWith(AndroidJUnit4::class)
class PackageUpdateReceiverTest {
    @Test
    fun interruptedRemoteAttemptBecomesUnknownWithoutClaimingSend() {
        // The package-replaced receiver is not invoked because it also schedules WorkManager.
        val context = ApplicationProvider.getApplicationContext<Context>()
        context.deleteDatabase("almazen_sms_attempts.db")
        val commandId = UUID.randomUUID().toString()
        val attemptId = UUID.randomUUID().toString()

        SmsAttemptStore(context).use { store ->
            assertTrue(store.saveRemoteCommand(commandId, "encrypted-destination", "encrypted-body"))
            assertTrue(store.markRemoteAccepted(commandId))
            store.createAttempt(attemptId, "•••• 567", 0, "Carrier", 1, remoteMessageId = commandId)
        }

        SmsAttemptStore(context).use { store ->
            assertEquals(1, store.markInterruptedAttemptsUnknown())
            assertEquals(SmsAttemptStatus.UNKNOWN, store.listRecent().single().status)
            val command = store.remoteCommand(commandId)
            assertEquals(RemoteCommandStatus.UNKNOWN, command?.status)
            assertEquals("unknown", command?.pendingReportStatus)
            assertTrue(store.pendingRemoteCommands().none { pending ->
                pending.id == commandId &&
                    (pending.status == RemoteCommandStatus.RECEIVED || pending.status == RemoteCommandStatus.ACCEPTED_BY_DEVICE)
            })
        }
        context.deleteDatabase("almazen_sms_attempts.db")
    }
}

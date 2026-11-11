package com.almazen.sms

import androidx.test.core.app.ApplicationProvider
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import com.almazen.sms.data.SmsAttemptStore
import com.almazen.sms.domain.SmsAttemptStatus
import com.almazen.sms.platform.BootRecoveryReceiver
import android.content.Intent
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.util.UUID

class SmsAttemptStoreTest {
    @Test
    fun persistsOnlyMaskedRecipientAndTransitionsFromSentToDelivered() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        context.deleteDatabase("almazen_sms_attempts.db")
        val id = UUID.randomUUID().toString()

        SmsAttemptStore(context).use { store ->
            store.createAttempt(id, "•••• 567", simSlot = 0, carrierName = "Carrier", partCount = 1)
            assertEquals(SmsAttemptStatus.SENDING, store.listRecent().single().status)
            store.recordSentPart(id, 0, true, null)
            assertEquals(SmsAttemptStatus.SENT, store.listRecent().single().status)
            store.recordDeliveryPart(id, 0, true)
            val delivered = store.listRecent().single()
            assertEquals(SmsAttemptStatus.DELIVERED, delivered.status)
            assertEquals("•••• 567", delivered.maskedRecipient)
            assertFalse(delivered.maskedRecipient.contains("1234"))
        }
        context.deleteDatabase("almazen_sms_attempts.db")
    }

    @Test
    fun missingSentCallbackCanBeMarkedUnknownWithoutRetry() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        context.deleteDatabase("almazen_sms_attempts.db")
        val id = UUID.randomUUID().toString()

        SmsAttemptStore(context).use { store ->
            store.createAttempt(id, "•••• 567", simSlot = 0, carrierName = "Carrier", partCount = 1, createdAt = 1L)
            store.markStaleAttemptsUnknown(cutoff = System.currentTimeMillis())
            val attempt = store.listRecent().single()
            assertEquals(SmsAttemptStatus.UNKNOWN, attempt.status)
            assertTrue(attempt.detail.orEmpty().contains("لا تعاود"))
            assertEquals("UNKNOWN_RECOVERY", store.listEvents(id).last().type)
        }
        context.deleteDatabase("almazen_sms_attempts.db")
    }

    @Test
    fun attemptAndEventHistorySurviveStoreRecreation() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        context.deleteDatabase("almazen_sms_attempts.db")
        val id = UUID.randomUUID().toString()
        SmsAttemptStore(context).use { it.createAttempt(id, "•••• 567", 0, "Carrier", 1) }
        SmsAttemptStore(context).use { store ->
            assertEquals(SmsAttemptStatus.SENDING, store.listRecent().single().status)
            assertEquals("CREATED", store.listEvents(id).single().type)
        }
        context.deleteDatabase("almazen_sms_attempts.db")
    }

    @Test
    fun upgradesExistingVersionOneDatabaseWithoutDroppingAttempts() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        context.deleteDatabase("almazen_sms_attempts.db")
        val file = context.getDatabasePath("almazen_sms_attempts.db")
        file.parentFile?.mkdirs()
        SQLiteDatabase.openOrCreateDatabase(file, null).use { db ->
            db.execSQL("CREATE TABLE attempts (id TEXT PRIMARY KEY NOT NULL, masked_recipient TEXT NOT NULL, status TEXT NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, sim_slot INTEGER NOT NULL, carrier_name TEXT NOT NULL, part_count INTEGER NOT NULL, detail TEXT)")
            db.execSQL("CREATE TABLE part_results (attempt_id TEXT NOT NULL, part_index INTEGER NOT NULL, sent_success INTEGER, delivered_success INTEGER, detail TEXT, PRIMARY KEY (attempt_id, part_index), FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE)")
            db.execSQL("CREATE INDEX attempts_created_at_idx ON attempts(created_at DESC)")
            db.version = 1
        }
        SmsAttemptStore(context).use { store ->
            val id = UUID.randomUUID().toString()
            store.createAttempt(id, "•••• 567", 0, "Carrier", 1)
            assertEquals("CREATED", store.listEvents(id).single().type)
        }
        context.deleteDatabase("almazen_sms_attempts.db")
    }

    @Test
    fun bootRecoveryMarksInterruptedSendUnknownWithoutRetrying() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        context.deleteDatabase("almazen_sms_attempts.db")
        val id = UUID.randomUUID().toString()
        SmsAttemptStore(context).use { it.createAttempt(id, "•••• 567", 0, "Carrier", 1) }

        BootRecoveryReceiver().onReceive(context, Intent(Intent.ACTION_BOOT_COMPLETED))

        SmsAttemptStore(context).use { store ->
            assertEquals(SmsAttemptStatus.UNKNOWN, store.listRecent().single().status)
            assertEquals("UNKNOWN_RECOVERY", store.listEvents(id).last().type)
        }
        context.deleteDatabase("almazen_sms_attempts.db")
    }

    @Test
    fun remoteCommandIsDurableAndInterruptedSendBecomesUnknownForReportingOnly() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        context.deleteDatabase("almazen_sms_attempts.db")
        val id = UUID.randomUUID().toString()
        val attemptId = UUID.randomUUID().toString()

        SmsAttemptStore(context).use { store ->
            assertTrue(store.saveRemoteCommand(id, "encrypted-destination", "encrypted-body"))
            assertFalse(store.saveRemoteCommand(id, "changed-value", "changed-value"))
            assertTrue(store.markRemoteAccepted(id))
            store.createAttempt(attemptId, "•••• 567", 0, "Carrier", 1, remoteMessageId = id)
        }

        SmsAttemptStore(context).use { store ->
            assertEquals("SENDING", store.remoteCommand(id)?.status)
            assertEquals(1, store.markInterruptedAttemptsUnknown())
            val command = store.pendingRemoteCommands().single()
            assertEquals("UNKNOWN", command.status)
            assertEquals("unknown", command.pendingReportStatus)
            assertTrue(command.encryptedDestination.isEmpty())
            assertTrue(command.encryptedBody.isEmpty())
        }
        context.deleteDatabase("almazen_sms_attempts.db")
    }

    @Test
    fun upgradesPhaseTwoDatabaseToGatewaySchemaWithoutDroppingHistory() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        context.deleteDatabase("almazen_sms_attempts.db")
        val file = context.getDatabasePath("almazen_sms_attempts.db")
        file.parentFile?.mkdirs()
        val oldAttemptId = UUID.randomUUID().toString()
        SQLiteDatabase.openOrCreateDatabase(file, null).use { db ->
            db.execSQL("CREATE TABLE attempts (id TEXT PRIMARY KEY NOT NULL, masked_recipient TEXT NOT NULL, status TEXT NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, sim_slot INTEGER NOT NULL, carrier_name TEXT NOT NULL, part_count INTEGER NOT NULL, detail TEXT)")
            db.execSQL("CREATE TABLE part_results (attempt_id TEXT NOT NULL, part_index INTEGER NOT NULL, sent_success INTEGER, delivered_success INTEGER, detail TEXT, PRIMARY KEY (attempt_id, part_index), FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE)")
            db.execSQL("CREATE INDEX attempts_created_at_idx ON attempts(created_at DESC)")
            db.execSQL("CREATE TABLE attempt_events (id INTEGER PRIMARY KEY AUTOINCREMENT, attempt_id TEXT NOT NULL, occurred_at INTEGER NOT NULL, event_type TEXT NOT NULL, detail TEXT, FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE)")
            db.execSQL("CREATE INDEX attempt_events_attempt_idx ON attempt_events(attempt_id, occurred_at)")
            db.execSQL("INSERT INTO attempts VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", arrayOf<Any?>(oldAttemptId, "•••• 567", "SENT", 10L, 11L, 0, "Carrier", 1, null))
            db.version = 2
        }

        SmsAttemptStore(context).use { store ->
            assertEquals(SmsAttemptStatus.SENT, store.listRecent().single().status)
            val remoteId = UUID.randomUUID().toString()
            assertTrue(store.saveRemoteCommand(remoteId, "encrypted-destination", "encrypted-body"))
            assertTrue(store.markRemoteAccepted(remoteId))
            store.createAttempt(UUID.randomUUID().toString(), "•••• 567", 0, "Carrier", 1, remoteMessageId = remoteId)
            assertEquals("SENDING", store.remoteCommand(remoteId)?.status)
        }
        context.deleteDatabase("almazen_sms_attempts.db")
    }
}

package com.almazen.sms.data

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper
import androidx.core.database.sqlite.transaction
import com.almazen.sms.domain.SmsAttempt
import com.almazen.sms.domain.SmsAttemptStatus
import com.almazen.sms.domain.SmsAttemptStatusReducer
import com.almazen.sms.domain.SmsPartResult
import com.almazen.sms.domain.SmsAttemptEvent

data class RemoteCommandRecord(
    val id: String,
    val encryptedDestination: String,
    val encryptedBody: String,
    val status: String,
    val attemptId: String?,
    val pendingReportStatus: String?,
    val sendAuthorizationRequired: Boolean,
)

object RemoteCommandStatus {
    const val RECEIVED = "RECEIVED"
    const val ACCEPTED_BY_DEVICE = "ACCEPTED_BY_DEVICE"
    const val SENDING = "SENDING"
    const val SENT = "SENT"
    const val FAILED = "FAILED"
    const val DELIVERED = "DELIVERED"
    const val UNKNOWN = "UNKNOWN"
}

class SmsAttemptStore(context: Context) : SQLiteOpenHelper(context, DATABASE_NAME, null, DATABASE_VERSION) {
    override fun onConfigure(db: SQLiteDatabase) {
        db.setForeignKeyConstraintsEnabled(true)
    }

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL(
            """CREATE TABLE attempts (
                id TEXT PRIMARY KEY NOT NULL,
                masked_recipient TEXT NOT NULL,
                status TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                sim_slot INTEGER NOT NULL,
                carrier_name TEXT NOT NULL,
                part_count INTEGER NOT NULL,
                remote_message_id TEXT,
                message_body TEXT,
                detail TEXT
            )""".trimIndent()
        )
        db.execSQL(
            """CREATE TABLE part_results (
                attempt_id TEXT NOT NULL,
                part_index INTEGER NOT NULL,
                sent_success INTEGER,
                delivered_success INTEGER,
                detail TEXT,
                PRIMARY KEY (attempt_id, part_index),
                FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE
            )""".trimIndent()
        )
        db.execSQL("CREATE INDEX attempts_created_at_idx ON attempts(created_at DESC)")
        createEventTable(db)
        createRemoteCommandTable(db)
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        if (oldVersion < 2) createEventTable(db)
        if (oldVersion < 3) {
            db.execSQL("ALTER TABLE attempts ADD COLUMN remote_message_id TEXT")
            createRemoteCommandTable(db)
        }
        if (oldVersion < 4) {
            db.execSQL("ALTER TABLE remote_commands ADD COLUMN send_authorization_required INTEGER NOT NULL DEFAULT 0")
        }
        if (oldVersion < 5) {
            db.execSQL("ALTER TABLE attempts ADD COLUMN message_body TEXT")
        }
    }

    private fun createEventTable(db: SQLiteDatabase) {
        db.execSQL("""CREATE TABLE attempt_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            attempt_id TEXT NOT NULL,
            occurred_at INTEGER NOT NULL,
            event_type TEXT NOT NULL,
            detail TEXT,
            FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE
        )""")
        db.execSQL("CREATE INDEX attempt_events_attempt_idx ON attempt_events(attempt_id, occurred_at)")
    }

    private fun createRemoteCommandTable(db: SQLiteDatabase) {
        db.execSQL("""CREATE TABLE remote_commands (
            id TEXT PRIMARY KEY NOT NULL,
            encrypted_destination TEXT NOT NULL,
            encrypted_body TEXT NOT NULL,
            status TEXT NOT NULL,
            attempt_id TEXT,
            pending_report_status TEXT,
            send_authorization_required INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )""".trimIndent())
        db.execSQL("CREATE INDEX remote_commands_status_idx ON remote_commands(status, updated_at)")
        db.execSQL("CREATE UNIQUE INDEX attempts_remote_message_idx ON attempts(remote_message_id) WHERE remote_message_id IS NOT NULL")
    }

    fun createAttempt(
        id: String,
        maskedRecipient: String,
        simSlot: Int,
        carrierName: String,
        partCount: Int,
        messageBody: String = "",
        remoteMessageId: String? = null,
        createdAt: Long = System.currentTimeMillis()
    ) {
        val db = writableDatabase
        db.transaction {
            val attempt = ContentValues().apply {
                put("id", id)
                put("masked_recipient", maskedRecipient)
                put("status", SmsAttemptStatus.SENDING.name)
                put("created_at", createdAt)
                put("updated_at", createdAt)
                put("sim_slot", simSlot)
                put("carrier_name", carrierName)
                put("part_count", partCount)
                put("message_body", messageBody.take(1600))
                if (remoteMessageId != null) put("remote_message_id", remoteMessageId)
            }
            db.insertOrThrow("attempts", null, attempt)
            if (remoteMessageId != null) {
                val linked = db.update("remote_commands", ContentValues().apply {
                    put("status", RemoteCommandStatus.SENDING)
                    put("attempt_id", id)
                    put("encrypted_destination", "")
                    put("encrypted_body", "")
                    put("updated_at", createdAt)
                }, "id = ? AND status = ? AND attempt_id IS NULL", arrayOf(remoteMessageId, RemoteCommandStatus.ACCEPTED_BY_DEVICE))
                check(linked == 1) { "Remote command is not in a sendable local state." }
            }
            repeat(partCount) { index ->
                insertOrThrow("part_results", null, ContentValues().apply {
                    put("attempt_id", id)
                    put("part_index", index)
                })
            }
            insertEvent(id, "CREATED", "حُفظت المحاولة قبل استدعاء نظام SMS.")
        }
    }

    fun recordSentPart(id: String, partIndex: Int, success: Boolean, detail: String?) =
        recordPartResult(id, partIndex, "sent_success", success, detail)

    fun recordDeliveryPart(id: String, partIndex: Int, delivered: Boolean) =
        recordPartResult(id, partIndex, "delivered_success", delivered, null)

    private fun recordPartResult(id: String, partIndex: Int, column: String, success: Boolean, detail: String?) {
        val db = writableDatabase
        db.transaction {
            val values = ContentValues().apply {
                put(column, if (success) 1 else 0)
                if (detail != null) put("detail", detail.take(160))
            }
            val updated = update("part_results", values, "attempt_id = ? AND part_index = ? AND $column IS NULL", arrayOf(id, partIndex.toString()))
            if (updated > 0) {
                insertEvent(id, if (column == "sent_success") if (success) "PART_SENT" else "PART_SEND_FAILED" else if (success) "PART_DELIVERED" else "PART_DELIVERY_FAILED", detail)
                updateAggregate(db, id)
                updateRemoteFromAttempt(db, id)
            }
        }
    }

    private fun updateAggregate(db: SQLiteDatabase, id: String) {
        val parts = mutableListOf<SmsPartResult>()
        db.query("part_results", arrayOf("sent_success", "delivered_success"), "attempt_id = ?", arrayOf(id), null, null, "part_index ASC").use { cursor ->
            while (cursor.moveToNext()) {
                parts += SmsPartResult(
                    sent = if (cursor.isNull(0)) null else cursor.getInt(0) == 1,
                    delivered = if (cursor.isNull(1)) null else cursor.getInt(1) == 1
                )
            }
        }
        val failureDetail = db.rawQuery(
            "SELECT detail FROM part_results WHERE attempt_id = ? AND detail IS NOT NULL LIMIT 1",
            arrayOf(id)
        ).use { cursor -> if (cursor.moveToFirst()) cursor.getString(0) else null }
        val status = SmsAttemptStatusReducer.reduce(parts)
        db.update("attempts", ContentValues().apply {
            put("status", status.name)
            put("updated_at", System.currentTimeMillis())
            if (failureDetail != null) put("detail", failureDetail)
        }, "id = ?", arrayOf(id))
    }

    fun allSentCallbacksReceived(id: String): Boolean = readableDatabase.rawQuery(
        "SELECT COUNT(*) FROM part_results WHERE attempt_id = ? AND sent_success IS NULL",
        arrayOf(id)
    ).use { cursor -> cursor.moveToFirst() && cursor.getInt(0) == 0 }

    fun markInvocationFailure(id: String, status: SmsAttemptStatus, detail: String) {
        val db = writableDatabase
        db.transaction {
            val changed = update("attempts", ContentValues().apply {
                put("status", status.name)
                put("detail", detail.take(160))
                put("updated_at", System.currentTimeMillis())
            }, "id = ? AND status = ?", arrayOf(id, SmsAttemptStatus.SENDING.name))
            if (changed > 0) insertEvent(id, status.name, detail.take(160))
            if (changed > 0) updateRemoteFromAttempt(this, id)
        }
    }

    fun markStaleAttemptsUnknown(cutoff: Long): Int {
        return markAttemptsUnknown("status = ? AND updated_at < ?", arrayOf(SmsAttemptStatus.SENDING.name, cutoff.toString()))
    }

    fun markInterruptedAttemptsUnknown(): Int {
        return markAttemptsUnknown("status = ?", arrayOf(SmsAttemptStatus.SENDING.name))
    }

    private fun markAttemptsUnknown(where: String, args: Array<String>): Int {
        val db = writableDatabase
        return db.transaction {
            val ids = mutableListOf<String>()
            query("attempts", arrayOf("id"), where, args, null, null, null).use { cursor ->
                while (cursor.moveToNext()) ids += cursor.getString(0)
            }
            val changed = update("attempts", ContentValues().apply {
            put("status", SmsAttemptStatus.UNKNOWN.name)
            put("detail", "لم يصل رد النظام ضمن المهلة؛ لا تعاود الإرسال تلقائياً.")
            put("updated_at", System.currentTimeMillis())
            }, where, args)
            ids.forEach { insertEvent(it, "UNKNOWN_RECOVERY", "استعيدت محاولة غير محسومة؛ لا إعادة إرسال تلقائية.") }
            ids.forEach { updateRemoteFromAttempt(this, it) }
            changed
        }
    }

    fun listEvents(attemptId: String): List<SmsAttemptEvent> = buildList {
        readableDatabase.query("attempt_events", arrayOf("occurred_at", "event_type", "detail"), "attempt_id = ?", arrayOf(attemptId), null, null, "occurred_at ASC").use { cursor ->
            while (cursor.moveToNext()) add(SmsAttemptEvent(attemptId, cursor.getLong(0), cursor.getString(1), cursor.getString(2)))
        }
    }

    private fun insertEvent(attemptId: String, type: String, detail: String?) {
        writableDatabase.insertOrThrow("attempt_events", null, ContentValues().apply {
            put("attempt_id", attemptId)
            put("occurred_at", System.currentTimeMillis())
            put("event_type", type.take(48))
            if (detail != null) put("detail", detail.take(160))
        })
    }

    fun listRecent(limit: Int = 50): List<SmsAttempt> {
        val attempts = mutableListOf<SmsAttempt>()
        readableDatabase.query("attempts", null, null, null, null, null, "created_at DESC", limit.toString()).use { cursor ->
            while (cursor.moveToNext()) {
                val bodyIndex = cursor.getColumnIndex("message_body")
                val storedBody = if (bodyIndex >= 0 && !cursor.isNull(bodyIndex)) cursor.getString(bodyIndex) else null
                attempts += SmsAttempt(
                    id = cursor.getString(cursor.getColumnIndexOrThrow("id")),
                    maskedRecipient = cursor.getString(cursor.getColumnIndexOrThrow("masked_recipient")),
                    messageBody = storedBody,
                    status = SmsAttemptStatus.valueOf(cursor.getString(cursor.getColumnIndexOrThrow("status"))),
                    createdAt = cursor.getLong(cursor.getColumnIndexOrThrow("created_at")),
                    simSlot = cursor.getInt(cursor.getColumnIndexOrThrow("sim_slot")),
                    carrierName = cursor.getString(cursor.getColumnIndexOrThrow("carrier_name")),
                    partCount = cursor.getInt(cursor.getColumnIndexOrThrow("part_count")),
                    detail = cursor.getString(cursor.getColumnIndexOrThrow("detail"))
                )
            }
        }
        return attempts
    }

    fun saveRemoteCommand(
        id: String,
        encryptedDestination: String,
        encryptedBody: String,
        sendAuthorizationRequired: Boolean = false,
        createdAt: Long = System.currentTimeMillis(),
    ): Boolean {
        val values = ContentValues().apply {
            put("id", id)
            put("encrypted_destination", encryptedDestination)
            put("encrypted_body", encryptedBody)
            put("status", RemoteCommandStatus.RECEIVED)
            put("send_authorization_required", if (sendAuthorizationRequired) 1 else 0)
            put("created_at", createdAt)
            put("updated_at", createdAt)
        }
        return writableDatabase.insertWithOnConflict("remote_commands", null, values, SQLiteDatabase.CONFLICT_IGNORE) != -1L
    }

    fun remoteCommand(id: String): RemoteCommandRecord? = readableDatabase.query(
        "remote_commands",
        arrayOf("id", "encrypted_destination", "encrypted_body", "status", "attempt_id", "pending_report_status", "send_authorization_required"),
        "id = ?", arrayOf(id), null, null, null
    ).use { cursor ->
        if (!cursor.moveToFirst()) null else RemoteCommandRecord(
            id = cursor.getString(0),
            encryptedDestination = cursor.getString(1),
            encryptedBody = cursor.getString(2),
            status = cursor.getString(3),
            attemptId = cursor.getString(4),
            pendingReportStatus = cursor.getString(5),
            sendAuthorizationRequired = cursor.getInt(6) == 1,
        )
    }

    fun pendingRemoteCommands(limit: Int = 20): List<RemoteCommandRecord> = buildList {
        readableDatabase.query(
            "remote_commands",
            arrayOf("id", "encrypted_destination", "encrypted_body", "status", "attempt_id", "pending_report_status", "send_authorization_required"),
            "status IN (?, ?) OR pending_report_status IS NOT NULL",
            arrayOf(RemoteCommandStatus.RECEIVED, RemoteCommandStatus.ACCEPTED_BY_DEVICE),
            null, null, "created_at ASC", limit.toString()
        ).use { cursor ->
            while (cursor.moveToNext()) {
                add(
                    RemoteCommandRecord(
                        cursor.getString(0),
                        cursor.getString(1),
                        cursor.getString(2),
                        cursor.getString(3),
                        cursor.getString(4),
                        cursor.getString(5),
                        cursor.getInt(6) == 1,
                    )
                )
            }
        }
    }

    fun hasPendingRemoteReports(): Boolean = readableDatabase.rawQuery(
        "SELECT EXISTS(SELECT 1 FROM remote_commands WHERE pending_report_status IS NOT NULL)", null
    ).use { cursor -> cursor.moveToFirst() && cursor.getInt(0) == 1 }

    fun markRemoteAccepted(id: String): Boolean = writableDatabase.transaction {
        val changed = update("remote_commands", ContentValues().apply {
            put("status", RemoteCommandStatus.ACCEPTED_BY_DEVICE)
            put("updated_at", System.currentTimeMillis())
        }, "id = ? AND status = ?", arrayOf(id, RemoteCommandStatus.RECEIVED))
        changed == 1 || remoteCommand(id)?.status == RemoteCommandStatus.ACCEPTED_BY_DEVICE
    }

    fun markRemoteReported(id: String, reportedStatus: String) {
        writableDatabase.update("remote_commands", ContentValues().apply {
            putNull("pending_report_status")
            put("updated_at", System.currentTimeMillis())
        }, "id = ? AND pending_report_status = ?", arrayOf(id, reportedStatus))
    }

    fun markRemoteStatus(id: String, status: String, pendingReportStatus: String? = null) {
        writableDatabase.update("remote_commands", ContentValues().apply {
            put("status", status)
            if (pendingReportStatus == null) putNull("pending_report_status") else put("pending_report_status", pendingReportStatus)
            put("updated_at", System.currentTimeMillis())
        }, "id = ?", arrayOf(id))
    }

    private fun updateRemoteFromAttempt(db: SQLiteDatabase, attemptId: String) {
        val remoteId = db.rawQuery("SELECT remote_message_id FROM attempts WHERE id = ?", arrayOf(attemptId)).use { cursor ->
            if (cursor.moveToFirst() && !cursor.isNull(0)) cursor.getString(0) else null
        } ?: return
        val attemptStatus = db.rawQuery("SELECT status FROM attempts WHERE id = ?", arrayOf(attemptId)).use { cursor ->
            if (cursor.moveToFirst()) cursor.getString(0) else return
        }
        val (remoteStatus, reportStatus) = when (attemptStatus) {
            SmsAttemptStatus.SENT.name -> RemoteCommandStatus.SENT to "sent_to_network"
            SmsAttemptStatus.SEND_FAILED.name -> RemoteCommandStatus.FAILED to "send_failed"
            SmsAttemptStatus.DELIVERED.name -> RemoteCommandStatus.DELIVERED to "delivery_reported"
            SmsAttemptStatus.DELIVERY_FAILED.name -> RemoteCommandStatus.FAILED to "delivery_failed"
            SmsAttemptStatus.UNKNOWN.name -> RemoteCommandStatus.UNKNOWN to "unknown"
            else -> return
        }
        db.update("remote_commands", ContentValues().apply {
            put("status", remoteStatus)
            put("pending_report_status", reportStatus)
            put("updated_at", System.currentTimeMillis())
        }, "id = ?", arrayOf(remoteId))
    }

    companion object {
        private const val DATABASE_NAME = "almazen_sms_attempts.db"
        private const val DATABASE_VERSION = 5
    }
}

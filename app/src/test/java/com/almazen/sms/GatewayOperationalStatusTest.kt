package com.almazen.sms

import com.almazen.sms.data.GatewaySettings
import com.almazen.sms.data.GatewaySyncStatus
import com.almazen.sms.device.GatewaySnapshot
import com.almazen.sms.device.InternetStatus
import com.almazen.sms.device.MobileServiceStatus
import com.almazen.sms.device.SimCardStatus
import com.almazen.sms.device.SimStatus
import com.almazen.sms.domain.GatewayOperationalPhase
import com.almazen.sms.domain.GatewayOperationalStatusEvaluator
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class GatewayOperationalStatusTest {
    private val readySim = SimStatus(1, 0, "Test", SimCardStatus.READY, MobileServiceStatus.IN_SERVICE)

    private fun snapshot(
        sendGranted: Boolean = true,
        phoneGranted: Boolean = true,
        internet: InternetStatus = InternetStatus.CONNECTED,
        sims: List<SimStatus> = listOf(readySim),
        smsFeatureAvailable: Boolean = true,
        simListReadable: Boolean = true
    ) = GatewaySnapshot(smsFeatureAvailable, sendGranted, phoneGranted, sims, internet, 80, true, "17", 37, simListReadable)

    private fun evaluateFresh(snapshot: GatewaySnapshot) = GatewayOperationalStatusEvaluator.evaluate(
        settings,
        GatewaySyncStatus("connected", 1_000L),
        snapshot,
        now = 1_000L
    )

    private val settings = GatewaySettings("https://api.example.com", "azm_device_" + "x".repeat(40), 1, 0, true)

    @Test
    fun pausedWhenGatewayDisabled() {
        val status = GatewayOperationalStatusEvaluator.evaluate(
            settings.copy(enabled = false),
            GatewaySyncStatus("connected", 0L),
            snapshot()
        )
        assertEquals(GatewayOperationalPhase.PAUSED, status.phase)
    }

    @Test
    fun blockedWhenSmsPermissionMissing() {
        val status = GatewayOperationalStatusEvaluator.evaluate(settings, GatewaySyncStatus("connected", 0L), snapshot(sendGranted = false))
        assertEquals(GatewayOperationalPhase.BLOCKED, status.phase)
    }

    @Test
    fun waitingWhenInternetUnavailable() {
        val status = GatewayOperationalStatusEvaluator.evaluate(settings, GatewaySyncStatus("connected", 0L), snapshot(internet = InternetStatus.DISCONNECTED))
        assertEquals(GatewayOperationalPhase.WAITING, status.phase)
    }

    @Test
    fun waitingWhenLastSyncIsStale() {
        val status = GatewayOperationalStatusEvaluator.evaluate(
            settings,
            GatewaySyncStatus("connected", 1L),
            snapshot(),
            now = 1L + (46 * 60 * 1000L)
        )
        assertEquals(GatewayOperationalPhase.WAITING, status.phase)
    }

    @Test
    fun readyWhenHealthy() {
        val status = GatewayOperationalStatusEvaluator.evaluate(settings, GatewaySyncStatus("connected", System.currentTimeMillis()), snapshot())
        assertEquals(GatewayOperationalPhase.READY, status.phase)
        assertTrue(status.recoverySteps.any { it.contains("UNKNOWN") })
    }

    @Test
    fun blockedWhenPhoneStatePermissionMissing() {
        val status = evaluateFresh(snapshot(phoneGranted = false))
        assertEquals(GatewayOperationalPhase.BLOCKED, status.phase)
    }

    @Test
    fun blockedWhenSavedSimNotListed() {
        val status = evaluateFresh(snapshot(sims = emptyList()))
        assertEquals(GatewayOperationalPhase.BLOCKED, status.phase)
    }

    @Test
    fun blockedWhenSelectedSimAbsent() {
        val status = evaluateFresh(snapshot(sims = listOf(readySim.copy(card = SimCardStatus.ABSENT))))
        assertEquals(GatewayOperationalPhase.BLOCKED, status.phase)
    }

    @Test
    fun blockedWhenNoCellularService() {
        listOf(
            MobileServiceStatus.OUT_OF_SERVICE,
            MobileServiceStatus.EMERGENCY_ONLY,
            MobileServiceStatus.POWER_OFF
        ).forEach { service ->
            val status = evaluateFresh(snapshot(sims = listOf(readySim.copy(service = service))))
            assertEquals(service.name, GatewayOperationalPhase.BLOCKED, status.phase)
        }
    }

    @Test
    fun waitingWhenInternetUnvalidated() {
        val status = evaluateFresh(snapshot(internet = InternetStatus.NO_INTERNET))
        assertEquals(GatewayOperationalPhase.WAITING, status.phase)
    }

    @Test
    fun waitingWhenInternetStatusUnknown() {
        val status = evaluateFresh(snapshot(internet = InternetStatus.UNKNOWN))
        assertEquals(GatewayOperationalPhase.WAITING, status.phase)
    }

    @Test
    fun waitingWhenSimCardUnknown() {
        val status = evaluateFresh(snapshot(sims = listOf(readySim.copy(card = SimCardStatus.UNKNOWN))))
        assertEquals(GatewayOperationalPhase.WAITING, status.phase)
        val copy = "${status.headline} ${status.detail}"
        assertFalse(copy.contains("لا توجد شريحة"))
        assertFalse(copy.contains("غائب"))
    }

    @Test
    fun waitingWhenCellularServiceUnknown() {
        val status = evaluateFresh(snapshot(sims = listOf(readySim.copy(service = MobileServiceStatus.UNKNOWN))))
        assertEquals(GatewayOperationalPhase.WAITING, status.phase)
        val copy = "${status.headline} ${status.detail}"
        assertFalse(copy.contains("غير متاحة"))
        assertFalse(copy.contains("خارج الخدمة"))
        assertFalse(copy.contains("لا يمكنه"))
    }

    @Test
    fun blockedWhenSmsFeatureUnavailable() {
        val status = evaluateFresh(snapshot(smsFeatureAvailable = false))
        assertEquals(GatewayOperationalPhase.BLOCKED, status.phase)
        val copy = "${status.headline} ${status.detail}"
        assertFalse(copy.contains("لا توجد شريحة"))
        assertFalse(copy.contains("غائب"))
    }

    @Test
    fun blockedWhenReadableSimListOmitsSavedSim() {
        val status = evaluateFresh(snapshot(sims = emptyList(), simListReadable = true))
        assertEquals(GatewayOperationalPhase.BLOCKED, status.phase)
        assertEquals("الشريحة المحفوظة غير ظاهرة", status.headline)
    }

    @Test
    fun waitingWhenSavedSimMissingBecauseListWasNotRead() {
        val status = evaluateFresh(snapshot(sims = emptyList(), simListReadable = false))
        assertEquals(GatewayOperationalPhase.WAITING, status.phase)
        assertEquals("تعذر قراءة قائمة الشرائح", status.headline)
        assertFalse(status.detail.contains("أُزيلت"))
        assertFalse(status.detail.contains("غير ظاهرة"))
        assertFalse(status.detail.contains("لا يعرض الشريحة"))
    }
}

package com.almazen.sms

import com.almazen.sms.device.MobileServiceStatus
import com.almazen.sms.domain.CellularServiceRead
import com.almazen.sms.domain.GatewayOperationalPhase
import com.almazen.sms.domain.GatewayOperationalStatusEvaluator
import com.almazen.sms.data.GatewaySettings
import com.almazen.sms.data.GatewaySyncStatus
import com.almazen.sms.device.GatewaySnapshot
import com.almazen.sms.device.InternetStatus
import com.almazen.sms.device.SimCardStatus
import com.almazen.sms.device.SimStatus
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Test

class CellularServiceReadTest {
    @Test
    fun android10ReportedInServiceIsNotConfirmed() {
        listOf(26, 29, 30, 31, 32).forEach { api ->
            val status = CellularServiceRead.interpret(
                apiLevel = api,
                locationFreeApi = false,
                reportedState = CellularServiceRead.STATE_IN_SERVICE
            )
            assertEquals(api.toString(), MobileServiceStatus.UNKNOWN, status)
        }
    }

    @Test
    fun android10LocationFreeFlagDoesNotConfirmInService() {
        val status = CellularServiceRead.interpret(
            apiLevel = CellularServiceRead.LOCATION_FREE_SERVICE_STATE_API - 4,
            locationFreeApi = true,
            reportedState = CellularServiceRead.STATE_IN_SERVICE
        )
        assertEquals(MobileServiceStatus.UNKNOWN, status)
    }

    @Test
    fun android10NullOrUnknownCodeStaysUnknown() {
        assertEquals(
            MobileServiceStatus.UNKNOWN,
            CellularServiceRead.interpret(29, locationFreeApi = false, reportedState = null)
        )
        assertEquals(
            MobileServiceStatus.UNKNOWN,
            CellularServiceRead.interpret(29, locationFreeApi = false, reportedState = 99)
        )
    }

    @Test
    fun android10ExplicitOutageStatesStayExplicit() {
        assertEquals(
            MobileServiceStatus.OUT_OF_SERVICE,
            CellularServiceRead.interpret(29, false, CellularServiceRead.STATE_OUT_OF_SERVICE)
        )
        assertEquals(
            MobileServiceStatus.EMERGENCY_ONLY,
            CellularServiceRead.interpret(29, false, CellularServiceRead.STATE_EMERGENCY_ONLY)
        )
        assertEquals(
            MobileServiceStatus.POWER_OFF,
            CellularServiceRead.interpret(29, false, CellularServiceRead.STATE_POWER_OFF)
        )
    }

    @Test
    fun api33LocationFreeInServiceIsAccepted() {
        listOf(33, 36, 37).forEach { api ->
            val status = CellularServiceRead.interpret(
                apiLevel = api,
                locationFreeApi = true,
                reportedState = CellularServiceRead.STATE_IN_SERVICE
            )
            assertEquals(api.toString(), MobileServiceStatus.IN_SERVICE, status)
        }
    }

    @Test
    fun api33WithoutLocationFreeCallDoesNotAcceptInService() {
        val status = CellularServiceRead.interpret(
            apiLevel = 37,
            locationFreeApi = false,
            reportedState = CellularServiceRead.STATE_IN_SERVICE
        )
        assertEquals(MobileServiceStatus.UNKNOWN, status)
    }

    @Test
    fun api33NullReadStaysUnknown() {
        assertEquals(
            MobileServiceStatus.UNKNOWN,
            CellularServiceRead.interpret(33, locationFreeApi = true, reportedState = null)
        )
    }

    @Test
    fun unconfirmedAndroid10ServiceIsWaitingNotReady() {
        val service = CellularServiceRead.interpret(
            apiLevel = 29,
            locationFreeApi = false,
            reportedState = CellularServiceRead.STATE_IN_SERVICE
        )
        val sim = SimStatus(1, 0, "Test", SimCardStatus.READY, service)
        val snapshot = GatewaySnapshot(
            smsFeatureAvailable = true,
            sendSmsPermissionGranted = true,
            phoneStatePermissionGranted = true,
            sims = listOf(sim),
            internet = InternetStatus.CONNECTED,
            batteryPercent = 80,
            charging = true,
            osVersion = "10",
            apiLevel = 29,
            simListReadable = true
        )
        val status = GatewayOperationalStatusEvaluator.evaluate(
            GatewaySettings("https://api.example.com", "azm_device_" + "x".repeat(40), 1, 0, true),
            GatewaySyncStatus("connected", 1_000L),
            snapshot,
            now = 1_000L
        )
        assertEquals(GatewayOperationalPhase.WAITING, status.phase)
        assertNotEquals(GatewayOperationalPhase.READY, status.phase)
    }
}

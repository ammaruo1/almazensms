package com.almazen.sms

import com.almazen.sms.domain.BackgroundExecutionPolicy
import com.almazen.sms.domain.BackgroundExecutionPolicy.GatewaySyncStrategy
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.junit.runners.Parameterized

@RunWith(Parameterized::class)
class BackgroundExecutionPolicyTest(private val apiLevel: Int) {
    @Test
    fun supportsBaselineAndCurrentTargetLevels() {
        assertTrue(BackgroundExecutionPolicy.isSupportedApiLevel(apiLevel))
        assertEquals(GatewaySyncStrategy.WORK_MANAGER, BackgroundExecutionPolicy.chosenSyncStrategy(apiLevel))
        assertFalse(BackgroundExecutionPolicy.usesAlwaysOnForegroundService(apiLevel))
    }

    @Test
    fun expeditedWorkMatchesAndroid12Split() {
        val expedited = BackgroundExecutionPolicy.canRequestExpeditedWork(apiLevel)
        if (apiLevel >= BackgroundExecutionPolicy.ANDROID_12_API) {
            assertTrue(expedited)
        } else {
            assertFalse(expedited)
        }
    }

    @Test
    fun foregroundServiceRulesDifferByGeneration() {
        assertEquals(
            apiLevel >= BackgroundExecutionPolicy.ANDROID_12_API,
            BackgroundExecutionPolicy.backgroundForegroundServiceStartRestricted(apiLevel)
        )
        assertEquals(
            apiLevel >= BackgroundExecutionPolicy.ANDROID_15_API,
            BackgroundExecutionPolicy.dataSyncForegroundServiceTimeoutApplies(apiLevel)
        )
        assertTrue(BackgroundExecutionPolicy.forceStopSuppressesBootRecoveryUntilUserLaunch(apiLevel))
    }

    @Test
    fun periodicFloorIsFifteenMinutesEverywhere() {
        assertEquals(15, BackgroundExecutionPolicy.minimumPeriodicIntervalMinutes(apiLevel))
    }

    @Test
    fun postNotificationsOnlyFromApi33() {
        assertEquals(
            apiLevel >= BackgroundExecutionPolicy.ANDROID_13_API,
            BackgroundExecutionPolicy.requiresPostNotificationsPermission(apiLevel)
        )
    }

    @Test
    fun limitationsAreNonEmptyAndDocumentNoUptimeGuarantee() {
        val limits = BackgroundExecutionPolicy.operationalLimitations(apiLevel)
        assertTrue(limits.isNotEmpty())
        assertTrue(limits.any { it.contains("force-stop") || it.contains("إيقاف التطبيق") })
        assertTrue(limits.any { it.contains("24") || it.contains("متواصل") })
        assertTrue(limits.any { it.contains("لا تحتاج البوابة إلى إبقاء الشاشة مضاءة") })
        assertTrue(limits.none { it.contains("كل 5 ثوانٍ") })
    }

    companion object {
        @JvmStatic
        @Parameterized.Parameters(name = "API {0}")
        fun apiLevels(): List<Int> = listOf(
            BackgroundExecutionPolicy.MIN_APP_API,
            BackgroundExecutionPolicy.ANDROID_10_API,
            30,
            BackgroundExecutionPolicy.ANDROID_12_API,
            BackgroundExecutionPolicy.ANDROID_13_API,
            34,
            BackgroundExecutionPolicy.ANDROID_15_API,
            36,
            37
        )
    }
}

package com.almazen.sms

import android.os.Build
import androidx.test.core.app.ApplicationProvider
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.almazen.sms.domain.BackgroundExecutionPolicy
import com.almazen.sms.worker.GatewayWorkScheduler
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Assume.assumeTrue
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Device/emulator checks for WorkManager scheduling across API 29+.
 * Not executed in the default agent round unless the suite is run locally/CI.
 */
@RunWith(AndroidJUnit4::class)
class GatewayWorkSchedulerApiTest {
    @Test
    fun deviceApiIsWithinSupportedRange() {
        assertTrue(BackgroundExecutionPolicy.isSupportedApiLevel(Build.VERSION.SDK_INT))
    }

    @Test
    fun enqueueNowDoesNotThrowOnSupportedApi() {
        assumeTrue(Build.VERSION.SDK_INT >= BackgroundExecutionPolicy.ANDROID_10_API)
        val context = ApplicationProvider.getApplicationContext<android.content.Context>()
        GatewayWorkScheduler.disable(context)
        GatewayWorkScheduler.enqueueNow(context)
    }

    @Test
    fun expeditedEligibilityMatchesRuntimeApi() {
        val expected = BackgroundExecutionPolicy.canRequestExpeditedWork(Build.VERSION.SDK_INT)
        assertEquals(expected, Build.VERSION.SDK_INT >= BackgroundExecutionPolicy.ANDROID_12_API)
    }
}

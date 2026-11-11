package com.almazen.sms

import com.almazen.sms.domain.BackgroundExecutionPolicy
import org.junit.Assert.assertFalse
import org.junit.Assert.assertThrows
import org.junit.Test

class BackgroundExecutionPolicyMinSdkTest {
    @Test
    fun strategyRejectsApiBelowMinSdk() {
        assertFalse(BackgroundExecutionPolicy.isSupportedApiLevel(25))
        assertThrows(IllegalArgumentException::class.java) {
            BackgroundExecutionPolicy.chosenSyncStrategy(25)
        }
    }
}

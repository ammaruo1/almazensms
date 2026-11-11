package com.almazen.sms

import androidx.compose.ui.platform.ComposeView
import androidx.test.core.app.ActivityScenario
import androidx.test.ext.junit.runners.AndroidJUnit4
import android.view.ViewGroup
import android.R
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class MainActivityLaunchTest {
    @Test
    fun launchesComposeScreenWithoutRequestingSmsPermission() {
        ActivityScenario.launch(MainActivity::class.java).use { scenario ->
            scenario.onActivity { activity ->
                val content = activity.findViewById<ViewGroup>(R.id.content)
                assertFalse(activity.isFinishing)
                assertTrue(content.getChildAt(0) is ComposeView)
            }
        }
    }
}

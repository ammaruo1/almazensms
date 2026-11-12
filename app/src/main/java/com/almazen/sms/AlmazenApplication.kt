package com.almazen.sms

import android.app.Application
import com.almazen.sms.platform.GatewayPush

class AlmazenApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        GatewayPush.initialize(this)
    }
}

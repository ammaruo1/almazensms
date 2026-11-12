package com.almazen.sms.platform

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.util.Log
import com.almazen.sms.data.GatewayCredentialStore
import com.almazen.sms.worker.GatewayWorkScheduler

/** Nudges WorkManager when validated Internet returns while the app process is alive. */
object GatewaySyncMonitor {
    private const val TAG = "AlmazenGatewaySyncMon"
    private const val MIN_NUDGE_INTERVAL_MS = 60_000L
    @Volatile private var registered = false
    @Volatile private var lastNudgeElapsed = 0L
    private var callback: ConnectivityManager.NetworkCallback? = null

    fun nudgeIfEnabled(context: Context) {
        val appContext = context.applicationContext
        val enabled = runCatching { GatewayCredentialStore(appContext).load()?.enabled == true }.getOrDefault(false)
        if (!enabled) return
        val now = android.os.SystemClock.elapsedRealtime()
        synchronized(this) {
            if (now - lastNudgeElapsed < MIN_NUDGE_INTERVAL_MS) return
            lastNudgeElapsed = now
        }
        nudgeWorkManager(appContext)
    }

    fun startIfNeeded(context: Context) {
        val appContext = context.applicationContext
        val enabled = runCatching { GatewayCredentialStore(appContext).load()?.enabled == true }.getOrDefault(false)
        if (!enabled) {
            stop(appContext)
            return
        }
        if (registered) return
        val manager = appContext.getSystemService(ConnectivityManager::class.java) ?: return
        val networkCallback = object : ConnectivityManager.NetworkCallback() {
            override fun onCapabilitiesChanged(network: Network, networkCapabilities: NetworkCapabilities) {
                if (hasValidatedInternet(networkCapabilities)) nudgeIfEnabled(appContext)
            }

            override fun onAvailable(network: Network) {
                val capabilities = manager.getNetworkCapabilities(network) ?: return
                if (hasValidatedInternet(capabilities)) nudgeIfEnabled(appContext)
            }
        }
        runCatching {
            manager.registerDefaultNetworkCallback(networkCallback)
            callback = networkCallback
            registered = true
        }.onFailure {
            Log.w(TAG, "Could not register connectivity nudge")
            callback = null
            registered = false
        }
    }

    fun stop(context: Context) {
        if (!registered) return
        val manager = context.applicationContext.getSystemService(ConnectivityManager::class.java)
        callback?.let { runCatching { manager?.unregisterNetworkCallback(it) } }
        callback = null
        registered = false
    }

    private fun hasValidatedInternet(capabilities: NetworkCapabilities): Boolean =
        capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
            capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)

    private fun nudgeWorkManager(context: Context) {
        runCatching { GatewayWorkScheduler.enqueueNow(context) }
            .onFailure { Log.w(TAG, "Connectivity nudge could not enqueue sync") }
    }
}

package com.almazen.sms.device

import android.annotation.SuppressLint
import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Build
import android.telephony.ServiceState
import android.telephony.SmsManager
import android.telephony.SubscriptionInfo
import android.telephony.SubscriptionManager
import android.telephony.TelephonyManager
import androidx.core.content.ContextCompat
import com.almazen.sms.domain.sanitizedCarrierName

data class SimStatus(
    val subscriptionId: Int,
    val slot: Int,
    val carrier: String,
    val card: SimCardStatus,
    val service: MobileServiceStatus
)

enum class MobileServiceStatus { IN_SERVICE, OUT_OF_SERVICE, EMERGENCY_ONLY, POWER_OFF, UNKNOWN }
enum class SimCardStatus { READY, NOT_READY, ABSENT, PIN_REQUIRED, PUK_REQUIRED, NETWORK_LOCKED, DISABLED, ERROR, UNKNOWN }

enum class InternetStatus { CONNECTED, NO_INTERNET, DISCONNECTED, UNKNOWN }

data class GatewaySnapshot(
    val smsFeatureAvailable: Boolean,
    val sendSmsPermissionGranted: Boolean,
    val phoneStatePermissionGranted: Boolean,
    val sims: List<SimStatus>,
    val internet: InternetStatus,
    val batteryPercent: Int?,
    val charging: Boolean?,
    val osVersion: String,
    val apiLevel: Int
)

class GatewayDiagnostics(private val context: Context) {
    fun snapshot(): GatewaySnapshot {
        val smsFeature = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            context.packageManager.hasSystemFeature(PackageManager.FEATURE_TELEPHONY_MESSAGING)
        } else {
            context.packageManager.hasSystemFeature(PackageManager.FEATURE_TELEPHONY)
        }
        val hasPhoneState = ContextCompat.checkSelfPermission(context, Manifest.permission.READ_PHONE_STATE) == PackageManager.PERMISSION_GRANTED
        val activeSims = if (hasPhoneState && context.packageManager.hasSystemFeature(PackageManager.FEATURE_TELEPHONY_SUBSCRIPTION)) {
            runCatching { activeSubscriptions() }.getOrDefault(emptyList())
        } else emptyList()
        val battery = readBatteryStatus()

        return GatewaySnapshot(
            smsFeatureAvailable = smsFeature,
            sendSmsPermissionGranted = ContextCompat.checkSelfPermission(context, Manifest.permission.SEND_SMS) == PackageManager.PERMISSION_GRANTED,
            phoneStatePermissionGranted = hasPhoneState,
            sims = activeSims,
            internet = readInternetStatus(),
            batteryPercent = battery?.first,
            charging = battery?.second,
            osVersion = Build.VERSION.RELEASE ?: "—",
            apiLevel = Build.VERSION.SDK_INT
        )
    }

    private fun activeSubscriptions(): List<SimStatus> {
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.READ_PHONE_STATE) != PackageManager.PERMISSION_GRANTED) {
            return emptyList()
        }
        val manager = context.getSystemService(SubscriptionManager::class.java) ?: return emptyList()
        val subscriptions = try {
            manager.activeSubscriptionInfoList.orEmpty()
        } catch (_: SecurityException) {
            return emptyList()
        }
        return subscriptions.map { info ->
            val telephony = context.getSystemService(TelephonyManager::class.java)
            val cardState = try {
                telephony?.getSimState(info.simSlotIndex)?.toCardStatus() ?: SimCardStatus.UNKNOWN
            } catch (_: RuntimeException) {
                SimCardStatus.UNKNOWN
            }
            SimStatus(
                subscriptionId = info.subscriptionId,
                slot = info.simSlotIndex,
                carrier = info.carrierName?.toString().orEmpty().sanitizedCarrierName(),
                card = cardState,
                service = readServiceState(info)
            )
        }
    }

    @SuppressLint("MissingPermission") // INCLUDE_LOCATION_DATA_NONE deliberately avoids requesting location access.
    private fun readServiceState(info: SubscriptionInfo): MobileServiceStatus = try {
        val base = context.getSystemService(TelephonyManager::class.java)
        val telephony = base?.createForSubscriptionId(info.subscriptionId) ?: return MobileServiceStatus.UNKNOWN
        val serviceState = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            telephony.getServiceState(TelephonyManager.INCLUDE_LOCATION_DATA_NONE)
        } else {
            @Suppress("DEPRECATION")
            telephony.serviceState
        }
        val state = serviceState?.state
        when (state) {
            ServiceState.STATE_IN_SERVICE -> MobileServiceStatus.IN_SERVICE
            ServiceState.STATE_OUT_OF_SERVICE -> MobileServiceStatus.OUT_OF_SERVICE
            ServiceState.STATE_EMERGENCY_ONLY -> MobileServiceStatus.EMERGENCY_ONLY
            ServiceState.STATE_POWER_OFF -> MobileServiceStatus.POWER_OFF
            else -> MobileServiceStatus.UNKNOWN
        }
    } catch (_: SecurityException) {
        MobileServiceStatus.UNKNOWN
    } catch (_: UnsupportedOperationException) {
        MobileServiceStatus.UNKNOWN
    }

    private fun Int.toCardStatus(): SimCardStatus = when (this) {
        TelephonyManager.SIM_STATE_READY -> SimCardStatus.READY
        TelephonyManager.SIM_STATE_NOT_READY -> SimCardStatus.NOT_READY
        TelephonyManager.SIM_STATE_ABSENT -> SimCardStatus.ABSENT
        TelephonyManager.SIM_STATE_PIN_REQUIRED -> SimCardStatus.PIN_REQUIRED
        TelephonyManager.SIM_STATE_PUK_REQUIRED -> SimCardStatus.PUK_REQUIRED
        TelephonyManager.SIM_STATE_NETWORK_LOCKED -> SimCardStatus.NETWORK_LOCKED
        TelephonyManager.SIM_STATE_PERM_DISABLED, TelephonyManager.SIM_STATE_CARD_RESTRICTED -> SimCardStatus.DISABLED
        TelephonyManager.SIM_STATE_CARD_IO_ERROR -> SimCardStatus.ERROR
        else -> SimCardStatus.UNKNOWN
    }

    private fun readInternetStatus(): InternetStatus = try {
        val manager = context.getSystemService(ConnectivityManager::class.java) ?: return InternetStatus.UNKNOWN
        val network = manager.activeNetwork ?: return InternetStatus.DISCONNECTED
        val capabilities = manager.getNetworkCapabilities(network) ?: return InternetStatus.UNKNOWN
        if (capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
            capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
        ) InternetStatus.CONNECTED else InternetStatus.NO_INTERNET
    } catch (_: SecurityException) {
        InternetStatus.UNKNOWN
    }

    private fun readBatteryStatus(): Pair<Int, Boolean>? = try {
        val intent = context.registerReceiver(null, IntentFilter(Intent.ACTION_BATTERY_CHANGED)) ?: return null
        val level = intent.getIntExtra("level", -1)
        val scale = intent.getIntExtra("scale", -1)
        val status = intent.getIntExtra("status", -1)
        val percent = if (level >= 0 && scale > 0) (level * 100 / scale).coerceIn(0, 100) else return null
        val charging = status == android.os.BatteryManager.BATTERY_STATUS_CHARGING ||
            status == android.os.BatteryManager.BATTERY_STATUS_FULL
        percent to charging
    } catch (_: RuntimeException) {
        null
    }
}

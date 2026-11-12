package com.almazen.sms.domain

import com.almazen.sms.device.MobileServiceStatus

/**
 * Maps a telephony service-state integer without treating an unverified read as in service.
 *
 * [STATE_IN_SERVICE] is 0, the same value as an unset integer. `TelephonyManager.getServiceState(int)`
 * with `INCLUDE_LOCATION_DATA_NONE` exists only from API 33. This app does not request location
 * permission. On Android 10 (API 29) through API 32 the deprecated no-arg read can throw, return
 * null, or report 0 without a location-free query. That 0 is not accepted as [MobileServiceStatus.IN_SERVICE].
 * Explicit outage values are still passed through. This mapping was not executed on an Android 10
 * device or on SM-S928U.
 */
object CellularServiceRead {
    /** Matches `android.telephony.ServiceState.STATE_IN_SERVICE`. */
    const val STATE_IN_SERVICE = 0
    /** Matches `android.telephony.ServiceState.STATE_OUT_OF_SERVICE`. */
    const val STATE_OUT_OF_SERVICE = 1
    /** Matches `android.telephony.ServiceState.STATE_EMERGENCY_ONLY`. */
    const val STATE_EMERGENCY_ONLY = 2
    /** Matches `android.telephony.ServiceState.STATE_POWER_OFF`. */
    const val STATE_POWER_OFF = 3

    /** First API that exposes `getServiceState(INCLUDE_LOCATION_DATA_NONE)`. */
    const val LOCATION_FREE_SERVICE_STATE_API = 33

    fun interpret(apiLevel: Int, locationFreeApi: Boolean, reportedState: Int?): MobileServiceStatus {
        if (reportedState == null) return MobileServiceStatus.UNKNOWN
        val mapped = when (reportedState) {
            STATE_IN_SERVICE -> MobileServiceStatus.IN_SERVICE
            STATE_OUT_OF_SERVICE -> MobileServiceStatus.OUT_OF_SERVICE
            STATE_EMERGENCY_ONLY -> MobileServiceStatus.EMERGENCY_ONLY
            STATE_POWER_OFF -> MobileServiceStatus.POWER_OFF
            else -> MobileServiceStatus.UNKNOWN
        }
        val inServiceConfirmed = locationFreeApi && apiLevel >= LOCATION_FREE_SERVICE_STATE_API
        if (mapped == MobileServiceStatus.IN_SERVICE && !inServiceConfirmed) {
            return MobileServiceStatus.UNKNOWN
        }
        return mapped
    }
}

package com.almazen.sms.domain

/**
 * Version-aware rules for gateway sync and recovery without claiming 24/7 uptime.
 * All methods take an explicit API level so JVM tests can cover Android 10 (29) through current releases.
 */
object BackgroundExecutionPolicy {
    const val MIN_APP_API = 26
    const val ANDROID_10_API = 29
    const val ANDROID_12_API = 31
    const val ANDROID_13_API = 33
    const val ANDROID_15_API = 35

    enum class GatewaySyncStrategy {
        /** Periodic and one-off sync via WorkManager; official deferrable path on all supported APIs. */
        WORK_MANAGER,
        /** Not used: an always-on foreground service would not be truthful on modern Android. */
        FOREGROUND_SERVICE_ALWAYS_ON
    }

    fun isSupportedApiLevel(apiLevel: Int): Boolean = apiLevel >= MIN_APP_API

    fun chosenSyncStrategy(apiLevel: Int): GatewaySyncStrategy {
        require(isSupportedApiLevel(apiLevel)) { "API $apiLevel is below minSdk $MIN_APP_API" }
        return GatewaySyncStrategy.WORK_MANAGER
    }

    fun usesAlwaysOnForegroundService(apiLevel: Int): Boolean {
        require(isSupportedApiLevel(apiLevel))
        return false
    }

    /** POST_NOTIFICATIONS exists only from API 33; we do not run an FGS, so this is unused today. */
    fun requiresPostNotificationsPermission(apiLevel: Int): Boolean =
        apiLevel >= ANDROID_13_API

    /**
     * WorkManager periodic interval floor (minutes). Same on API 29 and newer; OEM/Doze may delay further.
     */
    fun minimumPeriodicIntervalMinutes(apiLevel: Int): Int {
        require(isSupportedApiLevel(apiLevel))
        return 15
    }

    /**
     * Expedited WM requests (quota-limited) are available from API 31; older devices use normal one-time work.
     */
    fun canRequestExpeditedWork(apiLevel: Int): Boolean = apiLevel >= ANDROID_12_API

    /**
     * Starting a foreground service from the background is restricted from API 31 (with limited exceptions).
     * FGS for gateway sync is started from foreground user actions; background starts remain restricted.
     */
    fun backgroundForegroundServiceStartRestricted(apiLevel: Int): Boolean = apiLevel >= ANDROID_12_API

    /** `dataSync` FGS cumulative time limits apply from Android 15 / API 35 when targeting current SDKs. */
    fun dataSyncForegroundServiceTimeoutApplies(apiLevel: Int): Boolean = apiLevel >= ANDROID_15_API

    /**
     * After the user force-stops the package, BOOT_COMPLETED and implicit work resumption do not run until launch.
     */
    fun forceStopSuppressesBootRecoveryUntilUserLaunch(apiLevel: Int): Boolean {
        require(isSupportedApiLevel(apiLevel))
        return true
    }

    fun operationalLimitations(apiLevel: Int): List<String> {
        require(isSupportedApiLevel(apiLevel))
        val shared = mutableListOf(
            "لا تحتاج البوابة إلى إبقاء الشاشة مضاءة. FCM هو المسار السريع عند إعداده، وقد يتأخر أو لا يصل.",
            "WorkManager هو المسار الاحتياطي؛ الحد الأدنى للدورية 15 دقيقة، وقد يتأخر أكثر مع Doze أو سياسة الشركة المصنّعة.",
            "إيقاف التطبيق إجبارياً (force-stop) يوقف المزامنة حتى يفتح المستخدم التطبيق مرة أخرى.",
            "لا يوجد ضمان لإرسال فوري بالمللي ثانية أو تشغيل متواصل 24/7."
        )
        if (apiLevel < ANDROID_12_API) {
            shared += "على Android 10–11 لا يُستخدم العمل المُعجَّل في WorkManager؛ الفحص الفوري يعتمد على الجدولة العادية."
        }
        if (apiLevel >= ANDROID_12_API) {
            shared += "على Android 12+ تُقيَّد بدء خدمات أمامية من الخلفية؛ لذلك لا نعتمد على خدمة أمامية دائمة للمزامنة."
        }
        return shared
    }
}

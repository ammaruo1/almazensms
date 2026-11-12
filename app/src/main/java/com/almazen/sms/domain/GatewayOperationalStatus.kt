package com.almazen.sms.domain

import com.almazen.sms.data.GatewaySettings
import com.almazen.sms.data.GatewaySyncStatus
import com.almazen.sms.device.GatewaySnapshot
import com.almazen.sms.device.InternetStatus
import com.almazen.sms.device.MobileServiceStatus
import com.almazen.sms.device.SimCardStatus

/** User-facing gateway readiness derived from configuration, diagnostics, and last worker sync. */
enum class GatewayOperationalPhase {
    NOT_CONFIGURED,
    PAUSED,
    BLOCKED,
    WAITING,
    READY
}

data class GatewayOperationalStatus(
    val phase: GatewayOperationalPhase,
    val headline: String,
    val detail: String,
    val recoverySteps: List<String>
)

object GatewayOperationalStatusEvaluator {
    fun evaluate(
        settings: GatewaySettings?,
        syncStatus: GatewaySyncStatus,
        snapshot: GatewaySnapshot,
        now: Long = System.currentTimeMillis()
    ): GatewayOperationalStatus {
        if (settings == null) {
            return GatewayOperationalStatus(
                phase = GatewayOperationalPhase.NOT_CONFIGURED,
                headline = "البوابة غير مربوطة",
                detail = "احفظ اتصال HTTPS ومفتاح الجهاز قبل تفعيل الاستقبال الآلي.",
                recoverySteps = listOf(
                    "أدخل نطاق API الصحيح واختبر الاتصال.",
                    "احفظ مفتاح الجهاز الذي يظهر مرة واحدة من لوحة التحكم."
                )
            )
        }
        if (!settings.enabled) {
            return GatewayOperationalStatus(
                phase = GatewayOperationalPhase.PAUSED,
                headline = "استقبال طلبات الخادم متوقف",
                detail = "لن يطالب التطبيق الخادم برسائل جديدة حتى تعيد التفعيل يدوياً.",
                recoverySteps = listOf("افتح إعداد الخادم واختر تفعيل استقبال طلبات API بعد مراجعة الشريحة والأذونات.")
            )
        }
        when (syncStatus.state) {
            "credential_rejected" -> return blocked(
                headline = "بيانات الجهاز مرفوضة",
                detail = "أوقف النظام الاستقبال تلقائياً بعد رفض الخادم للاعتماد.",
                steps = listOf(
                    "أصدر مفتاح جهاز جديد من لوحة التحكم.",
                    "أعد الربط في التطبيق ثم فعّل الاستقبال بعد الاختبار."
                )
            )
        }
        if (!snapshot.sendSmsPermissionGranted) {
            return blocked(
                headline = "إذن إرسال SMS غير متاح",
                detail = "Android يمنع إرسال رسائل API بدون إذن SEND_SMS.",
                steps = listOf(
                    "من إعدادات التطبيق في Android، فعّل إذن الرسائل.",
                    "أعد فتح التطبيق وتحقق من مؤشر الإذن قبل تفعيل الاستقبال."
                )
            )
        }
        if (!snapshot.phoneStatePermissionGranted) {
            return blocked(
                headline = "لا يمكن تأكيد الشريحة المختارة",
                detail = "إذن حالة الهاتف مطلوب لعرض الشرائح النشطة والتحقق من الشريحة المحفوظة.",
                steps = listOf("امنح إذن حالة الهاتف من شاشة الإرسال أو إعدادات النظام، ثم اختر الشريحة الصحيحة.")
            )
        }
        if (!snapshot.smsFeatureAvailable) {
            return blocked(
                headline = "ميزة SMS غير متاحة على الجهاز",
                detail = "هذا الجهاز لا يعرض ميزة اتصال هاتفي قادرة على الرسائل، لذلك لن يبدأ الإرسال الآلي.",
                steps = listOf(
                    "استخدم هاتفاً يحتوي عتاد SMS.",
                    "أرسل يدوياً فقط إذا كانت شاشة الإرسال اليدوي تسمح بذلك."
                )
            )
        }
        val sim = snapshot.sims.firstOrNull { it.subscriptionId == settings.subscriptionId }
        if (sim == null) {
            if (!snapshot.simListReadable) {
                return waiting(
                    headline = "تعذر قراءة قائمة الشرائح",
                    detail = "فشلت قراءة قائمة الشرائح من النظام. هذا ليس دليلاً على إزالة الشريحة المحفوظة.",
                    steps = listOf(
                        "أعد فتح التطبيق.",
                        "إذا استمر التعذر، تحقق من إذن حالة الهاتف وحدّث التشخيص قبل التفعيل."
                    )
                )
            }
            return blocked(
                headline = "الشريحة المحفوظة غير ظاهرة",
                detail = "Android لا يعرض الشريحة التي اخترتها عند الحفظ.",
                steps = listOf(
                    "تأكد من وجود SIM ومقعدها.",
                    "أعد حفظ الربط مع اختيار شريحة نشطة."
                )
            )
        }
        when (sim.card) {
            SimCardStatus.ABSENT -> return blocked(
                headline = "لا توجد شريحة في الفتحة المختارة",
                detail = "البوابة تحتاج SIM فعّالة للإرسال عبر الشبكة الخلوية.",
                steps = listOf("أدخل الشريحة أو اختر فتحة أخرى ثم حدّث الإعداد.")
            )
            SimCardStatus.NOT_READY, SimCardStatus.PIN_REQUIRED, SimCardStatus.PUK_REQUIRED,
            SimCardStatus.NETWORK_LOCKED, SimCardStatus.DISABLED, SimCardStatus.ERROR ->
                return blocked(
                    headline = "الشريحة غير جاهزة",
                    detail = "Android يبلغ أن الشريحة ليست جاهزة للإرسال.",
                    steps = listOf(
                        "أكمل تهيئة الشريحة في إعدادات النظام.",
                        "أعد فتح التطبيق بعد جاهزية الشريحة."
                    )
                )
            SimCardStatus.UNKNOWN -> return waiting(
                headline = "حالة الشريحة غير مُبلَّغة",
                detail = "لم يُبلَّغ عن حالة الشريحة، لذلك جاهزية الإرسال غير مؤكدة.",
                steps = listOf(
                    "امنح إذن حالة الهاتف إن لزم.",
                    "أعد فتح التطبيق وانتظر حتى يبلّغ Android أن الشريحة READY."
                )
            )
            else -> Unit
        }
        when (sim.service) {
            MobileServiceStatus.OUT_OF_SERVICE, MobileServiceStatus.EMERGENCY_ONLY, MobileServiceStatus.POWER_OFF ->
                return blocked(
                    headline = "خدمة الشبكة الخلوية غير متاحة",
                    detail = "مؤشر خدمة الشبكة يشير إلى أن الهاتف لا يمكنه استخدام SMS الخلوي الآن.",
                    steps = listOf(
                        "أوقف وضع الطيران وتأكد من تغطية الشبكة.",
                        "أعد تشغيل الراديو أو الجهاز إذا استمرت الحالة."
                    )
                )
            MobileServiceStatus.UNKNOWN -> return waiting(
                headline = "حالة الخدمة الخلوية غير مُبلَّغة",
                detail = "لم يُبلَّغ عن حالة الخدمة الخلوية.",
                steps = listOf("أعد فتح التطبيق أو انتظر تحديث التشخيص التالي.")
            )
            else -> Unit
        }
        when (snapshot.internet) {
            InternetStatus.DISCONNECTED, InternetStatus.NO_INTERNET -> return waiting(
                headline = "لا يوجد إنترنت مُتحقَّق منه",
                detail = "فحص الخادم يعتمد على WorkManager ويتطلب شبكة IP متصلة.",
                steps = listOf(
                    "فعّل بيانات الجوال أو Wi‑Fi مع إنترنت فعّال.",
                    "سيُعاد جدولة الفحص عند عودة الشبكة إذا لم يُوقَف التطبيق إجبارياً."
                )
            )
            InternetStatus.UNKNOWN -> return waiting(
                headline = "حالة الإنترنت غير مؤكدة",
                detail = "تعذر قراءة حالة الشبكة الآن؛ قد يُؤخر Android المزامنة.",
                steps = listOf("افتح التطبيق لتحديث المؤشرات.")
            )
            InternetStatus.CONNECTED -> Unit
        }
        if (syncStatus.updatedAt <= 0L) {
            return waiting(
                headline = "لم تُسجَّل مزامنة بعد",
                detail = "التفعيل يجدول WorkManager. أول فحص يحدث عندما تسمح الشبكة والنظام، وقد يتأخر عن لحظة التفعيل.",
                steps = listOf("أبقِ الاستقبال مفعّلاً. إذا أُوقف التطبيق إجبارياً، افتحه مرة واحدة لإعادة الجدولة.")
            )
        }
        if (now - syncStatus.updatedAt > STALE_SYNC_MS) {
            return waiting(
                headline = "آخر مزامنة أقدم من المتوقع",
                detail = "قد يؤخر Doze أو سياسة البطارية عمل WorkManager، أو أن التطبيق كان موقوفاً إجبارياً قبل فتحه. الفتح الحالي يعيد الجدولة إذا بقي الاستقبال مفعّلاً.",
                steps = listOf(
                    "من إعدادات التطبيق، تأكد أنه ليس موقوفاً إجبارياً.",
                    "تحقق من الإنترنت. الدورة الدورية لا تقل عن 15 دقيقة وقد تتأخر أكثر من ذلك."
                )
            )
        }
        when (syncStatus.state) {
            "network_error" -> return waiting(
                headline = "تعذر الوصول للخادم آخر مرة",
                detail = "WorkManager سيحاول لاحقاً ما دام الاستقبال مفعّلاً.",
                steps = listOf("تحقق من عنوان API وTLS، ثم افتح التطبيق أو انتظر الدورة التالية.")
            )
            "backend_error", "local_error" -> return waiting(
                headline = "خطأ أثناء آخر مزامنة",
                detail = "لم يُؤكَّد اتصال سليم بالخادم في آخر محاولة.",
                steps = listOf("راجع سجل الخادم ثم أعد فتح التطبيق.")
            )
            "permission_unavailable" -> return blocked(
                headline = "النظام رفض صلاحية أثناء المزامنة",
                detail = "حدث استثناء أمان أثناء العمل الخلفي.",
                steps = listOf("افتح التطبيق وتحقق من الأذونات والشريحة.")
            )
        }
        return GatewayOperationalStatus(
            phase = GatewayOperationalPhase.READY,
            headline = "جاهز للمزامنة المجدولة",
            detail = "الشروط المحلية تسمح بالإرسال عند وصول أمر من الخادم. التنفيذ يبقى عبر WorkManager وقد يتأخر.",
            recoverySteps = listOf(
                "لا نستخدم خدمة أمامية دائمة؛ الفحص الدوري 15 دقيقة على الأقل.",
                "لا نعيد إرسال أي محاولة UNKNOWN تلقائياً."
            )
        )
    }

    private fun blocked(headline: String, detail: String, steps: List<String>) = GatewayOperationalStatus(
        phase = GatewayOperationalPhase.BLOCKED,
        headline = headline,
        detail = detail,
        recoverySteps = steps
    )

    private fun waiting(headline: String, detail: String, steps: List<String>) = GatewayOperationalStatus(
        phase = GatewayOperationalPhase.WAITING,
        headline = headline,
        detail = detail,
        recoverySteps = steps
    )

    private const val STALE_SYNC_MS = 45 * 60 * 1000L
}

package com.almazen.sms.domain

object GatewaySyncStateLabels {
    fun label(state: String): String = when (state) {
        "connected" -> "الخادم متصل"
        "network_error" -> "تعذر الوصول للشبكة أو الخادم"
        "backend_error" -> "خطأ من الخادم"
        "credential_rejected" -> "بيانات الجهاز مرفوضة"
        "permission_unavailable" -> "صلاحية غير متاحة"
        "local_error" -> "خطأ محلي"
        "gateway_paused" -> "الاستقبال متوقف"
        "sms_permission_denied" -> "إذن SMS غير ممنوح"
        "phone_state_denied" -> "إذن الشريحة غير متاح"
        "sim_list_unreadable" -> "تعذر قراءة الشرائح"
        "sim_missing" -> "الشريحة غير ظاهرة"
        "sim_not_ready" -> "الشريحة غير جاهزة"
        "cellular_unavailable" -> "الشبكة الخلوية غير متاحة"
        "cellular_unconfirmed" -> "الشبكة الخلوية غير مؤكدة"
        "waiting_for_internet" -> "بانتظار إنترنت"
        "device_not_ready" -> "الجهاز غير جاهز"
        else -> "بانتظار أول اتصال"
    }

    fun isHealthy(state: String): Boolean = state == "connected"
}

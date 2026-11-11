package com.almazen.sms.domain

import java.net.URI

object GatewayUrlPolicy {
    fun validate(serverUrl: String): String {
        val normalized = serverUrl.trim().trimEnd('/')
        val uri = runCatching { URI(normalized) }.getOrNull()
        require(uri != null && uri.scheme.equals("https", ignoreCase = true) && !uri.host.isNullOrBlank()) {
            "أدخل عنوان HTTPS صالحًا للخادم."
        }
        require(uri.userInfo == null && uri.query == null && uri.fragment == null) {
            "لا تضع بيانات اعتماد أو معاملات سرية في عنوان الخادم."
        }
        require(uri.path.isNullOrBlank() || uri.path == "/") {
            "أدخل نطاق الخادم فقط دون مسار API."
        }
        return normalized
    }
}

package com.almazen.sms.network

import com.almazen.sms.data.GatewaySettings
import com.almazen.sms.domain.SendAuthorizationResult
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL

data class RemoteMessage(val id: String, val destination: String, val body: String)

data class RemoteClaim(val message: RemoteMessage, val sendAuthorizationRequired: Boolean)

class GatewayApiClient(private val settings: GatewaySettings) {
    fun heartbeat(appVersion: String, androidVersion: String, apiLevel: Int, networkStatus: String, simStatus: String) {
        post("/devices/heartbeat", JSONObject().apply {
            put("app_version", appVersion)
            put("android_version", androidVersion)
            put("api_level", apiLevel)
            put("network_status", networkStatus)
            put("sim_status", simStatus)
        })
    }

    fun claim(): RemoteClaim? {
        val json = request("GET", "/devices/messages/claim", null)
        if (json.isNull("data")) return null
        val data = json.getJSONObject("data")
        val message = RemoteMessage(data.getString("id"), data.getString("to"), data.getString("body"))
        val sendAuthorizationRequired = data.optBoolean("send_authorization_required", false)
        return RemoteClaim(message, sendAuthorizationRequired)
    }

    fun sendAuthorization(messageId: String): SendAuthorizationResult? {
        return try {
            val json = request("POST", "/devices/messages/${encodePathSegment(messageId)}/send-authorization", JSONObject())
            val data = json.getJSONObject("data")
            SendAuthorizationResult(send = data.getBoolean("send"), reason = data.getString("reason"))
        } catch (exception: GatewayApiException) {
            if (exception.httpStatus in 400..499) SendAuthorizationResult(send = false, reason = "rejected") else null
        } catch (_: IOException) {
            null
        } catch (_: Exception) {
            null
        }
    }

    fun acknowledge(messageId: String) {
        post("/devices/messages/${encodePathSegment(messageId)}/acknowledge", JSONObject())
    }

    fun report(messageId: String, status: String, detailCode: String? = null) {
        val payload = JSONObject().put("status", status)
        if (detailCode != null) payload.put("detail_code", detailCode.take(48))
        post("/devices/messages/${encodePathSegment(messageId)}/report", payload)
    }

    private fun post(path: String, payload: JSONObject) {
        request("POST", path, payload)
    }

    private fun request(method: String, path: String, payload: JSONObject?): JSONObject {
        val root = settings.serverUrl.trimEnd('/')
        val target = URL("$root/api/v1$path")
        if (target.protocol != "https") throw IOException("HTTPS is required for gateway credentials.")
        val connection = (target.openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = CONNECT_TIMEOUT_MS
            readTimeout = READ_TIMEOUT_MS
            instanceFollowRedirects = false
            useCaches = false
            setRequestProperty("Accept", "application/json")
            setRequestProperty("Authorization", "Bearer ${settings.deviceToken}")
            if (payload != null) {
                doOutput = true
                setRequestProperty("Content-Type", "application/json; charset=utf-8")
            }
        }

        try {
            if (payload != null) connection.outputStream.use { it.write(payload.toString().toByteArray(Charsets.UTF_8)) }
            val code = connection.responseCode
            if (code !in 200..299) throw GatewayApiException(code)
            val responseText = connection.inputStream.bufferedReader(Charsets.UTF_8).use { it.readText() }
            return if (responseText.isBlank()) JSONObject() else JSONObject(responseText)
        } catch (exception: GatewayApiException) {
            throw exception
        } catch (exception: IOException) {
            throw exception
        } catch (_: Exception) {
            throw IOException("Backend response could not be parsed.")
        } finally {
            connection.disconnect()
        }
    }

    private fun encodePathSegment(value: String): String = value.replace("%", "%25").replace("/", "%2F")

    companion object {
        private const val CONNECT_TIMEOUT_MS = 10_000
        private const val READ_TIMEOUT_MS = 15_000
    }
}

class GatewayApiException(val httpStatus: Int) : IOException("Backend returned HTTP $httpStatus.")

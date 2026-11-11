package com.almazen.sms.data

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import com.almazen.sms.domain.GatewayUrlPolicy
import java.nio.charset.StandardCharsets
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

data class GatewaySettings(
    val serverUrl: String,
    val deviceToken: String,
    val subscriptionId: Int,
    val simSlot: Int,
    val enabled: Boolean
)

data class GatewaySyncStatus(val state: String, val updatedAt: Long)

class GatewayCredentialStore(context: Context) {
    private val preferences = context.applicationContext.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)

    fun save(serverUrl: String, deviceToken: String, subscriptionId: Int, simSlot: Int, enabled: Boolean): Boolean {
        val normalizedUrl = validateServerUrl(serverUrl)
        require(deviceToken.startsWith("azm_device_") && deviceToken.length >= 40) { "بيانات اعتماد الجهاز غير صالحة." }
        require(subscriptionId >= 0 && simSlot >= 0) { "اختيار SIM غير صالح." }
        val encryptedToken = encrypt(deviceToken)
        return preferences.edit()
            .putString(KEY_SERVER_URL, normalizedUrl)
            .putString(KEY_TOKEN, encryptedToken)
            .putInt(KEY_SUBSCRIPTION_ID, subscriptionId)
            .putInt(KEY_SIM_SLOT, simSlot)
            .putBoolean(KEY_ENABLED, enabled)
            .commit()
    }

    fun load(): GatewaySettings? {
        val url = preferences.getString(KEY_SERVER_URL, null) ?: return null
        val token = preferences.getString(KEY_TOKEN, null)?.let { runCatching { decrypt(it) }.getOrNull() } ?: return null
        if (token.isBlank()) return null
        return GatewaySettings(
            serverUrl = url,
            deviceToken = token,
            subscriptionId = preferences.getInt(KEY_SUBSCRIPTION_ID, -1),
            simSlot = preferences.getInt(KEY_SIM_SLOT, -1),
            enabled = preferences.getBoolean(KEY_ENABLED, false)
        )
    }

    fun setEnabled(enabled: Boolean): Boolean = preferences.edit().putBoolean(KEY_ENABLED, enabled).commit()

    fun clear() {
        preferences.edit().clear().commit()
    }

    fun encryptPayload(value: String): String = encrypt(value)

    fun decryptPayload(value: String): String = decrypt(value)

    fun lastRemoteSendStartedAt(): Long = preferences.getLong(KEY_LAST_REMOTE_SEND, 0L)

    fun recordRemoteSendStartedAt(timestamp: Long) {
        preferences.edit().putLong(KEY_LAST_REMOTE_SEND, timestamp).commit()
    }

    fun recordSyncState(state: String, timestamp: Long = System.currentTimeMillis()) {
        preferences.edit().putString(KEY_SYNC_STATE, state.take(32)).putLong(KEY_SYNC_UPDATED_AT, timestamp).commit()
    }

    fun syncStatus(): GatewaySyncStatus = GatewaySyncStatus(
        preferences.getString(KEY_SYNC_STATE, "not_configured") ?: "not_configured",
        preferences.getLong(KEY_SYNC_UPDATED_AT, 0L)
    )

    private fun encrypt(value: String): String {
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey())
        val encrypted = cipher.doFinal(value.toByteArray(StandardCharsets.UTF_8))
        val iv = Base64.encodeToString(cipher.iv, Base64.NO_WRAP)
        val payload = Base64.encodeToString(encrypted, Base64.NO_WRAP)
        return "$iv:$payload"
    }

    private fun decrypt(value: String): String {
        val parts = value.split(':', limit = 2)
        require(parts.size == 2) { "Encrypted gateway data is malformed." }
        val iv = Base64.decode(parts[0], Base64.NO_WRAP)
        val payload = Base64.decode(parts[1], Base64.NO_WRAP)
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.DECRYPT_MODE, getOrCreateKey(), GCMParameterSpec(128, iv))
        return String(cipher.doFinal(payload), StandardCharsets.UTF_8)
    }

    private fun getOrCreateKey(): SecretKey {
        val keyStore = KeyStore.getInstance(KEYSTORE_PROVIDER).apply { load(null) }
        (keyStore.getKey(KEY_ALIAS, null) as? SecretKey)?.let { return it }
        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KEYSTORE_PROVIDER)
        generator.init(
            KeyGenParameterSpec.Builder(
                KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT
            ).setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setRandomizedEncryptionRequired(true)
                .setKeySize(256)
                .build()
        )
        return generator.generateKey()
    }

    companion object {
        private const val PREFERENCES = "device_gateway"
        private const val KEY_ALIAS = "com.almazen.sms.gateway.credentials.v1"
        private const val KEYSTORE_PROVIDER = "AndroidKeyStore"
        private const val TRANSFORMATION = "AES/GCM/NoPadding"
        private const val KEY_SERVER_URL = "server_url"
        private const val KEY_TOKEN = "device_token_encrypted"
        private const val KEY_SUBSCRIPTION_ID = "subscription_id"
        private const val KEY_SIM_SLOT = "sim_slot"
        private const val KEY_ENABLED = "gateway_enabled"
        private const val KEY_LAST_REMOTE_SEND = "last_remote_send_started_at"
        private const val KEY_SYNC_STATE = "last_sync_state"
        private const val KEY_SYNC_UPDATED_AT = "last_sync_updated_at"

        fun validateServerUrl(serverUrl: String): String {
            return GatewayUrlPolicy.validate(serverUrl)
        }
    }
}

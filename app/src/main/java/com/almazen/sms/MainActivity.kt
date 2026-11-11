package com.almazen.sms

import android.Manifest
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.LayoutDirection
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.repeatOnLifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import com.almazen.sms.data.SmsAttemptStore
import com.almazen.sms.data.GatewayCredentialStore
import com.almazen.sms.data.GatewaySettings
import com.almazen.sms.data.GatewaySyncStatus
import com.almazen.sms.device.GatewayDiagnostics
import com.almazen.sms.device.GatewaySnapshot
import com.almazen.sms.device.InternetStatus
import com.almazen.sms.device.MobileServiceStatus
import com.almazen.sms.device.SimCardStatus
import com.almazen.sms.device.SimStatus
import com.almazen.sms.domain.SmsAttempt
import com.almazen.sms.domain.SmsAttemptStatus
import com.almazen.sms.domain.SmsInput
import com.almazen.sms.domain.SmsInputValidation
import com.almazen.sms.domain.SmsInputValidator
import com.almazen.sms.domain.displayLabel
import com.almazen.sms.domain.shortLabel
import com.almazen.sms.platform.AndroidSmsGateway
import com.almazen.sms.platform.SmsSendRequest
import com.almazen.sms.platform.SmsSendResult
import com.almazen.sms.platform.SmsSendCoordinator
import com.almazen.sms.network.GatewayApiClient
import com.almazen.sms.worker.GatewayWorkScheduler
import com.almazen.sms.ui.theme.AlmazenSmsTheme
import com.almazen.sms.ui.theme.BrandAccent
import com.almazen.sms.ui.theme.BrandBackground
import com.almazen.sms.ui.theme.BrandCard
import com.almazen.sms.ui.theme.BrandMuted
import com.almazen.sms.ui.theme.BrandPrimary
import com.almazen.sms.ui.theme.BrandText
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import android.os.Build
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.coroutines.launch
import kotlinx.coroutines.delay

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent { AlmazenSmsTheme { AlmazenSmsApp() } }
    }
}

private enum class AppTab(val title: String) { HOME("الرئيسية"), SEND("إرسال تجريبي"), HISTORY("السجل"), GATEWAY("الخادم") }

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AlmazenSmsApp() {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val snackbar = remember { SnackbarHostState() }
    val diagnostics = remember { GatewayDiagnostics(context.applicationContext) }
    val gateway = remember { AndroidSmsGateway(context.applicationContext) }
    val gatewayCredentials = remember { GatewayCredentialStore(context.applicationContext) }
    val lifecycleOwner = LocalLifecycleOwner.current
    var snapshot by remember { mutableStateOf(diagnostics.snapshot()) }
    var attempts by remember { mutableStateOf(emptyList<SmsAttempt>()) }
    var selectedTab by remember { mutableStateOf(AppTab.HOME) }
    var destination by remember { mutableStateOf("") }
    var body by remember { mutableStateOf("") }
    var selectedSubscriptionId by remember { mutableStateOf<Int?>(null) }
    var queuedPermission by remember { mutableStateOf<String?>(null) }
    var permissionDialog by remember { mutableStateOf<String?>(null) }
    var confirmRequest by remember { mutableStateOf<SmsSendRequest?>(null) }
    var gatewaySettings by remember { mutableStateOf(runCatching { gatewayCredentials.load() }.getOrNull()) }
    var gatewaySyncState by remember { mutableStateOf(gatewayCredentials.syncStatus()) }
    var gatewayBusy by remember { mutableStateOf(false) }
    var gatewayActivationPending by remember { mutableStateOf(false) }
    var confirmAutomation by remember { mutableStateOf(false) }
    fun refresh() {
        snapshot = diagnostics.snapshot()
        gatewaySettings = runCatching { gatewayCredentials.load() }.getOrNull()
        gatewaySyncState = gatewayCredentials.syncStatus()
        SmsSendCoordinator.expireIfTimedOut()
        runCatching {
            SmsAttemptStore(context.applicationContext).use { store ->
                store.markStaleAttemptsUnknown(System.currentTimeMillis() - CALLBACK_TIMEOUT_MS)
                attempts = store.listRecent()
            }
        }.onFailure {
            scope.launch { snackbar.showSnackbar("تعذر قراءة سجل المحاولات المحلي.") }
        }
        if (snapshot.sims.none { it.subscriptionId == selectedSubscriptionId }) {
            selectedSubscriptionId = snapshot.sims.singleOrNull()?.subscriptionId
        }
    }

    fun enableAutomaticGateway() {
        val settings = gatewayCredentials.load()
        if (settings == null) {
            scope.launch { snackbar.showSnackbar("احفظ اتصالاً صالحاً بالخادم أولاً.") }
            return
        }
        val sim = snapshot.sims.firstOrNull { it.subscriptionId == selectedSubscriptionId }
        if (sim == null || sim.card != SimCardStatus.READY) {
            scope.launch { snackbar.showSnackbar("اختر شريحة نشطة وجاهزة قبل تفعيل الإرسال الآلي.") }
            return
        }
        if (!snapshot.sendSmsPermissionGranted) {
            gatewayActivationPending = true
            queuedPermission = Manifest.permission.SEND_SMS
            permissionDialog = "السماح مطلوب لكي يرسل الهاتف الرسائل التي يقبلها مفتاح API. قد تترتب رسوم من شركة الاتصالات. لن يبدأ التفعيل حتى تمنح إذن Android وتختار استمرارًا هنا."
            return
        }
        if (gatewayCredentials.setEnabled(true)) {
            gatewaySettings = gatewayCredentials.load()
            GatewayWorkScheduler.enable(context.applicationContext)
            scope.launch { snackbar.showSnackbar("فُعّل استقبال طلبات الخادم. قد يؤخر Android الفحص الدوري؛ لا يوجد ضمان للإرسال الفوري أو المتواصل.") }
        }
    }

    val permissionLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        refresh()
        val purpose = queuedPermission
        queuedPermission = null
        val enableGatewayAfterGrant = gatewayActivationPending
        gatewayActivationPending = false
        if (granted && purpose == Manifest.permission.SEND_SMS && enableGatewayAfterGrant) {
            enableAutomaticGateway()
        }
        scope.launch {
            snackbar.showSnackbar(
                when {
                    granted && purpose == Manifest.permission.SEND_SMS -> "مُنح الإذن. راجع الرسالة ثم اضغط إرسال مرة أخرى."
                    granted -> "مُنح إذن قراءة حالة الشرائح."
                    purpose == Manifest.permission.SEND_SMS -> "لم يُمنح إذن إرسال الرسائل. لم يتم إرسال أي شيء."
                    else -> "لم يُمنح إذن فحص الشرائح. يمكنك متابعة بقية التطبيق."
                }
            )
        }
    }

    LaunchedEffect(Unit) { refresh() }
    LaunchedEffect(lifecycleOwner) {
        lifecycleOwner.lifecycle.repeatOnLifecycle(Lifecycle.State.STARTED) {
            while (true) {
                delay(15_000)
                refresh()
            }
        }
    }

    DisposableEffect(lifecycleOwner) {
        val manager = context.getSystemService(ConnectivityManager::class.java)
        var callback: ConnectivityManager.NetworkCallback? = null
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_START -> if (callback == null && manager != null) {
                    callback = object : ConnectivityManager.NetworkCallback() {
                        override fun onAvailable(network: Network) { scope.launch { refresh() } }
                        override fun onLost(network: Network) { scope.launch { refresh() } }
                        override fun onCapabilitiesChanged(network: Network, networkCapabilities: NetworkCapabilities) { scope.launch { refresh() } }
                    }
                    runCatching { manager.registerDefaultNetworkCallback(callback!!) }
                        .onFailure { callback = null }
                    refresh()
                }
                Lifecycle.Event.ON_STOP -> callback?.let { registered ->
                    runCatching { manager?.unregisterNetworkCallback(registered) }
                    callback = null
                }
                else -> Unit
            }
        }
        lifecycleOwner.lifecycle.addObserver(observer)
        onDispose {
            lifecycleOwner.lifecycle.removeObserver(observer)
            callback?.let { runCatching { manager?.unregisterNetworkCallback(it) } }
        }
    }

    fun askPermission(permission: String) {
        queuedPermission = permission
        permissionLauncher.launch(permission)
    }

    fun beginSendReview() {
        when (val validation = SmsInputValidator.validate(SmsInput(destination, body))) {
            is SmsInputValidation.Invalid -> scope.launch {
                snackbar.showSnackbar(
                    when (validation.reason) {
                        SmsInputValidation.Reason.EMPTY_DESTINATION -> "أدخل رقم المستلم أولاً."
                        SmsInputValidation.Reason.INVALID_DESTINATION -> "تحقق من صيغة رقم المستلم."
                        SmsInputValidation.Reason.EMPTY_BODY -> "اكتب نص الرسالة أولاً."
                    }
                )
            }
            is SmsInputValidation.Valid -> {
                val subscriptionId = selectedSubscriptionId
                if (subscriptionId == null) {
                    permissionDialog = if (!snapshot.phoneStatePermissionGranted) {
                        "يلزم إذن حالة الهاتف لعرض الشرائح النشطة واختيار الشريحة التي ستستخدم للإرسال. لا نقرأ الرسائل الواردة أو رقم الهاتف."
                    } else {
                        "لم يعرض Android أي شريحة نشطة لهذا التطبيق. تحقق من حالة SIM في إعدادات الهاتف."
                    }
                    queuedPermission = if (!snapshot.phoneStatePermissionGranted) Manifest.permission.READ_PHONE_STATE else null
                    return
                }
                if (!snapshot.smsFeatureAvailable) {
                    scope.launch { snackbar.showSnackbar("هذا الجهاز لا يعلن توفر ميزة إرسال SMS.") }
                    return
                }
                if (!snapshot.sendSmsPermissionGranted) {
                    permissionDialog = "يحتاج ALMAZEN SMS إلى إذن SEND_SMS لإرسال الرسالة التي راجعتها يدويًا فقط. لن يقرأ التطبيق صندوق الرسائل."
                    queuedPermission = Manifest.permission.SEND_SMS
                    return
                }
                val sim = snapshot.sims.firstOrNull { it.subscriptionId == subscriptionId }
                if (sim == null) {
                    scope.launch { snackbar.showSnackbar("الشريحة المختارة لم تعد نشطة. حدّث التشخيص ثم أعد المراجعة.") }
                    refresh()
                    return
                }
                if (sim.card != SimCardStatus.READY) {
                    scope.launch { snackbar.showSnackbar("حالة الشريحة ليست جاهزة حسب Android (${sim.card.name}). لم يبدأ الإرسال.") }
                    return
                }
                if (sim.service == MobileServiceStatus.OUT_OF_SERVICE ||
                    sim.service == MobileServiceStatus.EMERGENCY_ONLY ||
                    sim.service == MobileServiceStatus.POWER_OFF
                ) {
                    scope.launch { snackbar.showSnackbar("خدمة الشبكة غير مؤكدة أو غير متاحة. لم يبدأ الإرسال.") }
                    return
                }
                val count = runCatching { gateway.estimateParts(body, subscriptionId) }.getOrDefault(1)
                confirmRequest = SmsSendRequest(
                    destination = validation.normalizedDestination,
                    body = body,
                    subscriptionId = subscriptionId,
                    simSlot = sim.slot,
                    carrierName = sim.carrier,
                    partCountForReview = count
                )
            }
        }
    }

    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl) {
    Scaffold(
        containerColor = BrandBackground,
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text("ALMAZEN SMS", fontWeight = FontWeight.ExtraBold, color = Color.White)
                        Text("بوابة الرسائل عبر الشريحة", style = MaterialTheme.typography.labelSmall, color = BrandMuted)
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = BrandPrimary)
            )
        },
        snackbarHost = { SnackbarHost(snackbar) },
        bottomBar = {
            Surface(color = BrandCard, shadowElevation = 8.dp) {
                Row(Modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    AppTab.entries.forEach { tab ->
                        val selected = selectedTab == tab
                        TextButton(
                            onClick = { selectedTab = tab },
                            modifier = Modifier.weight(1f),
                            colors = ButtonDefaults.textButtonColors(contentColor = if (selected) BrandAccent else BrandMuted)
                        ) {
                            Text(tab.title, fontWeight = if (selected) FontWeight.Bold else FontWeight.Medium, maxLines = 1)
                        }
                    }
                }
            }
        }
    ) { padding ->
        LazyColumn(
            modifier = Modifier.fillMaxSize().padding(padding),
            contentPadding = androidx.compose.foundation.layout.PaddingValues(start = 18.dp, end = 18.dp, top = 16.dp, bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp)
        ) {
            when (selectedTab) {
                AppTab.HOME -> {
                    item { HomeHeader(snapshot, attempts.size) }
                    item { GatewayStatusCard(snapshot) }
                    item { PrivacyNote() }
                    item { SectionTitle("أحدث المحاولات", "النتيجة من Android، لا تعني دائمًا وصول الرسالة") }
                    if (attempts.isEmpty()) item { EmptyState("لا توجد محاولات إرسال بعد") }
                    else items(attempts.take(3), key = { it.id }) { AttemptCard(it) }
                    item { OutlinedButton(onClick = { refresh() }, modifier = Modifier.fillMaxWidth()) { Text("تحديث حالة الجهاز") } }
                }
                AppTab.SEND -> {
                    item { SectionTitle("رسالة اختبار يدوية", "كل إرسال يتطلب مراجعتك وتأكيدك على هذه الشاشة") }
                    item { SendForm(destination, { destination = it }, body, { body = it }) }
                    item { SimPicker(snapshot, selectedSubscriptionId, { selectedSubscriptionId = it }, { permissionDialog = "نقرأ حالة الشرائح النشطة فقط لاختيار شريحة الإرسال. لا نطلب قراءة الرسائل أو رقم الهاتف."; queuedPermission = Manifest.permission.READ_PHONE_STATE }) }
                    item { Button(onClick = { beginSendReview() }, modifier = Modifier.fillMaxWidth(), enabled = !com.almazen.sms.platform.SmsSendCoordinator.isSending()) { Text("مراجعة وتأكيد الإرسال") } }
                    item { Text("لا تُرسل إلى رقم لا تملكه أو لم تحصل على موافقة صاحبه. لن يعاد إرسال النتيجة غير المؤكدة تلقائيًا.", style = MaterialTheme.typography.bodySmall, color = BrandMuted) }
                }
                AppTab.HISTORY -> {
                    item { SectionTitle("سجل الإرسال", "محفوظ محليًا على هذا الجهاز، دون نص الرسالة أو الرقم الكامل") }
                    if (attempts.isEmpty()) item { EmptyState("سيظهر سجل المحاولات هنا") }
                    else items(attempts, key = { it.id }) { AttemptCard(it, detailed = true) }
                    item { OutlinedButton(onClick = { refresh() }, modifier = Modifier.fillMaxWidth()) { Text("تحديث السجل") } }
                }
                AppTab.GATEWAY -> {
                    item { SectionTitle("ربط بوابة الهاتف بالخادم", "بيانات الاعتماد مشفرة ومحفوظة محليًا") }
                    item { SimPicker(snapshot, selectedSubscriptionId, { selectedSubscriptionId = it }, { permissionDialog = "نقرأ حالة الشرائح النشطة لاختيار الشريحة التي ستستخدمها الطلبات الواردة من API."; queuedPermission = Manifest.permission.READ_PHONE_STATE }) }
                    item {
                        GatewayConnectionCard(
                            settings = gatewaySettings,
                            syncState = gatewaySyncState,
                            busy = gatewayBusy,
                            onTestAndSave = { serverUrl, deviceToken, onSaved ->
                                val sim = snapshot.sims.firstOrNull { it.subscriptionId == selectedSubscriptionId }
                                if (sim == null) {
                                    scope.launch { snackbar.showSnackbar("امنح إذن حالة الهاتف واختر SIM قبل حفظ الربط.") }
                                } else {
                                    scope.launch {
                                        gatewayBusy = true
                                        try {
                                            val existing = gatewayCredentials.load()
                                            val endpoint = GatewayCredentialStore.validateServerUrl(serverUrl)
                                            if (existing?.enabled == true && endpoint != existing.serverUrl) {
                                                error("أوقف استقبال الرسائل قبل تغيير الخادم.")
                                            }
                                            val token = deviceToken.trim().ifBlank {
                                                existing?.takeIf { it.serverUrl == endpoint }?.deviceToken
                                                    ?: error("أدخل بيانات اعتماد الجهاز التي ظهرت مرة واحدة عند إنشائه.")
                                            }
                                            val settings = com.almazen.sms.data.GatewaySettings(endpoint, token, sim.subscriptionId, sim.slot, false)
                                            withContext(Dispatchers.IO) {
                                                val current = diagnostics.snapshot()
                                                val network = when (current.internet) {
                                                    InternetStatus.CONNECTED -> "connected"
                                                    InternetStatus.NO_INTERNET -> "no_internet"
                                                    InternetStatus.DISCONNECTED -> "disconnected"
                                                    InternetStatus.UNKNOWN -> "unknown"
                                                }
                                                val simState = if (sim.card == SimCardStatus.READY) "ready" else "not_ready"
                                                val appVersion = context.packageManager.getPackageInfo(context.packageName, 0).versionName ?: "unknown"
                                                GatewayApiClient(settings).heartbeat(appVersion, Build.VERSION.RELEASE ?: "unknown", Build.VERSION.SDK_INT, network, simState)
                                                check(gatewayCredentials.save(endpoint, token, sim.subscriptionId, sim.slot, false)) { "تعذر حفظ بيانات الربط محليًا." }
                                            }
                                            gatewaySettings = gatewayCredentials.load()
                                            gatewaySyncState = gatewayCredentials.syncStatus()
                                            onSaved()
                                            snackbar.showSnackbar("نجح اختبار HTTPS وحفظ بيانات الجهاز. لم يُرسل أي SMS.")
                                        } catch (exception: Exception) {
                                            snackbar.showSnackbar(exception.message?.take(120) ?: "تعذر الاتصال بالخادم. تحقق من HTTPS والبيانات.")
                                        } finally {
                                            gatewayBusy = false
                                        }
                                    }
                                }
                            },
                            onActivate = { confirmAutomation = true },
                            onPause = {
                                gatewayCredentials.setEnabled(false)
                                gatewaySettings = gatewayCredentials.load()
                                scope.launch { snackbar.showSnackbar("توقف استلام الرسائل الجديدة. ستستمر مزامنة نتائج الرسائل التي بدأت سابقًا.") }
                            },
                            onDisconnect = {
                                GatewayWorkScheduler.disable(context.applicationContext)
                                gatewayCredentials.clear()
                                gatewaySettings = null
                                gatewaySyncState = gatewayCredentials.syncStatus()
                            }
                        )
                    }
                }
            }
        }
    }

    permissionDialog?.let { message ->
        AlertDialog(
            onDismissRequest = { permissionDialog = null; queuedPermission = null },
            title = { Text(if (queuedPermission == Manifest.permission.SEND_SMS) "إذن إرسال الرسائل" else "فحص الشريحة") },
            text = { Text(message) },
            confirmButton = {
                TextButton(onClick = {
                    val permission = queuedPermission
                    permissionDialog = null
                    if (permission != null) askPermission(permission)
                }) { Text("متابعة") }
            },
            dismissButton = { TextButton(onClick = { permissionDialog = null; queuedPermission = null }) { Text("ليس الآن") } }
        )
    }

    if (confirmAutomation) {
        AlertDialog(
            onDismissRequest = { confirmAutomation = false },
            title = { Text("تفعيل استقبال رسائل API") },
            text = {
                Text("بعد التفعيل، قد يرسل الهاتف تلقائيًا الرسائل التي يعتمدها مشروع الخادم باستخدام SIM المختارة. الحد الافتراضي للمشروع 20 رسالة يوميًا وبحد أقصى 10 طلبات في الدقيقة؛ قد تترتب رسوم اتصال. Android قد يؤخر الفحص، ولا يمكن ضمان التوقيت أو التشغيل المستمر.")
            },
            confirmButton = { Button(onClick = { confirmAutomation = false; enableAutomaticGateway() }) { Text("أفهم وأفعّل") } },
            dismissButton = { TextButton(onClick = { confirmAutomation = false }) { Text("إلغاء") } }
        )
    }

    confirmRequest?.let { request ->
        AlertDialog(
            onDismissRequest = { confirmRequest = null },
            title = { Text("تأكيد إرسال SMS") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("راجع الوجهة والنص. سيُطلب من شبكة الهاتف إرسال الرسالة عند تأكيدك.")
                    Text("إلى: ${request.destination}", fontWeight = FontWeight.Bold)
                    Text("الشريحة: SIM ${request.simSlot + 1} · ${request.carrierName}")
                    if (snapshot.sims.firstOrNull { it.subscriptionId == request.subscriptionId }?.service == MobileServiceStatus.UNKNOWN) {
                        Text("حالة تسجيل الشريحة على الشبكة غير متاحة لهذا الإصدار من Android. ستظهر نتيجة الإرسال من رد النظام.", style = MaterialTheme.typography.bodySmall, color = BrandMuted)
                    }
                    Text("الأجزاء: ${request.partCountForReview}")
                    Card(colors = CardDefaults.cardColors(containerColor = BrandBackground)) {
                        Text(request.body, Modifier.fillMaxWidth().padding(12.dp), color = BrandText)
                    }
                    Text("حالة SENT تعني أن Android أبلغ بتسليمها للشبكة فقط، ولا تثبت وصولها.", style = MaterialTheme.typography.bodySmall, color = BrandMuted)
                }
            },
            confirmButton = {
                Button(onClick = {
                    confirmRequest = null
                    val result = gateway.send(request)
                    when (result) {
                        is SmsSendResult.Started -> scope.launch { snackbar.showSnackbar("بدأت محاولة الإرسال. معرّف المحاولة ${result.attemptId.take(8)}") }
                        is SmsSendResult.Rejected -> scope.launch { snackbar.showSnackbar(result.reason) }
                        is SmsSendResult.Failed -> scope.launch { snackbar.showSnackbar("${result.status.displayLabel()}: ${result.reason}") }
                    }
                    refresh()
                }) { Text("أؤكد الإرسال الآن") }
            },
            dismissButton = { TextButton(onClick = { confirmRequest = null }) { Text("إلغاء") } }
        )
    }
    }
}

@Composable
private fun HomeHeader(snapshot: GatewaySnapshot, attemptCount: Int) {
    Card(colors = CardDefaults.cardColors(containerColor = BrandPrimary), shape = RoundedCornerShape(28.dp)) {
        Column(Modifier.fillMaxWidth().padding(22.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text("مركز حالة البوابة", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold, color = Color.White)
            Text("إرسال يدوي ومتابعة واضحة لكل محاولة", color = BrandMuted)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                StatusPill(if (snapshot.sendSmsPermissionGranted) "الإذن متاح" else "الإذن غير ممنوح", snapshot.sendSmsPermissionGranted)
                StatusPill("$attemptCount محاولة", true)
            }
        }
    }
}

@Composable
private fun GatewayConnectionCard(
    settings: GatewaySettings?,
    syncState: GatewaySyncStatus,
    busy: Boolean,
    onTestAndSave: (serverUrl: String, deviceToken: String, onSaved: () -> Unit) -> Unit,
    onActivate: () -> Unit,
    onPause: () -> Unit,
    onDisconnect: () -> Unit
) {
    var serverUrl by remember(settings?.serverUrl) { mutableStateOf(settings?.serverUrl.orEmpty()) }
    var deviceToken by remember { mutableStateOf("") }
    var showDisconnectDialog by remember { mutableStateOf(false) }

    Card(colors = CardDefaults.cardColors(containerColor = BrandCard), shape = RoundedCornerShape(22.dp)) {
        Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text("إعداد اتصال HTTPS", fontWeight = FontWeight.Bold, color = BrandText)
            OutlinedTextField(
                value = serverUrl,
                onValueChange = { serverUrl = it },
                modifier = Modifier.fillMaxWidth(),
                label = { Text("نطاق API") },
                placeholder = { Text("https://sms.example.com") },
                supportingText = { Text("أدخل النطاق فقط. يقبل التطبيق HTTPS ولا يحفظ مسارًا أو بيانات اعتماد ضمن الرابط.") },
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri)
            )
            OutlinedTextField(
                value = deviceToken,
                onValueChange = { deviceToken = it },
                modifier = Modifier.fillMaxWidth(),
                label = { Text(if (settings == null) "مفتاح الجهاز الذي ظهر مرة واحدة" else "مفتاح جهاز بديل، اتركه فارغًا لاستخدام المحفوظ") },
                visualTransformation = PasswordVisualTransformation(),
                supportingText = { Text("المفتاح لا يظهر بعد الحفظ؛ يحميه Android Keystore ويُستثنى من النسخ الاحتياطي.") },
                singleLine = true
            )
            if (settings != null) {
                Text("بيانات جهاز محفوظة ومخفية · الشريحة المحفوظة SIM ${settings.simSlot + 1}", color = BrandMuted, style = MaterialTheme.typography.bodySmall)
                Text("حالة آخر اتصال: ${syncStateLabel(syncState.state)}${if (syncState.updatedAt > 0) " · ${formatDate(syncState.updatedAt)}" else ""}", color = BrandMuted, style = MaterialTheme.typography.bodySmall)
            }
            Button(
                onClick = { onTestAndSave(serverUrl, deviceToken) { deviceToken = "" } },
                enabled = !busy && serverUrl.isNotBlank(),
                modifier = Modifier.fillMaxWidth()
            ) { Text(if (busy) "جارٍ اختبار الاتصال…" else "اختبار HTTPS وحفظ الربط") }
            if (settings != null) {
                if (settings.enabled) {
                    OutlinedButton(onClick = onPause, modifier = Modifier.fillMaxWidth()) { Text("إيقاف استقبال الرسائل الجديدة") }
                } else {
                    Button(onClick = onActivate, modifier = Modifier.fillMaxWidth(), enabled = !busy) { Text("تفعيل استقبال طلبات API") }
                }
                OutlinedButton(onClick = { showDisconnectDialog = true }, modifier = Modifier.fillMaxWidth()) { Text("فصل الجهاز وحذف بيانات اعتماده") }
            }
            Text(
                "WorkManager يفحص الطلبات كل 15 دقيقة على الأقل حسب جدولة Android، وقد يتأخر بسبب النظام أو الشبكة. حد المشروع الافتراضي 20 رسالة يوميًا، مع إرسال متسلسل بفاصل لا يقل عن دقيقة. هذا لا يضمن تشغيلًا دائمًا ولا وصول SMS.",
                color = BrandMuted,
                style = MaterialTheme.typography.bodySmall
            )
        }
    }

    if (showDisconnectDialog) {
        AlertDialog(
            onDismissRequest = { showDisconnectDialog = false },
            title = { Text("فصل البوابة؟") },
            text = { Text("سيُوقف الفحص ويحذف مفتاح الجهاز من هذا الهاتف. أي نتيجة لم تُرفع ستبقى محلية إلى أن تعيد الربط.") },
            confirmButton = {
                Button(onClick = { showDisconnectDialog = false; onDisconnect() }) { Text("فصل وحذف المفتاح") }
            },
            dismissButton = { TextButton(onClick = { showDisconnectDialog = false }) { Text("إلغاء") } }
        )
    }
}

private fun syncStateLabel(state: String): String = when (state) {
    "connected" -> "الخادم أجاب"
    "network_error" -> "تعذر الوصول للشبكة أو الخادم"
    "backend_error" -> "رفض الخادم الطلب أو حدث خطأ"
    "credential_rejected" -> "بيانات الجهاز مرفوضة؛ أُوقف الاستقبال"
    "permission_unavailable" -> "صلاحية الجهاز غير متاحة"
    "local_error" -> "تعذر حفظ حالة محلية"
    else -> "لم يحدث اتصال بعد"
}

@Composable
private fun GatewayStatusCard(snapshot: GatewaySnapshot) {
    Card(colors = CardDefaults.cardColors(containerColor = BrandCard), shape = RoundedCornerShape(22.dp)) {
        Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            SectionTitle("حالة الجهاز", "مؤشرات النظام الحالية")
            StatusRow("ميزة SMS", if (snapshot.smsFeatureAvailable) "مدعومة من النظام" else "غير متاحة", snapshot.smsFeatureAvailable)
            StatusRow("الإذن", if (snapshot.sendSmsPermissionGranted) "ممنوح" else "يُطلب عند الإرسال", snapshot.sendSmsPermissionGranted)
            StatusRow("اتصال الإنترنت", when (snapshot.internet) {
                InternetStatus.CONNECTED -> "متصل حسب تحقق Android"
                InternetStatus.NO_INTERNET -> "شبكة متصلة دون إنترنت مؤكد"
                InternetStatus.DISCONNECTED -> "لا توجد شبكة نشطة"
                InternetStatus.UNKNOWN -> "غير معروف"
            }, snapshot.internet == InternetStatus.CONNECTED)
            StatusRow("النظام", "Android ${snapshot.osVersion} · API ${snapshot.apiLevel}", true)
            StatusRow("البطارية", when {
                snapshot.batteryPercent == null -> "غير متاحة"
                snapshot.charging == true -> "تشحن · ${snapshot.batteryPercent}%"
                else -> "${snapshot.batteryPercent}% · لا يظهر الشحن"
            }, snapshot.charging == true)
            HorizontalDivider(color = BrandMuted.copy(alpha = .22f))
            if (snapshot.sims.isEmpty()) {
                Text(
                    if (snapshot.phoneStatePermissionGranted) "لم يعرض Android شريحة نشطة. قد لا تعني هذه القراءة وحدها عدم وجود SIM." else "حالة SIM مخفية حتى تمنح إذن حالة الهاتف من شاشة الإرسال.",
                    color = BrandMuted,
                    style = MaterialTheme.typography.bodyMedium
                )
            } else snapshot.sims.forEach { sim -> SimSummary(sim) }
            Text("مؤشرات الشبكة لا تضمن قدرة المشغل على الإرسال أو استلام الطرف الآخر للرسالة.", color = BrandMuted, style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
private fun SimSummary(sim: SimStatus) {
    Column(verticalArrangement = Arrangement.spacedBy(5.dp)) {
        Text("SIM ${sim.slot + 1} · ${sim.carrier}", fontWeight = FontWeight.Bold, color = BrandText)
        StatusRow("جاهزية الشريحة", when (sim.card) {
            SimCardStatus.READY -> "جاهزة حسب Android"
            SimCardStatus.NOT_READY -> "غير جاهزة حسب Android"
            SimCardStatus.ABSENT -> "غير موجودة"
            SimCardStatus.PIN_REQUIRED -> "تتطلب PIN"
            SimCardStatus.PUK_REQUIRED -> "تتطلب PUK"
            SimCardStatus.NETWORK_LOCKED -> "مقفلة على الشبكة"
            SimCardStatus.DISABLED -> "معطلة أو مقيّدة"
            SimCardStatus.ERROR -> "خطأ في قراءة الشريحة"
            SimCardStatus.UNKNOWN -> "غير معروفة"
        }, sim.card == SimCardStatus.READY)
        StatusRow("خدمة الهاتف", when (sim.service) {
            MobileServiceStatus.IN_SERVICE -> "مسجل على الشبكة"
            MobileServiceStatus.OUT_OF_SERVICE -> "خارج الخدمة"
            MobileServiceStatus.EMERGENCY_ONLY -> "مكالمات الطوارئ فقط"
            MobileServiceStatus.POWER_OFF -> "الراديو متوقف"
            MobileServiceStatus.UNKNOWN -> "غير متاحة من النظام"
        }, sim.service == MobileServiceStatus.IN_SERVICE)
    }
}

@Composable
private fun SimPicker(snapshot: GatewaySnapshot, selected: Int?, onSelected: (Int) -> Unit, onRequestPermission: () -> Unit) {
    Card(colors = CardDefaults.cardColors(containerColor = BrandCard), shape = RoundedCornerShape(20.dp)) {
        Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("شريحة الإرسال", fontWeight = FontWeight.Bold, color = BrandText)
            Text("نختار معرّف الاشتراك صراحةً لتجنّب إرسال الرسالة عبر SIM غير المقصودة.", color = BrandMuted, style = MaterialTheme.typography.bodySmall)
            when {
                !snapshot.phoneStatePermissionGranted -> OutlinedButton(onClick = onRequestPermission, modifier = Modifier.fillMaxWidth()) { Text("السماح بفحص الشرائح") }
                snapshot.sims.isEmpty() -> Text("لا توجد شريحة نشطة ظاهرة للتطبيق.", color = BrandMuted)
                else -> snapshot.sims.forEach { sim ->
                    val isSelected = selected == sim.subscriptionId
                    OutlinedButton(onClick = { onSelected(sim.subscriptionId) }, modifier = Modifier.fillMaxWidth(), colors = ButtonDefaults.outlinedButtonColors(contentColor = if (isSelected) BrandAccent else BrandText)) {
                        Text("${if (isSelected) "✓  " else ""}SIM ${sim.slot + 1} · ${sim.carrier}")
                    }
                }
            }
        }
    }
}

@Composable
private fun SendForm(destination: String, onDestination: (String) -> Unit, body: String, onBody: (String) -> Unit) {
    Card(colors = CardDefaults.cardColors(containerColor = BrandCard), shape = RoundedCornerShape(22.dp)) {
        Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            OutlinedTextField(
                value = destination,
                onValueChange = onDestination,
                modifier = Modifier.fillMaxWidth(),
                label = { Text("رقم المستلم") },
                supportingText = { Text("أدخل الرقم بصيغته الدولية أو المحلية. يظهر كاملًا للمراجعة قبل الإرسال.") },
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone)
            )
            OutlinedTextField(
                value = body,
                onValueChange = onBody,
                modifier = Modifier.fillMaxWidth(),
                label = { Text("نص الرسالة") },
                supportingText = { Text("${body.length} حرف · يدعم العربية والإنجليزية والرسائل متعددة الأجزاء") },
                minLines = 4,
                maxLines = 7
            )
        }
    }
}

@Composable
private fun PrivacyNote() {
    Card(colors = CardDefaults.cardColors(containerColor = BrandAccent.copy(alpha = .10f)), shape = RoundedCornerShape(18.dp)) {
        Row(Modifier.padding(16.dp), verticalAlignment = Alignment.Top) {
            Text("خصوصيتك", fontWeight = FontWeight.Bold, color = BrandAccent)
            Spacer(Modifier.width(10.dp))
            Text("يحفظ سجل المحاولة حالة الإرسال والرقم مقنّعًا. لا يُحفظ نص الرسالة، ولا يقرأ التطبيق صندوق الوارد.", color = BrandText, style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
private fun AttemptCard(attempt: SmsAttempt, detailed: Boolean = false) {
    Card(colors = CardDefaults.cardColors(containerColor = BrandCard), shape = RoundedCornerShape(18.dp)) {
        Column(Modifier.fillMaxWidth().padding(15.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                Text(attempt.status.displayLabel(), fontWeight = FontWeight.Bold, color = statusColor(attempt.status))
                StatusPill(attempt.status.shortLabel(), attempt.status == SmsAttemptStatus.SENT || attempt.status == SmsAttemptStatus.DELIVERED)
            }
            Text("${attempt.maskedRecipient} · SIM ${attempt.simSlot + 1} · ${attempt.carrierName}", color = BrandText)
            Text("${formatDate(attempt.createdAt)} · ${attempt.partCount} جزء", color = BrandMuted, style = MaterialTheme.typography.bodySmall)
            if (detailed) {
                Text("معرّف: ${attempt.id.take(8)}", color = BrandMuted, style = MaterialTheme.typography.labelSmall)
                attempt.detail?.let { Text(it, color = BrandMuted, style = MaterialTheme.typography.bodySmall) }
                if (attempt.status == SmsAttemptStatus.UNKNOWN) Text("لا تعاود الإرسال تلقائيًا؛ تحقق من النتيجة قبل محاولة جديدة.", color = BrandAccent, style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

@Composable
private fun EmptyState(text: String) {
    Box(Modifier.fillMaxWidth().padding(vertical = 32.dp), contentAlignment = Alignment.Center) {
        Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Box(Modifier.size(46.dp).background(BrandCard, CircleShape), contentAlignment = Alignment.Center) { Text("SMS", color = BrandAccent, fontWeight = FontWeight.Bold) }
            Text(text, color = BrandMuted)
        }
    }
}

@Composable
private fun SectionTitle(title: String, subtitle: String) {
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, color = BrandText)
        Text(subtitle, style = MaterialTheme.typography.bodySmall, color = BrandMuted, maxLines = 2, overflow = TextOverflow.Ellipsis)
    }
}

@Composable
private fun StatusRow(label: String, value: String, positive: Boolean) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = BrandMuted)
        Text(value, color = if (positive) BrandAccent else BrandMuted, fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.bodySmall)
    }
}

@Composable
private fun StatusPill(text: String, positive: Boolean) {
    Surface(color = (if (positive) BrandAccent else Color(0xFF677487)).copy(alpha = .15f), shape = RoundedCornerShape(50)) {
        Text(text, Modifier.padding(horizontal = 10.dp, vertical = 5.dp), color = if (positive) BrandAccent else BrandMuted, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold)
    }
}

private fun statusColor(status: SmsAttemptStatus): Color = when (status) {
    SmsAttemptStatus.DELIVERED -> BrandAccent
    SmsAttemptStatus.SENT -> Color(0xFF78B6FF)
    SmsAttemptStatus.SEND_FAILED, SmsAttemptStatus.DELIVERY_FAILED -> Color(0xFFFF7D86)
    SmsAttemptStatus.UNKNOWN -> Color(0xFFFFC56E)
    SmsAttemptStatus.SENDING -> BrandMuted
}

private fun formatDate(timestamp: Long): String = SimpleDateFormat("yyyy/MM/dd · HH:mm", Locale.getDefault()).format(Date(timestamp))

private const val CALLBACK_TIMEOUT_MS = 2 * 60 * 1000L

package com.almazen.sms.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

val BrandPrimary = Color(0xFF101B2A)
val BrandBackground = Color(0xFF0A111B)
val BrandCard = Color(0xFF152233)
val BrandAccent = Color(0xFF75E0BD)
val BrandMuted = Color(0xFFA7B5C5)
val BrandText = Color(0xFFF0F5FA)

private val AlmazenColors = darkColorScheme(
    primary = BrandAccent,
    onPrimary = Color(0xFF092018),
    secondary = Color(0xFF8FB8FF),
    onSecondary = BrandPrimary,
    background = BrandBackground,
    onBackground = BrandText,
    surface = BrandCard,
    onSurface = BrandText,
    surfaceVariant = Color(0xFF202E40),
    onSurfaceVariant = BrandMuted,
    outline = Color(0xFF506177),
    error = Color(0xFFFF7D86),
    onError = Color(0xFF35080E)
)

@Composable
fun AlmazenSmsTheme(content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = AlmazenColors, typography = Typography, content = content)
}

# توافق مع الاستدعاء القديم من سطح المكتب — يشغّل السكربت الرئيسي داخل scripts.
$ErrorActionPreference = 'Stop'
$main = Join-Path $PSScriptRoot 'scripts\deploy-desktop.ps1'
if (-not (Test-Path -LiteralPath $main)) {
    throw "لم يُعثر على سكربت النشر: $main"
}
& $main @args
exit $LASTEXITCODE

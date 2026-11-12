# نشر backend الـ API إلى Hostinger من سطح المكتب — نفس أسلوب تحديث المازن أونلاين.
$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..')

$backendRoot = (Resolve-Path -LiteralPath (Join-Path (Get-Location) 'backend')).Path
$remote = 'u893896051@82.198.228.99'
$port = '65002'
$remoteRoot = '/home/u893896051/apps/almazen-sms-api'
$remoteHome = '/home/u893896051'
$remotePhpCli = if ([string]::IsNullOrWhiteSpace($env:ALMAZEN_SMS_REMOTE_PHP)) {
    '/opt/alt/php83/usr/bin/php'
} else {
    $env:ALMAZEN_SMS_REMOTE_PHP.Trim()
}
$uploadDirectories = @('app', 'config', 'database', 'resources', 'routes')
$deployPathPatterns = @(
    '^backend/app/',
    '^backend/config/',
    '^backend/database/',
    '^backend/resources/',
    '^backend/routes/',
    '^backend/openapi\.yaml$'
)

function Write-Title([string]$Text) {
    Write-Host ''
    Write-Host $Text -ForegroundColor Cyan
}

function Test-DeployPath([string]$Path) {
    $normalized = $Path.Replace('\', '/')
    foreach ($pattern in $deployPathPatterns) {
        if ($normalized -match $pattern) {
            return $true
        }
    }
    return $false
}

function Get-SshAuthOptions {
  @(
    '-o', 'PreferredAuthentications=password',
    '-o', 'PubkeyAuthentication=no',
    '-o', 'NumberOfPasswordPrompts=1',
    '-o', 'StrictHostKeyChecking=accept-new'
  )
}

function Get-SshBaseArgs {
  @(Get-SshAuthOptions) + @('-p', $port)
}

function Get-ScpBaseArgs {
  # OpenSSH scp uses -P for port (-p means preserve file times).
  @(Get-SshAuthOptions) + @('-P', $port)
}

Write-Host ''
Write-Host 'تحديث ALMAZEN SMS API' -ForegroundColor Cyan
Write-Host 'سيُطلب منك كلمة مرور الاستضافة. لا تُحفظ في الملف.' -ForegroundColor DarkGray
Write-Host ''

if (-not (Get-Command git -ErrorAction SilentlyContinue)) { throw 'Git غير مثبت على هذا الجهاز.' }
if (-not (Get-Command ssh -ErrorAction SilentlyContinue)) { throw 'SSH غير متوفر على هذا الجهاز.' }
if (-not (Get-Command scp -ErrorAction SilentlyContinue)) { throw 'scp غير متوفر على هذا الجهاز.' }
if (-not (Get-Command tar -ErrorAction SilentlyContinue)) { throw 'tar غير متوفر على هذا الجهاز.' }
if (-not (Get-Command python -ErrorAction SilentlyContinue)) { throw 'Python غير متوفر، وهو مطلوب لإدخال كلمة المرور بأمان.' }

$secure = Read-Host 'كلمة مرور SSH للاستضافة' -AsSecureString
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
$pass = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
if ([string]::IsNullOrEmpty($pass)) { throw 'لم تُدخل كلمة المرور.' }

$askBody = "@echo off`r`npython -c `"import os; print(os.environ.get('ALMAZEN_SSH_PASS',''))`"`r`n"
$ask = Join-Path $env:TEMP ('almazen-sms-askpass-' + [guid]::NewGuid().ToString('N') + '.cmd')
[System.IO.File]::WriteAllText($ask, $askBody)

try {
    $env:ALMAZEN_SSH_PASS = $pass
    $env:SSH_ASKPASS = $ask
    $env:SSH_ASKPASS_REQUIRE = 'force'
    $env:DISPLAY = 'required'

    Write-Host 'التحقق من كلمة المرور...' -ForegroundColor DarkGray
    $sshCheck = @(Get-SshBaseArgs) + @($remote, 'echo ok')
    & ssh @sshCheck | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'كلمة المرور غير صحيحة، أو تعذر الاتصال بالاستضافة.' }

    Write-Host 'تم قبول كلمة المرور.' -ForegroundColor Green
    Write-Host 'مقارنة ملفات الـ API مع آخر نسخة محفوظة في Git...' -ForegroundColor DarkGray

    git add -A
    $staged = @(git diff --cached --name-only)
    $secret = @($staged | Where-Object { $_ -match '(^|/)\.env$|(^|/)google-services\.json$|service-account\.json$|firebase.*\.json$' })
    if ($secret.Count -gt 0) {
        git reset -q
        throw "توقف النشر لأن التعديل يشمل ملفاً سرياً: $($secret -join ', ')"
    }

    $deployStaged = @($staged | Where-Object { Test-DeployPath $_ })
    $reuploadOnly = $false
    if ($deployStaged.Count -eq 0) {
        git reset -q
        Write-Host ''
        Write-Host 'لا توجد تغييرات جديدة على ملفات الـ API في Git.' -ForegroundColor Yellow
        $reuploadAnswer = (Read-Host 'إذا فشل الرفع سابقاً اكتب REUPLOAD لرفع النسخة الحالية (أو Enter للخروج)').Trim()
        if ($reuploadAnswer -cne 'REUPLOAD') { exit 0 }
        $reuploadOnly = $true
        Write-Host 'إعادة رفع النسخة الحالية من مجلدات API بدون commit جديد...' -ForegroundColor Cyan
    }
    else {
        $added = New-Object System.Collections.Generic.List[string]
        $modified = New-Object System.Collections.Generic.List[string]
        $deleted = New-Object System.Collections.Generic.List[string]

        foreach ($line in @(git status --porcelain)) {
            if ([string]::IsNullOrWhiteSpace($line) -or $line.Length -lt 4) { continue }
            $code = $line.Substring(0, 2).Trim()
            $path = $line.Substring(3)
            if ($path -match ' -> ') { $path = ($path -split ' -> ')[-1] }
            if (-not (Test-DeployPath $path)) { continue }
            if ($code -match 'D') { $deleted.Add($path) }
            elseif ($code -match '^\?\?$' -or $code -match 'A') { $added.Add($path) }
            else { $modified.Add($path) }
        }

        Write-Title "ملفات الـ API المعدّلة ($($modified.Count))"
        $modified | ForEach-Object { Write-Host "  * $_" }
        Write-Title "ملفات الـ API الجديدة ($($added.Count))"
        $added | ForEach-Object { Write-Host "  + $_" -ForegroundColor Green }
        Write-Title "ملفات الـ API المحذوفة ($($deleted.Count))"
        $deleted | ForEach-Object { Write-Host "  - $_" -ForegroundColor Red }

        Write-Host ''
        Write-Host 'جارٍ رفع التحديث إلى الاستضافة. انتظر حتى تكتمل العملية...' -ForegroundColor Cyan

        $name = git config user.name
        $email = git config user.email
        if ([string]::IsNullOrWhiteSpace($name)) { $name = 'Almazen SMS API' }
        if ([string]::IsNullOrWhiteSpace($email)) { $email = 'deploy@almazen.online' }

        $when = Get-Date -Format 'yyyy-MM-dd HH:mm'
        git -c "user.name=$name" -c "user.email=$email" commit -m "نشر تحديثات API إلى الاستضافة $when"
        if ($LASTEXITCODE -ne 0) { throw 'تعذر حفظ التعديلات في Git قبل الرفع.' }
    }

    if ($reuploadOnly) {
        Write-Host ''
        Write-Host 'جارٍ رفع التحديث إلى الاستضافة. انتظر حتى تكتمل العملية...' -ForegroundColor Cyan
    }

    $sshArgs = @(Get-SshBaseArgs) + @($remote)
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'

    & ssh @sshArgs "test -x $remotePhpCli"
    if ($LASTEXITCODE -ne 0) { throw "PHP 8.3 CLI غير متوفر على الاستضافة: $remotePhpCli" }

    & ssh @sshArgs "tar -czf $remoteHome/almazen-sms-api-backup-$stamp.tar.gz -C $remoteRoot app config database resources routes"
    if ($LASTEXITCODE -ne 0) { throw 'تعذر إنشاء النسخة الاحتياطية على الاستضافة.' }

    foreach ($directory in $uploadDirectories) {
        $localDir = Join-Path $backendRoot $directory
        if (-not (Test-Path -LiteralPath $localDir -PathType Container)) {
            throw "المجلد المحلي مفقود: backend/$directory"
        }

        $tarFile = Join-Path $env:TEMP "almazen-api-upload-$stamp-$directory.tar"
        if (Test-Path -LiteralPath $tarFile) { Remove-Item -LiteralPath $tarFile -Force }

        Push-Location -LiteralPath $backendRoot
        try {
            & tar -cf $tarFile $directory
            if ($LASTEXITCODE -ne 0) { throw "تعذر تجهيز الحزمة: $directory" }
        }
        finally {
            Pop-Location
        }

        $remoteTar = "$remoteHome/almazen-api-upload-$stamp-$directory.tar"
        & scp @(Get-ScpBaseArgs) + @($tarFile, "${remote}:$remoteTar")
        if ($LASTEXITCODE -ne 0) { throw "تعذر رفع مجلد $directory" }

        & ssh @sshArgs "tar -xf $remoteTar -C $remoteRoot && rm -f $remoteTar"
        if ($LASTEXITCODE -ne 0) { throw "تعذر استخراج مجلد $directory على الاستضافة" }

        Remove-Item -LiteralPath $tarFile -Force -ErrorAction SilentlyContinue
    }

    $openApi = Join-Path $backendRoot 'openapi.yaml'
    if (Test-Path -LiteralPath $openApi) {
        & scp @(Get-ScpBaseArgs) + @($openApi, "${remote}:$remoteRoot/openapi.yaml")
        if ($LASTEXITCODE -ne 0) { throw 'تعذر رفع openapi.yaml' }
    }

    & ssh @sshArgs "cd $remoteRoot && $remotePhpCli artisan optimize:clear && $remotePhpCli artisan migrate --force"
    if ($LASTEXITCODE -ne 0) { throw 'تعذر تشغيل أوامر Laravel على الاستضافة.' }

    Write-Host ''
    Write-Host 'اكتمل تحديث API على https://api.almazen.online/' -ForegroundColor Green
    Write-Host 'لم يُمس: .env و storage و vendor على الاستضافة. لا يرسل هذا السكربت SMS.' -ForegroundColor DarkGray
}
finally {
    Remove-Item Env:ALMAZEN_SSH_PASS -ErrorAction SilentlyContinue
    Remove-Item Env:SSH_ASKPASS -ErrorAction SilentlyContinue
    Remove-Item Env:SSH_ASKPASS_REQUIRE -ErrorAction SilentlyContinue
    Remove-Item Env:DISPLAY -ErrorAction SilentlyContinue
    $pass = $null
    Remove-Item -Force $ask -ErrorAction SilentlyContinue
}

param(
    [Parameter(Mandatory = $true)]
    [string]$PhpPath,
    [string]$ProjectRoot = (Split-Path -Parent $PSScriptRoot),
    [string]$TaskName = 'CPMSCompanyChatDailyLeave0800'
)

$ErrorActionPreference = 'Stop'

$resolvedPhp = (Resolve-Path -LiteralPath $PhpPath).Path
$resolvedProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
$jobPath = Join-Path $resolvedProjectRoot 'tools\company_chat_daily_job.php'

if (-not (Test-Path -LiteralPath $jobPath -PathType Leaf)) {
    throw "Company Chat job script was not found: $jobPath"
}

$phpVersion = (& $resolvedPhp -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;").Trim()
if ($phpVersion -ne '5.6') {
    throw "PHP 5.6 is required. Detected version: $phpVersion"
}

$requiredExtensions = @('pdo_mysql', 'curl', 'openssl')
foreach ($extensionName in $requiredExtensions) {
    $extensionLoaded = (& $resolvedPhp -r "echo extension_loaded('$extensionName') ? '1' : '0';").Trim()
    if ($extensionLoaded -ne '1') {
        throw "Required PHP extension is not enabled: $extensionName"
    }
}

$windowsTimeZone = Get-TimeZone
if ($windowsTimeZone.Id -ne 'Korea Standard Time') {
    throw "Windows time zone must be Korea Standard Time. Detected: $($windowsTimeZone.Id)"
}

$action = New-ScheduledTaskAction `
    -Execute $resolvedPhp `
    -Argument ('"{0}" --type=leave' -f $jobPath) `
    -WorkingDirectory $resolvedProjectRoot
$trigger = New-ScheduledTaskTrigger -Daily -At '08:00'
$principal = New-ScheduledTaskPrincipal `
    -UserId 'SYSTEM' `
    -LogonType ServiceAccount `
    -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Settings $settings `
    -Description 'Send the CPMS daily leave notice to the company Google Chat space at 08:00 KST.' `
    -Force | Out-Null

Get-ScheduledTask -TaskName $TaskName |
    Select-Object TaskName, State, Actions, Triggers

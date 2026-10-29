[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$projectDirectory = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$buildDirectory = [IO.Path]::GetFullPath((Join-Path $projectDirectory 'build'))
$setupPath = Join-Path $buildDirectory 'installer/setup.exe'
$payloadDirectory = Join-Path $buildDirectory 'installer/payload'
$scriptPath = Join-Path $projectDirectory 'windows/installer/water_project.iss'
$idMatch = [regex]::Match((Get-Content -LiteralPath $scriptPath -Raw), '(?m)^AppId=\{\{([0-9A-Fa-f-]+)\}\s*$')
if (-not $idMatch.Success) { throw 'Cannot identify the installer AppId safely.' }
$uninstallSubkey = 'Software\Microsoft\Windows\CurrentVersion\Uninstall\{' + $idMatch.Groups[1].Value + '}_is1'

function Get-InstallationRecords {
    foreach ($hive in @([Microsoft.Win32.RegistryHive]::CurrentUser, [Microsoft.Win32.RegistryHive]::LocalMachine)) {
        foreach ($view in @([Microsoft.Win32.RegistryView]::Registry64, [Microsoft.Win32.RegistryView]::Registry32)) {
            $baseKey = [Microsoft.Win32.RegistryKey]::OpenBaseKey($hive, $view)
            try {
                $key = $baseKey.OpenSubKey($uninstallSubkey)
                if ($key) {
                    try {
                        [pscustomobject]@{ Hive = $hive; View = $view; Location = [string]$key.GetValue('InstallLocation') }
                    }
                    finally { $key.Dispose() }
                }
            }
            finally { $baseKey.Dispose() }
        }
    }
}

if (@(Get-InstallationRecords).Count) {
    throw 'An existing installation uses this AppId. Run this smoke test in a clean Windows account.'
}
if (-not (Test-Path -LiteralPath $setupPath -PathType Leaf)) { throw 'Build setup.exe first.' }
$payloadFiles = @(Get-ChildItem -LiteralPath $payloadDirectory -Recurse -File | Where-Object Extension -NotIn @('.pdb', '.ilk', '.exp', '.lib'))
if ($payloadFiles.Count -lt 10) { throw 'The staged application bundle is incomplete.' }

$runDirectory = [IO.Path]::GetFullPath((Join-Path $buildDirectory ('installer-smoke/' + [guid]::NewGuid().ToString('N'))))
if (-not $runDirectory.StartsWith($buildDirectory + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Smoke test destination must stay inside the project build directory.'
}
New-Item -ItemType Directory -Path $runDirectory | Out-Null
$installDirectory = Join-Path $runDirectory 'installed'
$sentinelPath = Join-Path $installDirectory 'qa-user-data.txt'
$sentinelValue = [guid]::NewGuid().ToString()

function Invoke-SetupProcess([string]$Executable, [string]$Arguments) {
    $process = Start-Process -FilePath $Executable -ArgumentList $Arguments -WindowStyle Hidden -PassThru -Wait
    if ($process.ExitCode -ne 0) { throw "Installer process failed: exit $($process.ExitCode). Logs: $runDirectory" }
}

function Assert-Payload {
    foreach ($payloadFile in $payloadFiles) {
        $relativePath = $payloadFile.FullName.Substring($payloadDirectory.Length).TrimStart('\', '/')
        $installedPath = Join-Path $installDirectory $relativePath
        if (-not (Test-Path -LiteralPath $installedPath -PathType Leaf)) { throw "Missing installed payload: $relativePath" }
        if ((Get-FileHash -LiteralPath $installedPath).Hash -ne (Get-FileHash -LiteralPath $payloadFile.FullName).Hash) {
            throw "Installed payload differs: $relativePath"
        }
    }
    $records = @(Get-InstallationRecords)
    if (-not $records.Count) { throw 'The uninstall registration is missing.' }
    foreach ($record in $records) {
        if ($record.Hive -ne [Microsoft.Win32.RegistryHive]::CurrentUser -or $record.Location.TrimEnd('\', '/') -ne $installDirectory) {
            throw 'Installer registered outside the isolated per-user destination.'
        }
    }
}

function Remove-TestInstallation {
    $uninstaller = Join-Path $installDirectory 'unins000.exe'
    $records = @(Get-InstallationRecords)
    if (-not $records.Count) { return }
    foreach ($record in $records) {
        if ($record.Location.TrimEnd('\', '/') -ne $installDirectory) { throw 'Refusing to uninstall a different installation.' }
    }
    if (-not (Test-Path -LiteralPath $uninstaller -PathType Leaf)) { throw 'Test uninstaller is missing.' }
    Invoke-SetupProcess $uninstaller "/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /LOG=`"$(Join-Path $runDirectory 'uninstall.log')`""
}

try {
    foreach ($phase in @('install', 'upgrade')) {
        $arguments = "/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /CURRENTUSER /NOICONS /TASKS=`"`" /DIR=`"$installDirectory`" /GROUP=`"WaterProjectInstallerSmoke`" /LOG=`"$(Join-Path $runDirectory ($phase + '.log'))`""
        Invoke-SetupProcess $setupPath $arguments
        Assert-Payload
        if ($phase -eq 'install') {
            Set-Content -LiteralPath $sentinelPath -Value $sentinelValue -Encoding ascii
        }
        elseif ((Get-Content -LiteralPath $sentinelPath -Raw).Trim() -ne $sentinelValue) {
            throw 'Upgrade changed the synthetic user data.'
        }
        Write-Output "$phase payload: PASS ($($payloadFiles.Count) SHA256 matches)"
    }
    Remove-TestInstallation
    if (@(Get-InstallationRecords).Count) { throw 'Uninstall registration remains.' }
    foreach ($payloadFile in $payloadFiles) {
        $relativePath = $payloadFile.FullName.Substring($payloadDirectory.Length).TrimStart('\', '/')
        if (Test-Path -LiteralPath (Join-Path $installDirectory $relativePath)) { throw "Uninstall retained packaged file: $relativePath" }
    }
    if ((Get-Content -LiteralPath $sentinelPath -Raw).Trim() -ne $sentinelValue) { throw 'Uninstall removed synthetic user data.' }
    $result = [pscustomobject]@{
        Result = 'PASS'
        PayloadFiles = $payloadFiles.Count
        SetupSHA256 = (Get-FileHash -LiteralPath $setupPath).Hash
        Upgrade = 'same-version reinstall'
        UserDataPreserved = $true
        Logs = $runDirectory
    }
    $result | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $runDirectory 'result.json') -Encoding utf8
    $result | Format-List
}
finally {
    Remove-TestInstallation
}

[CmdletBinding()]
param(
    [switch]$SkipBuild,
    [string]$IsccPath,
    [string]$CrtDirectory,
    [string[]]$FlutterBuildArguments = @()
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$projectDirectory = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$releaseDirectory = Join-Path $projectDirectory 'build/windows/x64/runner/Release'
$outputDirectory = Join-Path $projectDirectory 'build/installer'
$payloadDirectory = Join-Path $outputDirectory 'payload'

function Find-InnoCompiler {
    if ($IsccPath) {
        if (-not (Test-Path -LiteralPath $IsccPath -PathType Leaf)) {
            throw "Inno compiler does not exist: $IsccPath"
        }
        return (Resolve-Path -LiteralPath $IsccPath).Path
    }
    $command = Get-Command 'ISCC.exe' -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    foreach ($baseDirectory in @($env:LOCALAPPDATA, ${env:ProgramFiles(x86)}, $env:ProgramFiles)) {
        if (-not $baseDirectory) { continue }
        foreach ($relativePath in @('Programs/Inno Setup 7/ISCC.exe', 'Programs/Inno Setup 6/ISCC.exe', 'Inno Setup 7/ISCC.exe', 'Inno Setup 6/ISCC.exe')) {
            $candidate = Join-Path $baseDirectory $relativePath
            if (Test-Path -LiteralPath $candidate -PathType Leaf) { return $candidate }
        }
    }
    throw 'Install Inno Setup 6.5 or newer from https://jrsoftware.org/isdl.php, or pass -IsccPath.'
}

function Find-CrtDirectory {
    if ($CrtDirectory) {
        return (Resolve-Path -LiteralPath $CrtDirectory).Path
    }
    $vswherePath = Join-Path ${env:ProgramFiles(x86)} 'Microsoft Visual Studio/Installer/vswhere.exe'
    if (-not (Test-Path -LiteralPath $vswherePath -PathType Leaf)) {
        throw 'Visual Studio redistributable was not found. Pass -CrtDirectory pointing to an x64 Microsoft.VC*.CRT directory.'
    }
    $visualStudioPaths = @(& $vswherePath -products '*' -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath)
    if ($LASTEXITCODE -ne 0) { throw 'vswhere failed to locate Visual Studio.' }
    $candidates = @(
        foreach ($visualStudioPath in $visualStudioPaths) {
            $redistRoot = Join-Path $visualStudioPath 'VC/Redist/MSVC'
            if (Test-Path -LiteralPath $redistRoot) {
                Get-ChildItem -LiteralPath $redistRoot -Directory | ForEach-Object {
                    $x64Directory = Join-Path $_.FullName 'x64'
                    if (Test-Path -LiteralPath $x64Directory) {
                        Get-ChildItem -LiteralPath $x64Directory -Directory -Filter 'Microsoft.VC*.CRT' |
                            Select-Object @{Name = 'Version'; Expression = { [version]$_.Parent.Parent.Name }}, FullName
                    }
                }
            }
        }
    )
    $selected = $candidates | Sort-Object Version -Descending | Select-Object -First 1
    if (-not $selected) { throw 'No x64 Microsoft CRT redistributable found. Pass -CrtDirectory.' }
    return $selected.FullName
}

$compiler = Find-InnoCompiler
$arabicMessages = Join-Path (Split-Path -Parent $compiler) 'Languages/Arabic.isl'
if (-not (Test-Path -LiteralPath $arabicMessages -PathType Leaf)) {
    throw 'This Inno Setup installation lacks Arabic.isl. Install Inno Setup 6.5 or newer with its languages.'
}
$runtimeDirectory = Find-CrtDirectory
foreach ($runtimeFile in @('msvcp140.dll', 'vcruntime140.dll', 'vcruntime140_1.dll')) {
    if (-not (Test-Path -LiteralPath (Join-Path $runtimeDirectory $runtimeFile) -PathType Leaf)) {
        throw "Incomplete x64 CRT directory: missing $runtimeFile in $runtimeDirectory"
    }
}

Push-Location $projectDirectory
try {
    if (-not $SkipBuild) {
        & flutter build windows --release @FlutterBuildArguments
        if ($LASTEXITCODE -ne 0) { throw "Flutter Windows release build failed (exit $LASTEXITCODE)." }
    }

    foreach ($requiredFile in @('water_project.exe', 'flutter_windows.dll', 'data/app.so', 'data/icudtl.dat', 'data/flutter_assets/AssetManifest.bin')) {
        if (-not (Test-Path -LiteralPath (Join-Path $releaseDirectory $requiredFile) -PathType Leaf)) {
            throw "Incomplete Windows release: $requiredFile is missing. Run without -SkipBuild."
        }
    }

    $pubspecText = Get-Content -LiteralPath (Join-Path $projectDirectory 'pubspec.yaml') -Raw
    $versionMatch = [regex]::Match($pubspecText, '(?m)^version:\s*(\d+\.\d+\.\d+)(?:-([0-9A-Za-z.-]+))?(?:\+(\d+))?\s*$')
    if (-not $versionMatch.Success) { throw 'pubspec.yaml must contain a numeric x.y.z version with an optional prerelease and numeric build number.' }
    $appVersion = $versionMatch.Groups[1].Value
    if ($versionMatch.Groups[2].Success) { $appVersion += '-' + $versionMatch.Groups[2].Value }
    $buildNumber = '0'
    if ($versionMatch.Groups[3].Success) {
        $buildNumber = $versionMatch.Groups[3].Value
        $appVersion += '+' + $buildNumber
    }
    $fileVersion = $versionMatch.Groups[1].Value + '.' + $buildNumber
    $actualVersion = (Get-Item -LiteralPath (Join-Path $releaseDirectory 'water_project.exe')).VersionInfo
    $actualFileVersion = '{0}.{1}.{2}.{3}' -f $actualVersion.FileMajorPart, $actualVersion.FileMinorPart, $actualVersion.FileBuildPart, $actualVersion.FilePrivatePart
    if ($actualFileVersion -ne $fileVersion) {
        throw "Release executable version $actualFileVersion differs from pubspec $fileVersion. Rebuild without -SkipBuild."
    }

    New-Item -ItemType Directory -Path $outputDirectory -Force | Out-Null
    # Only this fixed generated staging directory is removed; never the app's user data.
    $expectedPayloadPath = [IO.Path]::GetFullPath((Join-Path $projectDirectory 'build/installer/payload'))
    if ([IO.Path]::GetFullPath($payloadDirectory) -ne $expectedPayloadPath) { throw 'Unexpected staging path.' }
    if (Test-Path -LiteralPath $payloadDirectory) {
        if ((Get-Item -LiteralPath $payloadDirectory).Attributes -band [IO.FileAttributes]::ReparsePoint) {
            throw 'Refusing to replace a staging directory that is a symbolic link or junction.'
        }
        Remove-Item -LiteralPath $payloadDirectory -Recurse -Force
    }
    New-Item -ItemType Directory -Path $payloadDirectory | Out-Null
    Get-ChildItem -LiteralPath $releaseDirectory | Copy-Item -Destination $payloadDirectory -Recurse -Force
    Get-ChildItem -LiteralPath $runtimeDirectory -Filter '*.dll' -File | Copy-Item -Destination $payloadDirectory -Force

    & $compiler "/DBuildDir=$payloadDirectory" "/DOutputDir=$outputDirectory" "/DAppVersion=$appVersion" "/DAppFileVersion=$fileVersion" (Join-Path $PSScriptRoot 'water_project.iss')
    if ($LASTEXITCODE -ne 0) { throw "Inno Setup compilation failed (exit $LASTEXITCODE)." }
    $installerPath = Join-Path $outputDirectory 'setup.exe'
    if (-not (Test-Path -LiteralPath $installerPath -PathType Leaf)) { throw 'Inno Setup did not produce setup.exe.' }
    $hash = (Get-FileHash -LiteralPath $installerPath -Algorithm SHA256).Hash.ToLowerInvariant()
    Set-Content -LiteralPath (Join-Path $outputDirectory 'SHA256SUMS') -Value "$hash  setup.exe" -Encoding ascii
    Write-Host "Installer ready: $installerPath"
    Write-Host "SHA256: $hash"
}
finally {
    Pop-Location
}

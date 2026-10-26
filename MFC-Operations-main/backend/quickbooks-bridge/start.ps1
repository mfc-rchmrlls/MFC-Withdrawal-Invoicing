$ErrorActionPreference = 'Stop'
$executable = Join-Path $env:LOCALAPPDATA 'MFC-Operations\QuickBooksBridge\QuickBooksReadOnlyBridge.exe'
if (-not (Test-Path $executable)) {
    throw 'Build the read-only bridge first with build.ps1.'
}
if ([string]::IsNullOrEmpty($env:WQ_QB_BRIDGE_SECRET) -or [Text.Encoding]::UTF8.GetByteCount($env:WQ_QB_BRIDGE_SECRET) -lt 32) {
    throw 'Set WQ_QB_BRIDGE_SECRET securely in the bridge process environment before startup.'
}
& $executable

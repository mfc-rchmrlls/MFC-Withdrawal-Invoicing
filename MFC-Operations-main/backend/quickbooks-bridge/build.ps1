$ErrorActionPreference = 'Stop'
$compiler = 'C:\Windows\Microsoft.NET\Framework64\v4.0.30319\csc.exe'
$source = Join-Path $PSScriptRoot 'Program.cs'
$outputDirectory = Join-Path $env:LOCALAPPDATA 'MFC-Operations\QuickBooksBridge'
$output = Join-Path $outputDirectory 'QuickBooksReadOnlyBridge.exe'

if (-not (Test-Path $compiler)) {
    throw 'The .NET Framework 4.x x64 C# compiler was not found.'
}
New-Item -ItemType Directory -Force -Path $outputDirectory | Out-Null
& $compiler /nologo /target:exe /platform:x64 /out:$output /reference:System.Web.Extensions.dll /reference:System.Xml.dll /reference:Microsoft.CSharp.dll $source
if ($LASTEXITCODE -ne 0) {
    throw 'The QuickBooks read-only bridge compilation failed.'
}
Write-Output "Built the bridge outside the repository at $output"

<#
Read-only Windows HRM isolation preflight. Never installs/restarts services, edits
Apache, writes files or queries any database. Run on a candidate host first.
#>
[CmdletBinding()]
param(
    [string] $SharedWebRoot = 'C:\xampp\htdocs',
    [string] $VueWebRoot = 'C:\xampp\htdocs\hrm-web',
    [string] $LaravelRoot = 'C:\services\hrm\backend',
    [int] $BackendPort = 18080,
    [int] $DbPort = 55432
)
$ErrorActionPreference = 'Stop'
$blockers = New-Object 'System.Collections.Generic.List[string]'
function Show-Check([string]$label, [string]$value) {
    Write-Output ("{0,-26} {1}" -f $label, $value)
}
$web = [System.IO.Path]::GetFullPath($SharedWebRoot).TrimEnd('\') + '\'
$laravel = [System.IO.Path]::GetFullPath($LaravelRoot).TrimEnd('\') + '\'
$vue = [System.IO.Path]::GetFullPath($VueWebRoot).TrimEnd('\') + '\'
Show-Check 'Mode' 'READ ONLY (no provisioning)'
Show-Check 'Vue public root' $vue
Show-Check 'Laravel private root' $laravel
if ($laravel.StartsWith($web, [System.StringComparison]::OrdinalIgnoreCase)) {
    $blockers.Add('Laravel source directory is inside shared public webroot')
}
if (-not $vue.StartsWith($web, [System.StringComparison]::OrdinalIgnoreCase)) {
    Show-Check 'Vue placement' 'outside the expected shared htdocs (review needed)'
}
if ($BackendPort -eq $DbPort -or $DbPort -eq 5432 -or $BackendPort -in @(80,443,5432)) {
    $blockers.Add('Proposed ports overlap a known shared/default port')
}
foreach ($port in @($BackendPort, $DbPort, 80, 443, 5432) | Select-Object -Unique) {
    $listeners = @(Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue)
    $owners = ($listeners | Select-Object -ExpandProperty OwningProcess -Unique) -join ','
    if (-not $owners) { $owners = 'none' }
    Show-Check ('TCP '+$port) ('listener PID(s): '+$owners)
    if (($port -eq $DbPort -or $port -eq $BackendPort) -and $listeners.Count -gt 0) {
        $blockers.Add('Proposed dedicated port '+$port+' is already occupied; inspect before deploying')
    }
}
foreach ($name in @('Apache2.4','postgresql-x64-16','postgresql-legacy-recovery-16')) {
    $svc = Get-Service -Name $name -ErrorAction SilentlyContinue
    if ($svc) { Show-Check ('Service '+$name) $svc.Status.ToString() }
}
$sharedPhp = 'C:\xampp\php\php.exe'
if (Test-Path $sharedPhp) {
    $versionText = & $sharedPhp -r 'echo PHP_VERSION;' 2>$null
    Show-Check 'Existing Apache PHP' ([string]$versionText)
    Show-Check 'HRM PHP requirement' 'separate PHP 8.4.1+ (do not switch global Apache PHP)'
}
if ($blockers.Count -gt 0) {
    Write-Output 'STOP: isolation blockers found'
    $blockers | ForEach-Object { Write-Output (' - '+$_) }
    exit 2
}
Write-Output 'Preflight found no specified path/port collision. This is NOT deployment acceptance.'
exit 0

# Read-only RDS capacity monitor. Run in Administrator PowerShell.
# Samples CPU, memory, paging-related commit, disk latency, and RDS session count for 60 minutes.
# Does not change services, stop processes, or install anything.

$ErrorActionPreference = 'Stop'

$OutDir  = 'C:\ServerMonitoring'
$OutFile = Join-Path $OutDir 'RDS-Peak.csv'
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

$DurationMinutes = 60
$IntervalSeconds = 15
$Samples = [int]($DurationMinutes * 60 / $IntervalSeconds)

$Counters = @(
    '\Processor(_Total)\% Processor Time',
    '\Memory\Available MBytes',
    '\Memory\Committed Bytes',
    '\Memory\Commit Limit',
    '\System\Processor Queue Length',
    '\PhysicalDisk(_Total)\Avg. Disk sec/Read',
    '\PhysicalDisk(_Total)\Avg. Disk sec/Write'
)

Write-Host "Sampling $DurationMinutes minutes every ${IntervalSeconds}s ..."
Write-Host "Output: $OutFile"
Write-Host "Leave this running during normal teaching with all 40 students active."

$raw = Get-Counter -Counter $Counters -SampleInterval $IntervalSeconds -MaxSamples $Samples

foreach ($sample in $raw) {
    $v = @{}
    foreach ($cs in $sample.CounterSamples) {
        $v[($cs.Path -split '\\')[-1]] = $cs.CookedValue
    }

    $sessions = @(quser 2>$null | Select-Object -Skip 1 | Where-Object { $_ -match '\S' }).Count

    [pscustomobject]@{
        Time             = $sample.Timestamp
        CPU_Pct          = [math]::Round($v['% Processor Time'], 2)
        Available_RAM_GB = [math]::Round($v['Available MBytes'] / 1024, 2)
        Committed_GB     = [math]::Round($v['Committed Bytes'] / 1GB, 2)
        Commit_Limit_GB  = [math]::Round($v['Commit Limit'] / 1GB, 2)
        Processor_Queue  = [math]::Round($v['Processor Queue Length'], 2)
        Read_Latency_ms  = [math]::Round($v['Avg. Disk sec/Read'] * 1000, 2)
        Write_Latency_ms = [math]::Round($v['Avg. Disk sec/Write'] * 1000, 2)
        RDS_Sessions     = $sessions
    } | Export-Csv -Path $OutFile -Append -NoTypeInformation
}

$d    = Import-Csv $OutFile
$ram  = @($d | ForEach-Object { [double]$_.Available_RAM_GB })
$cpu  = @($d | ForEach-Object { [double]$_.CPU_Pct })
$read = @($d | ForEach-Object { [double]$_.Read_Latency_ms }) | Sort-Object

$summary = [pscustomobject]@{
    Samples             = $d.Count
    Min_Available_GB    = ($ram | Measure-Object -Minimum).Minimum
    Avg_Available_GB    = [math]::Round(($ram | Measure-Object -Average).Average, 2)
    Max_CPU_Pct         = ($cpu | Measure-Object -Maximum).Maximum
    P95_Read_Latency_ms = $read[[math]::Ceiling(0.95 * $read.Count) - 1]
    Max_RDS_Sessions    = ($d | ForEach-Object { [int]$_.RDS_Sessions } | Measure-Object -Maximum).Maximum
}

Write-Host "`n=== SUMMARY ===" -ForegroundColor Cyan
$summary | Format-List

if ($summary.Min_Available_GB -ge 8 -and
    $summary.Max_CPU_Pct -lt 80 -and
    $summary.P95_Read_Latency_ms -lt 20) {
    Write-Host "RESULT: 4 GB / 2 vCPU Docker VM is worth testing." -ForegroundColor Green
} else {
    Write-Warning "RESULT: Do not start the VM yet. Improve headroom or use a separate host."
}

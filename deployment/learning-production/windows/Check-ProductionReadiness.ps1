# Run as Administrator on the Windows Server 2025 host.
# Read-only. Confirms the host can safely run the 4 GB Docker VM.

Write-Host "`n=== WINDOWS SERVER READINESS ===" -ForegroundColor Cyan

$System = Get-CimInstance Win32_ComputerSystem
$CPU    = Get-CimInstance Win32_Processor
$OS     = Get-CimInstance Win32_OperatingSystem
$Disk   = Get-Volume -DriveLetter C

$TotalRAM = [math]::Round($System.TotalPhysicalMemory / 1GB, 2)
$FreeRAM  = [math]::Round($OS.FreePhysicalMemory / 1MB, 2)
$FreeDisk = [math]::Round($Disk.SizeRemaining / 1GB, 2)

Write-Host "`nProcessor:"
$CPU | Select-Object Name, NumberOfCores, NumberOfLogicalProcessors, MaxClockSpeed

Write-Host "`nMemory:"
Write-Host "Installed: $TotalRAM GB"
Write-Host "Currently available: $FreeRAM GB"

Write-Host "`nDisk:"
Write-Host "C: free space: $FreeDisk GB"

Write-Host "`nVirtualization support:"
$CPU | Select-Object VirtualizationFirmwareEnabled,
                      SecondLevelAddressExtensions

Write-Host "`nCurrent RDS sessions:"
quser

Write-Host "`n=== DECISION ==="
if ($TotalRAM -lt 32) {
    Write-Warning "NOT READY: this deployment plan requires at least 32 GB RAM."
} elseif ($FreeDisk -lt 150) {
    Write-Warning "NOT READY: less than 150 GB free on C:."
} elseif (-not $CPU.VirtualizationFirmwareEnabled) {
    Write-Warning "NOT READY: hardware virtualization is disabled in BIOS/UEFI."
} else {
    Write-Host "Hardware thresholds passed." -ForegroundColor Green
    Write-Host "Still run Monitor-RDS.ps1 for 60 minutes during peak before creating the VM."
}

# Run as Administrator. Creates the production Ubuntu VM.
# EDIT THE TWO VALUES BELOW BEFORE RUNNING.

$ErrorActionPreference = 'Stop'

# >>> CHANGE THIS to the full path of the Ubuntu 24.04 Server ISO <<<
$ISOPath = 'C:\ISO\ubuntu-24.04-live-server-amd64.iso'

# The Hyper-V external switch created in Phase 2.
$SwitchName = 'Docker-External'

$VMName  = 'Learning-Docker'
$VMPath  = 'C:\HyperV\LearningDocker'
$VHDPath = Join-Path $VMPath 'LearningDocker.vhdx'

# --- Safety checks -----------------------------------------------------------
$RAMGB = [math]::Round((Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory / 1GB)
if ($RAMGB -lt 32) {
    throw "Production deployment blocked: this plan requires at least 32 GB RAM (found $RAMGB GB)."
}
if (-not (Get-VMSwitch -Name $SwitchName -ErrorAction SilentlyContinue)) {
    throw "Hyper-V switch '$SwitchName' not found. Create it in Hyper-V Manager first."
}
if (-not (Test-Path -LiteralPath $ISOPath)) {
    throw "Ubuntu ISO not found: $ISOPath"
}
if (Get-VM -Name $VMName -ErrorAction SilentlyContinue) {
    throw "VM '$VMName' already exists. No changes made."
}

New-Item -ItemType Directory -Force -Path $VMPath | Out-Null

# --- Create the VM -----------------------------------------------------------
New-VM `
    -Name $VMName `
    -Generation 2 `
    -MemoryStartupBytes 4GB `
    -NewVHDPath $VHDPath `
    -NewVHDSizeBytes 80GB `
    -Path $VMPath `
    -SwitchName $SwitchName

# Fixed 4 GB memory (predictable maximum consumption).
Set-VMMemory -VMName $VMName -DynamicMemoryEnabled $false -StartupBytes 4GB

# Two virtual CPUs with a low relative scheduling weight.
Set-VMProcessor -VMName $VMName -Count 2 -RelativeWeight 20

# Secure Boot for Ubuntu.
Set-VMFirmware -VMName $VMName -EnableSecureBoot On `
    -SecureBootTemplate MicrosoftUEFICertificateAuthority

# Boot from the installation ISO.
Add-VMDvdDrive -VMName $VMName -Path $ISOPath
$DVD = Get-VMDvdDrive -VMName $VMName
Set-VMFirmware -VMName $VMName -FirstBootDevice $DVD

# Automatic start after Windows boots; graceful guest shutdown on Windows shutdown.
Set-VM -VMName $VMName `
    -AutomaticStartAction Start `
    -AutomaticStartDelay 60 `
    -AutomaticStopAction ShutDown `
    -AutomaticCheckpointsEnabled $false

Start-VM -Name $VMName

Write-Host "`nVM created and started:" -ForegroundColor Green
Get-VM -Name $VMName | Select-Object Name, State, Generation,
    AutomaticStartAction, AutomaticStartDelay, AutomaticStopAction

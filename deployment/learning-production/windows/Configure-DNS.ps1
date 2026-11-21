# Run as Administrator on the Windows Server.
# Creates the internal DNS name lms.geslab.local -> 192.168.10.196.

$ErrorActionPreference = 'Stop'

$Zone = 'geslab.local'
$Name = 'lms'
$IP   = '192.168.10.196'

# Install DNS role if it is not present.
if (-not (Get-WindowsFeature -Name DNS).Installed) {
    Write-Host "Installing DNS role ..."
    Install-WindowsFeature -Name DNS -IncludeManagementTools | Out-Null
}

Import-Module DnsServer -ErrorAction Stop

# Create a file-backed primary zone if it does not exist.
if (-not (Get-DnsServerZone -Name $Zone -ErrorAction SilentlyContinue)) {
    Write-Host "Creating DNS zone $Zone ..."
    Add-DnsServerPrimaryZone -Name $Zone -ZoneFile "$Zone.dns"
}

# Replace any wrong A record so the result is deterministic.
$existing = @(Get-DnsServerResourceRecord -ZoneName $Zone -Name $Name -RRType A -ErrorAction SilentlyContinue)
foreach ($record in $existing) {
    $current = $record.RecordData.IPv4Address.IPAddressToString
    if ($current -ne $IP) {
        Write-Host "Removing incorrect record $Name -> $current"
        Remove-DnsServerResourceRecord -ZoneName $Zone -Name $Name -InputObject $record -Force
    }
}

$remaining = @(Get-DnsServerResourceRecord -ZoneName $Zone -Name $Name -RRType A -ErrorAction SilentlyContinue)
if ($remaining.Count -eq 0) {
    Write-Host "Adding A record $Name -> $IP"
    Add-DnsServerResourceRecordA -ZoneName $Zone -Name $Name -IPv4Address $IP
}

Write-Host "`nVerification:" -ForegroundColor Cyan
Resolve-DnsName "$Name.$Zone" | Format-Table Name, Type, IP4Address -AutoSize
Write-Host "`nStudents must use this Windows Server as their DNS server."

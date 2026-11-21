# Run as Administrator on the Windows Server.
# Creates a restricted SMB share on the external USB drive for LMS backups.

$ErrorActionPreference = 'Stop'

# >>> EDIT THIS if your USB drive is not E: <<<
$Drive   = 'E:'
$Folder  = 'E:\LearningBackups'
$Share   = 'LearningBackups$'
$Account = 'LearningBackup'

# >>> EDIT THIS to your actual LAN subnet if it is not 192.168.10.0/24 <<<
$LanSubnet = '192.168.10.0/24'

if (-not (Test-Path -LiteralPath $Drive)) {
    throw "USB drive $Drive not found. Plug it in and check the letter in Get-Volume."
}

New-Item -ItemType Directory -Force -Path $Folder | Out-Null

# Create a dedicated least-privilege local account.
if (-not (Get-LocalUser -Name $Account -ErrorAction SilentlyContinue)) {
    $Password = Read-Host "Enter a strong password for the backup account" -AsSecureString
    New-LocalUser -Name $Account -Password $Password `
        -Description 'Restricted Learning LMS backup account' | Out-Null
} else {
    Write-Host "Local account $Account already exists."
}

$Identity = "$env:COMPUTERNAME\$Account"

# Remove inherited permissions, then grant only what is needed.
icacls $Folder /inheritance:r | Out-Null
icacls $Folder /grant:r "${Identity}:(OI)(CI)M" "SYSTEM:(OI)(CI)F" "BUILTIN\Administrators:(OI)(CI)F" | Out-Null

# Create the hidden SMB share if needed.
if (-not (Get-SmbShare -Name $Share -ErrorAction SilentlyContinue)) {
    New-SmbShare -Name $Share -Path $Folder `
        -ChangeAccess $Identity `
        -FolderEnumerationMode AccessBased | Out-Null
}

# Restrict SMB inbound access to the trusted LAN only.
if (-not (Get-NetFirewallRule -DisplayName 'Learning USB Backup SMB' -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -DisplayName 'Learning USB Backup SMB' `
        -Direction Inbound -Protocol TCP -LocalPort 445 `
        -RemoteAddress $LanSubnet -Action Allow `
        -Profile Domain,Private | Out-Null
}

Write-Host "`nBackup share ready:" -ForegroundColor Green
Get-SmbShare -Name $Share | Select-Object Name, Path
Write-Host "Use these in the Ubuntu credentials file:"
Write-Host "  username=$Account"
Write-Host "  password=<the password you entered>"
Write-Host "  domain=$env:COMPUTERNAME"
Write-Host "`nDo not port-forward TCP 445 through the router."

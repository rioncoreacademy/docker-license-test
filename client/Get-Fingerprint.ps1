<#
Generates the machine fingerprint the same way the licensing doc specifies:
SHA256(MachineGuid + BIOS_UUID)

Must run on the host (not inside a container) since Docker containers don't
have access to the host's registry or BIOS. Pass the resulting value into
the container as an environment variable, e.g.:

  $fp = .\Get-Fingerprint.ps1
  docker run -e LICENSE_FINGERPRINT=$fp your-image
#>

$machineGuid = (Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Cryptography' -Name 'MachineGuid').MachineGuid
$biosUuid = (Get-CimInstance -ClassName Win32_ComputerSystemProduct).UUID

$combined = "$machineGuid$biosUuid"
$sha256 = [System.Security.Cryptography.SHA256]::Create()
$hashBytes = $sha256.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($combined))
$fingerprint = ($hashBytes | ForEach-Object { $_.ToString('x2') }) -join ''

Write-Output $fingerprint

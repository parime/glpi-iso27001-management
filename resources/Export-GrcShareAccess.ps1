<#
.SYNOPSIS
    Exports the access rights of the Windows file shares of this server to a CSV file for
    GRC Manager for GLPI (issue #846). READ ONLY: nothing is changed on the server.

.DESCRIPTION
    For each shared folder (administrative shares such as C$ or ADMIN$ excluded), the script reads:
      - the share permissions (Get-SmbShareAccess);
      - the NTFS permissions of the share root (Get-Acl), with its owner;
      - the NTFS permissions set explicitly on the subfolders, down to -Depth levels, and whether
        the subfolder inherits the rights of its parent (InheritanceProtected = True when it does not).
    Run it with an account that can read the folders (a member of Backup Operators or an account
    with read rights is enough). Then import the CSV file on the page
    "File share access rights" of GRC Manager.

    GRC Manager for GLPI, https://github.com/parime/glpi-iso27001-management, GPL-3.0-or-later.

.PARAMETER Depth
    Number of folder levels read under each share (0 = the share root only). Default: 1.

.PARAMETER OutFile
    CSV file written. Default: share-access.csv in the current folder.

.PARAMETER Share
    Names of the shares to read. Default: every non-administrative share.

.PARAMETER ResolvePrincipalType
    Asks Active Directory whether each account is a user or a group (ActiveDirectory module
    required). Without it, GRC Manager matches the accounts with the GLPI users and groups.

.EXAMPLE
    .\Export-GrcShareAccess.ps1 -Depth 2 -OutFile shares.csv
#>
[CmdletBinding()]
param(
    [ValidateRange(0, 10)]
    [int] $Depth = 1,
    [string] $OutFile = 'share-access.csv',
    [string[]] $Share = @(),
    [switch] $ResolvePrincipalType
)

Set-StrictMode -Version 2
$ErrorActionPreference = 'Stop'

$server = try { [System.Net.Dns]::GetHostEntry($env:COMPUTERNAME).HostName } catch { $env:COMPUTERNAME }
$typeCache = @{}
$useAd = $false
if ($ResolvePrincipalType) {
    if (Get-Module -ListAvailable -Name ActiveDirectory) {
        Import-Module ActiveDirectory
        $useAd = $true
    } else {
        Write-Warning 'ActiveDirectory module not found: account types are not resolved.'
    }
}

function Get-Sid([System.Security.Principal.IdentityReference] $Identity) {
    try {
        return $Identity.Translate([System.Security.Principal.SecurityIdentifier]).Value
    } catch {
        return ''
    }
}

function Get-PrincipalType([string] $Sid) {
    if (-not $useAd -or $Sid -notlike 'S-1-5-21-*') { return '' }
    if (-not $typeCache.ContainsKey($Sid)) {
        $type = ''
        try {
            $object = Get-ADObject -Filter "objectSid -eq '$Sid'" -Properties objectClass
            if ($object) { $type = [string] $object.objectClass }
        } catch {
            $type = ''
        }
        $typeCache[$Sid] = $type
    }
    return $typeCache[$Sid]
}

function New-Row($ShareName, $SharePath, $Folder, $Level, $Scope, $Identity, $Sid, $Rights, $Access,
                 $Inherited, $Protected, $Owner) {
    [PSCustomObject] @{
        Server               = $server
        Share                = $ShareName
        SharePath            = $SharePath
        Folder               = $Folder
        Depth                = $Level
        Scope                = $Scope
        Identity             = $Identity
        Sid                  = $Sid
        PrincipalType        = Get-PrincipalType $Sid
        Rights               = $Rights
        AccessType           = $Access
        IsInherited          = $Inherited
        InheritanceProtected = $Protected
        Owner                = $Owner
    }
}

function Get-FolderRows($ShareName, $SharePath, $Path, $Unc, $Level) {
    try {
        $acl = Get-Acl -LiteralPath $Path
    } catch {
        Write-Warning "Cannot read the rights of $Path : $($_.Exception.Message)"
        return
    }
    foreach ($ace in $acl.Access) {
        # Under the root, only the rights set on the folder itself (the inherited ones repeat the parent).
        if ($Level -gt 0 -and $ace.IsInherited) { continue }
        New-Row $ShareName $SharePath $Unc $Level 'NTFS' $ace.IdentityReference.Value (Get-Sid $ace.IdentityReference) `
            $ace.FileSystemRights.ToString() $ace.AccessControlType.ToString() $ace.IsInherited `
            $acl.AreAccessRulesProtected $acl.Owner
    }
}

$rows = New-Object System.Collections.Generic.List[object]
$shares = Get-SmbShare | Where-Object { -not $_.Special -and $_.Path -and $_.Name -notmatch '\$$' }
if ($Share.Count -gt 0) {
    $shares = $shares | Where-Object { $Share -contains $_.Name }
}
foreach ($smb in $shares) {
    $root = "\\$server\$($smb.Name)"
    foreach ($access in Get-SmbShareAccess -Name $smb.Name) {
        $sid = try {
            (New-Object System.Security.Principal.NTAccount($access.AccountName)).Translate(
                [System.Security.Principal.SecurityIdentifier]).Value
        } catch { '' }
        $rows.Add((New-Row $smb.Name $smb.Path $root 0 'Share' $access.AccountName $sid `
            $access.AccessRight.ToString() $access.AccessControlType.ToString() $false $false ''))
    }
    foreach ($row in Get-FolderRows $smb.Name $smb.Path $smb.Path $root 0) { $rows.Add($row) }
    if ($Depth -gt 0) {
        $folders = Get-ChildItem -LiteralPath $smb.Path -Directory -Recurse -Depth ($Depth - 1) -ErrorAction SilentlyContinue
        foreach ($folder in $folders) {
            $relative = $folder.FullName.Substring($smb.Path.TrimEnd('\').Length).TrimStart('\')
            $level = ($relative -split '\\').Count
            foreach ($row in Get-FolderRows $smb.Name $smb.Path $folder.FullName "$root\$relative" $level) {
                $rows.Add($row)
            }
        }
    }
}

$rows | Export-Csv -LiteralPath $OutFile -NoTypeInformation -Encoding UTF8
Write-Host "$($rows.Count) right(s) of $(@($shares).Count) share(s) written to $OutFile"

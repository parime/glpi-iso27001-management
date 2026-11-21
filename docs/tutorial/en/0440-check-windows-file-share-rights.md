### Check the access rights of the Windows file shares (controls A.5.15, A.5.18 and A.8.3)

The auditor asks for evidence that access to shared folders is limited to what is needed and
reviewed. The plugin never connects to a file server and never changes a right: an administrator
runs a read-only PowerShell script, then imports the CSV file it produces. You need the plugin right
and the right on the sensitive registers (with modification to import).

1. Open Access review > **File share access rights**. The "What is this page for?" box recalls what
   is expected.
2. In **Import the rights of a file server**, click **Download the PowerShell script** and copy
   `Export-GrcShareAccess.ps1` to the file server. Run it with an account that can read the folders:

   ```powershell
   .\Export-GrcShareAccess.ps1 -Depth 2 -OutFile shares.csv
   ```

   The script reads the shares (`Get-SmbShare`, administrative shares excluded), their share
   permissions (`Get-SmbShareAccess`), the NTFS permissions of the root and those set on the
   subfolders down to `-Depth` levels (`Get-Acl`), the inheritance and the owner.
   `-Share Accounting,Projects` limits the reading to some shares; `-ResolvePrincipalType` asks
   Active Directory whether each account is a user or a group. An export from another tool is
   accepted if it has columns such as `Server`, `Share`, `Folder`, `Identity`, `Rights`,
   `AccessType`, `IsInherited` and `InheritanceProtected`; **Download an example file** shows one.
3. Import the file. Accounts are matched with the GLPI users (login) and groups (name). A new import
   of the same server shows the **rights added and removed** since the previous one; the history is
   in **Imports and changes over time**.
4. The list of shares starts with the most exposed. The gaps reported are: **everyone can modify**
   (Everyone, Authenticated Users, Domain Users or Guests with a modify right), **deleted** (unresolved
   SID or account in the GLPI trash) or **disabled account** that still has rights, **right given to
   a person** rather than to a group, **broken inheritance** on a subfolder of a sensitive share,
   **share without an owner**.
5. Open a share: classify it (confidentiality, integrity, availability) and designate its
   **owner**. The weighted score adds 3 for each serious gap and 1 for the others, multiplied by 3
   for a share classified high and by 2 for medium.
6. Click **Start a review**: the owner decides for each right (keep, change or remove, with a comment
   for the last two), then **Close the review**. Decisions are recorded with their author and date.
   The administrator applies the changes on the server; the next import shows the removed rights.
7. On controls A.5.15, A.5.18 and A.8.3, the "File share access rights" box shows the number of
   shares, those with a gap, the rights that let everyone modify, those of deleted or disabled
   accounts and the shares reviewed in the last twelve months.

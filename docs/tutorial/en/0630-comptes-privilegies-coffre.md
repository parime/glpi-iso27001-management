### Follow the privileged accounts of the password vault

Shared accounts and equipment passwords must be kept in a password vault, shared on a need-to-know
basis and changed regularly (controls A.5.17 and A.8.2). The page **GRC > Access and accounts >
Privileged accounts** matches your vault (Bitwarden, Vaultwarden or a privileged access management
solution) with the GLPI accounts. Only **metadata** is read: never a password.

1. In **Setup > Plugins > GRC Manager**, **People and policies** tab, choose the **expected change
   delay** of shared passwords (12 months by default).
2. With Bitwarden Teams or Enterprise, **Connectors** tab, **Connection to Bitwarden** block: choose
   the server, paste the client ID (`organization.…`) and the client secret of the organization API
   key, then **Test the connection**. On the page, **Read now from Bitwarden** fetches the members,
   their role, their two-step login and their collections.
3. With Vaultwarden or a PAM solution, import the members as CSV (columns `email`, `type`,
   `status`, `collections`, `twoFactorEnabled`).
4. Import the vault items as CSV **without the passwords**: `name`, `collectionIds`, `username`,
   `uri`, `passwordRevisionDate`. With Bitwarden or Vaultwarden, the `bw list items` command line
   gives these fields; keep only these columns. A file with a password, code, note or custom field
   column is refused as a whole.
5. Follow up the reported gaps: password not changed within the delay, person who left and still
   has access, member unknown in GLPI, vault administrator without two-step login, server controller
   administrator account (iLO, iDRAC) missing from the vault. Fix them in the vault; the plugin
   changes nothing.
6. The same gaps appear in the **Privileged accounts** tab of each access review campaign, and as
   evidence on controls A.5.17 and A.8.2.

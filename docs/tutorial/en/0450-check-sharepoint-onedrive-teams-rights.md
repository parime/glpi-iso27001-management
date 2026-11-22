### Check the access rights of SharePoint, OneDrive and Teams (controls A.5.15, A.5.18 and A.8.3)

Documents are no longer only on file servers: the auditor also looks at the sharing links and the
guests of Microsoft 365. The plugin reads the rights through Microsoft Graph, read only, and never
changes anything in Microsoft 365. You need the plugin right and the right on the sensitive
registers (with modification to read or import).

1. Set up the Microsoft Graph application once in Setup > Plugins > GRC Manager, tab
   **Connectors**, box **Microsoft Entra ID and Microsoft Graph** (tenant, application ID, secret)
   (see step {{step:0400-measure-multi-factor-authentication-coverage}}). Also grant it, with
   admin consent, the `Sites.Read.All`, `Files.Read.All`, `User.Read.All` and `GroupMember.Read.All`
   application permissions.
2. Open Access review > **File share access rights**, box **Read SharePoint, OneDrive and Teams
   rights**. Click **Read SharePoint now**, **Read OneDrive now** or **Read Teams now**.
   - SharePoint: the rights of each site document library and of its top-level items shared
     separately;
   - OneDrive: the top-level items each person has shared (the owner's own rights are ignored);
   - Teams: owners, members, guests and visibility of each team.
   If Microsoft Graph refuses access or the tenant is too large for one read, nothing is replaced
   and a message says what to do.
3. Without access to Microsoft Graph, import a CSV file (one line per right, columns such as
   `Source`, `Site`, `SiteUrl`, `Item`, `Identity`, `Type`, `Roles` and `LinkScope`); **Download an
   example file** shows one.
4. Sites, OneDrives and teams join the list of shares, with the same gaps and the same score, plus
   two gaps: **public link** (anyone who has the link, without signing in) and **external guest**. A
   "people in the organization" link and a public team count as "everyone"; a named member of a team
   is not a gap.
5. Classify each site, designate its owner and start the review as for a Windows share. The
   decisions are then applied in Microsoft 365; the next read shows the removed rights.

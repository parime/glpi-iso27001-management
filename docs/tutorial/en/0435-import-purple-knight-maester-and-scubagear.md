### Add Purple Knight, Maester and ScubaGear to the security audit (controls A.5.15 to A.5.18, A.5.23, A.8.2 and A.8.5)

The **Active Directory security audit** page also reads the files of three other free tools. The
type of file is recognised automatically; the plugin runs no tool and never queries the directory
or the Microsoft 365 tenant.

1. **Purple Knight** (Active Directory, indicators of exposure and of compromise): after the scan,
   save the security indicators as CSV, then import that file. If the export has no domain column,
   fill in the **Domain (if the file gives none)** field. The Purple Knight score goes from 0 to 100,
   100 being the best: the page reverses it (100 minus the score) to keep the same reading as
   PingCastle, 0 being the best.
2. **Maester** (Entra ID and Microsoft 365): run `Invoke-Maester`, then import the
   `TestResults-<date>.json` file of the `test-results` folder. **ScubaGear**: run `Invoke-SCuBA`,
   then import the `ScubaResults_<identifier>.json` file of the output folder. The score of the page
   is the share of checks that failed.
3. Each card shows the badge of the tool. The details give the number of checks passed, then the
   list of **checks failed** with their severity, their category (account security, delegation,
   Kerberos, Entra ID identity, Microsoft 365 services...) and the Annex A controls concerned
   (A.5.23 for cloud services). The **Propose an action** and **Open a ticket** buttons work as for
   PingCastle.
4. Only the score and the failed checks are kept: never the list of accounts, the raw configuration
   of the tenant (`Raw` section of ScubaGear), the account that ran Maester, nor any text that looks
   like a password. Files that are too large, too deep or malformed are refused. Only the PingCastle
   report can be archived as a restricted document.
5. For an Entra ID tenant, a box points to the **MFA coverage** page: Maester and ScubaGear check the
   configuration (conditional access, authentication methods, roles), while the MFA coverage account
   by account comes from that page (Microsoft Graph or CSV import). The Active Directory security
   score of the dashboard and of the risk indicators only counts PingCastle and Purple Knight.

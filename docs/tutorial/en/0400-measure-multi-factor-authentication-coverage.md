### Measure the multi-factor authentication coverage (controls A.8.5 and A.5.17)

The auditor asks which accounts, and above all which administrators and remote access accounts,
are protected by multi-factor authentication (MFA). The plugin reads GLPI two-factor authentication
and the methods you import from your identity provider; it changes nothing and never reads any
secret. You need the plugin right and the native right to read users.

1. Open Access review > **MFA coverage**. The "What is this page for?" box recalls what is expected.
2. The counters give the MFA rate of all the active accounts of your entities, of the
   **administrators** (profile that can change the GLPI configuration or the profiles, or
   administrator role reported by the provider) and of the **remote access accounts**.
3. In **Methods registered with your identity provider**, choose the provider (Microsoft Entra ID,
   Okta, Google Workspace, on-premises solution) and import its CSV export. For Entra ID:
   Protection > Authentication methods > User registration details, download. For another tool:
   one line per account with the columns `login` or `email`, `methods` (or `mfa` yes/no), and
   optionally `admin`, `remote_access`, `excluded`, `exclusion_reason`. Accounts are matched on the
   login, the email address or the part of the UPN before the @; those that match no GLPI account
   are listed separately. For Entra ID, you can also read the report directly: register an
   application in the Entra admin center, grant it the AuditLog.Read.All application permission
   with admin consent and create a client secret. Then open Setup > Plugins > GRC Manager, tab
   **Connectors** (or the **Set up the connection** button of the **Read Microsoft Entra ID
   directly (Microsoft Graph)** box), enter the tenant, the application ID and the secret in
   **Microsoft Entra ID and Microsoft Graph** and save; **Test the connection** checks them without
   reading anything. Back on the page, **Read now from Microsoft Graph** replaces the Entra ID
   import of the entity; nothing is written in Entra ID.
4. Choose the GLPI groups of the VPN or remote desktop users in Setup > Plugins > GRC Manager, tab
   **People and policies**, box **Which accounts have remote access?** (the **Change the
   settings** button of the page leads there).
5. The list starts with the **gaps**: administrator without MFA, remote access without MFA, SMS,
   call or email only, account excluded without justification. Fix them in your identity provider,
   then import a new export.
6. On controls A.8.5 and A.5.17, the "MFA coverage" box shows the rates, the number of
   administrators without MFA and the date of the computation. The dashboard offers the card
   "Administrators protected by MFA (%)", risk indicators the source "Administrators without MFA",
   and the Account hygiene page recalls their number.

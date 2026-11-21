### Prove that important assets are backed up (controls A.8.13 and A.5.30)

The plugin compares the machines known by your backup tools with the computers and network devices
of GLPI. It only reads: it never starts a backup or a restore and changes nothing in the tool nor in
GLPI. You need the plugin right and the native right to read computers.

1. Open Technical security > **Backup coverage**. The "What is this page for?" box recalls what is
   expected.
2. In **Which assets must be backed up, and how often?**, choose the assets concerned (by default
   critical assets and servers), the default maximum age of the last successful backup (48 hours)
   and how often restores must be tested (12 months). The maximum age of an asset is the smallest
   RPO among the asset (C/I/A classification tab) and the business processes that use it; without
   any RPO, the default value applies.
3. For **Veeam Backup & Replication**, create a dedicated account with the "Veeam Backup Viewer"
   role (read only) in the Veeam console, then enter the address of the server (for example
   `https://veeam.example.test:9419`), the account, its password and the REST API revision. The
   password is encrypted with the GLPI key and never displayed again. **Read now from Veeam** reads,
   on your click only, the restore points of the last 31 days and the last result of each job. A
   refused or incomplete reading replaces nothing. A server with an internal address requires an
   administrator to allow internal addresses in the configuration page of the plugin, and its
   certificate must be trusted by the GLPI server.
4. For the other tools (Acronis, Commvault, Synology Active Backup, Proxmox Backup Server...), export
   as CSV the list of protected machines with the date of their last backup, then import it in
   **Backup tools**. Recognized columns: machine (`host`, `vm`, `device`, `client`, `backup-id`...),
   `ip`, job, last success (`last success`, `last backup`, `backup-time`), date of the run and
   result. A log with one line per run is accepted: the most recent run of each machine counts. A new
   import of the same tool replaces the previous one.
5. The list **Assets that must be backed up** starts with those without any backup, then those whose
   backup is too old, then those whose last attempt failed. It gives the maximum age and where it
   comes from, and the last restore test taken from the recovery exercises: record the test on the
   process ("Recovery strategy" tab), naming the asset tested. Backed up machines that GLPI does not
   know are listed apart.
6. On controls A.8.13 and A.5.30, the "Backup coverage" box shows the rate of recent backups, the
   critical assets without a recent backup, the failures and the critical assets without a recent
   restore test. Risk indicators offer the source "Critical assets without a recent backup (%)".

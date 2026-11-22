### Complete the Security 360° view with the other modules

The Security 360° view (see step {{step:0460-security-360-view-of-an-asset}}) grows as the plugin modules are used: each
source only appears once its data has been imported.

1. **Classification**: classify the asset (**C/I/A classification** tab). An unclassified asset is shown
   "to watch", as its criticality sets the backup, log and patch delays.
2. **Patches**, **end of support** and **warranty**: read from the GLPI inventory and the end of
   support cache already filled; a manufacturer end of life reached is critical.
3. **EDR**: with the SentinelOne connector, the tab shows whether an active agent protects the
   computer and whether alerts are waiting to be reviewed.
4. **Monitoring**, **backups** and **logs**: after a Zabbix reading or an import from the backup and
   log collection tools, the asset shows its status and the link to the module page.

The overall score takes every evaluated source into account; nothing is changed from the tab.

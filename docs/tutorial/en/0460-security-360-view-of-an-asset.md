### See the security of an asset at a glance (Security 360° view)

When an auditor picks a server at random, the **Security 360°** tab of the computer (or network
device) brings together what the plugin knows about this asset.

1. Open a computer, for example `SRV-DEMO-01`, then the **Security 360°** tab.
2. The banner gives the overall level (good, to improve, weak) and a score out of 100: weighted
   average of the assessed sources (compliant 100, to watch 50, critical 0). A single critical
   source prevents the good level.
3. Each line summarises one source with its badge: known vulnerabilities, disk encryption,
   configuration compliance (OpenSCAP), management controller, security incidents, linked risks.
   The **View the details** link opens the tab or page of the module to fix the gap.
4. A source without data for this asset (module not used, nothing imported, no right) is not
   shown: first import or read the data of the relevant modules.

Nothing is changed from this tab: it is an entry point, fixes are made in the modules.

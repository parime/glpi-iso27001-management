### Check that important assets send their logs (controls A.8.15, A.8.16 and A.8.17)

The plugin compares the list of hosts known to your log platforms with the computers and network
devices of GLPI. It only reads: it runs nothing on the platforms and changes nothing in GLPI. You
need the plugin right and the native right to read computers.

1. Open Technical security > **Logging coverage**. The "What is this page for?" box recalls what is
   expected.
2. In **Which assets must send their logs?**, choose the assets concerned (by default critical
   assets, servers and network devices), the number of days without event after which the logs are
   considered stopped (7 by default) and the retention required by your policy (180 days by
   default). An asset is critical when one axis of its C/I/A classification is high or when a
   network flow publishes it on the Internet. These settings are made in Setup > Plugins > GRC
   Manager, **Fleet** tab (**Change in the configuration** button of the page).
3. In **Log sources**, import the list of hosts of each platform: for example, in Splunk, the search
   `| metadata type=hosts index=*` exported as CSV (columns `host`, `lastTime`); in Graylog, Elastic
   or OpenSearch, a table of the sources with their last timestamp. When the Wazuh plugin is active,
   its active agents are read directly. A new import of the same platform replaces the previous one.
   Only the name, the address and the date of the last event are kept.
4. In **Retention and clock synchronization**, declare for each platform the retention, the
   obligation of the register that imposes a retention (and how long), and the time server. The
   retention required is the longest between the policy and the obligation; a badge flags a
   retention too short or not declared.
5. The list **Assets that must send their logs** starts with those that send nothing, then those
   whose logs stopped, critical assets first. Hosts that send logs but that GLPI does not know are
   listed apart: check their name or add them to the inventory.
6. On controls A.8.15, A.8.16 and A.8.17, the "Logging coverage" box shows the rate, the critical
   assets without logging, the platforms whose retention is not enough and the date of the
   computation. Risk indicators offer the source "Critical assets without logging (%)".

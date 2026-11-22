### Read the Zabbix monitoring (controls A.8.16, A.8.6 and A.5.30)

The auditor asks how servers and services are monitored, whether they are as available as planned
and whether their capacity is followed up. The plugin reads your **Zabbix server in read only** and
matches its hosts with the computers and network devices of GLPI; it never changes anything in
Zabbix. You need the plugin right and the native read right on computers.

1. In Zabbix, prepare a reading account: Users > User roles, a role of type User whose API is
   limited to host.get, problem.get, event.get, service.get, sla.get and sla.getsli; Users > Users,
   a user with this role in a group that can read the host groups to monitor; Users > API tokens, a
   token for this user.
2. Open Setup > Plugins > GRC Manager, **Connectors** tab (or the **Set up the connection** button of
   Technical security > **Zabbix monitoring**). In **Connection to Zabbix (read only)**, enter
   the address of the Zabbix interface (for example `https://zabbix.example.test/zabbix`) and the
   token, choose the entity of the readings and save; **Test the connection** checks the address and
   the token without reading anything. The token is encrypted by GLPI and never
   displayed again. A server on an internal address requires allowing internal addresses (plugin
   configuration > Outgoing connections).
3. On the **Zabbix monitoring** page, click **Read now**. A failed reading does not replace the previous one; the box "Read
   automatically every day" enables the automatic action "zabbix", disabled by default.
4. In **Which assets must be monitored?**, choose the concerned assets (critical assets, servers
   and network devices, by default) and the period of the problems.
5. The counters give the share of monitored assets, the **critical assets not monitored**, the open
   high-severity problems, the lowest service availability and the saturated assets (disk, memory,
   processor). The asset list shows for each one its monitoring, measured availability, open
   problems and longest outage compared with the RTO of the business processes that depend on it.
6. In **Problems over the last N days**, a high-severity problem can be **proposed as a security
   incident** or turned into a **ticket**: it is only created when you click.
7. In **Availability of the services (Zabbix SLA)**, link each service to the business process it
   supports. Zabbix hosts not matched with an asset are listed separately.
8. On controls A.8.16, A.8.6 and A.5.30, the "Zabbix monitoring" box shows the coverage, the
   saturations or the availability, with the date of the reading. Computers, network devices and
   business processes have a "Zabbix monitoring" tab, and risk indicators offer three sources:
   service availability of the month, open high-severity problems, critical assets not monitored.

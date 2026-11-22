### Find asset security in the Assets menu

The technical security screens are in **Assets > Asset security**, next to computers and network
devices.

1. Open **Assets > Asset security**. The home page shows the screens as cards, by theme:
   - **Vulnerabilities**: fleet vulnerabilities, most exposed machines, watched products;
   - **End of life and patches**: software to watch (end of support read on endoflife.date), hardware
     end of life, late patches, licences;
   - **Hardening**: automated technical checks, configuration compliance, disk encryption, Active
     Directory audit;
   - **Monitoring**: logging, Zabbix, Uptime Kuma, server management controllers;
   - **Backups and network**: backups, mail and domains, network flow matrix;
   - **Sites and development**: physical security, secure development, code security.
2. The tab bar at the top of each page shows one tab per theme; the second bar shows the pages of the
   open theme. The breadcrumb tells you where you are: "Assets > Asset security > …".
3. For the **end of life announced by the manufacturer** of a model, open the form of the model (for
   instance Setup > Dropdowns > Computer models), tab **Manufacturer end of life**, and enter the
   date. To date all the models in service at once: **Assets > Asset security > Hardware end of
   life**. The warning threshold ("end of life soon") is the one of the end of support, in the plugin
   configuration, Connectors tab.
4. The action buttons left the configuration: **Update now** (endoflife.date) and the links between a
   GLPI name and a product are on **Software to watch**; **Send the report now** is on **Fleet
   vulnerabilities**.

The new menu appears at your next login.

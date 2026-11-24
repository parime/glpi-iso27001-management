### Map the information system by layer, from the switch to the business process

The map links what GLPI and GRC already know, without drawing anything by hand:

1. Open **Risk analyses > IT system map**.
2. With nothing chosen, the page offers two starting points: your **business processes** ("What
   does this process depend on?") and your **network equipment** ("What does the failure of this
   equipment affect?"). Click one of them, or choose another starting item (computer, software,
   appliance, database instance) and the question, then **Show the map**.
3. Upwards, the page first lists the **business processes affected, most urgent first** (RTO of
   the business impact analysis), then the drawing: one band per layer, processes at the top and
   the network at the bottom. Each box links to the item; the list by layer below the drawing
   repeats every item.
4. A link is missing? Complete it where it is described: cable between two ports (item form,
   **Network ports** tab), virtual machine (same UUID as the computer), installed software, item of
   an appliance, dependency of a process (**Dependencies** tab of the process) or GLPI impact
   analysis.

Two connected switches do not spread the impact (the plugin does not know about redundancy):
declare the dependency in the GLPI impact analysis if it exists. An item you cannot see (other
entity, missing right) is counted without being named.

To go further:

- click a box of the drawing to **start again from that item**; untick **layers** to lighten the
  map;
- tick **Application flows (flow matrix)** to add the flows between applications and servers
  (dashed lines): the client depends on the server it calls;
- **Export (CSV)** and **Export (PDF)** give the map to attach to the BCP/DRP or to the audit file;
- the same map is in the **IT system map** tab of each computer, network equipment, application and
  business process;
- the **VLAN and IP networks** band shows the logical network, read in GLPI: VLAN of the network
  ports, IP networks of the addresses, **Network** field of the assets and of the items of an
  application. A VLAN or an IP network can also be the starting item ("What does the failure of this
  VLAN affect?");
- fill in the **environment** of each application in GLPI (Assets > Appliances: production, test,
  pre-production) with its servers and instances (**Items** tab): the map sorts them in the
  **Applications by environment** table, and the **Application environment** choice keeps only the
  applications of that environment, for instance to see the impact of a failure on production
  alone.

### Inventory and check the server management controllers (controls A.8.2, A.8.9 and A.8.22)

Remote management controllers (HPE iLO, Dell iDRAC, Lenovo XClarity Controller, Supermicro IPMI)
give full control of a server, even switched off. The plugin reads them **in read only** through
Redfish: it only sends read requests (GET), restarts nothing, updates no firmware and changes no
setting. You need the plugin right and the native read right on computers; the right to update the
plugin to declare, read and import.

1. On each controller, create a local account with the **read-only** role (ReadOnly), dedicated to
   the plugin.
2. Controllers nearly always have an internal address: in the plugin configuration, section
   **Outgoing connections**, allow internal addresses (the page reminds you until it is done).
3. Open Technical security > **Server management controllers**. In **Declare a controller read by
   Redfish**, enter its name, its `https://` address, the account and its password. The password is
   encrypted by GLPI and never shown again; it must be entered again if the address or the account
   changes. Leave **Server in GLPI** empty to match the server by its serial number.
4. A controller with a self-signed certificate is refused until you tick **Accept a self-signed
   certificate for this controller**: the option is off by default, applies to this controller only,
   and the "Certificate not verified" gap stays reported.
5. Click **Read now**. The plugin reads the model, the firmware of the controller, the BIOS, the
   serial number and the health of the server, the open protocols, the HTTPS certificate, Secure
   Boot and the local accounts. A failed reading does not replace the previous one: the cause is
   shown next to the last successful reading.
6. For a controller without Redfish (IPMI only), import a CSV file in **Import controllers without
   Redfish**, one line per controller, for example:
   `name;address;model;firmware;serial;ipmi_lan;weak_tls;accounts` then
   `bmc-srv03;192.0.2.30;X11;3.10;SM123456;yes;no;ADMIN:Administrator`.
7. The list **Controllers and their gaps** starts with the serious gaps: IPMI over the network,
   default account enabled, address outside the administration networks, Telnet, old protocols,
   expired certificate. Hover a gap to see what to do, then fix it on the controller: the plugin only
   reports it.
8. The exposure is checked against the network flow matrix: write the range of the administration
   network in the source or the destination of a flow whose name says it is an administration zone,
   for example `MGMT 10.0.99.0/24`.
9. **Local accounts of the controllers** lists the accounts and their role, to take into the
   privileged access review. **Physical servers without a known controller** lists the servers of
   GLPI (by their type or operating system, virtual machines excluded) whose management controller
   nobody follows.
10. On a computer, the **Management controller** tab sums up its controller. On controls A.8.2,
    A.8.9 and A.8.22, the "Server management controllers" frame gives the figures of the evidence,
    and the key risk indicators offer the source "Server management controllers with a serious gap".

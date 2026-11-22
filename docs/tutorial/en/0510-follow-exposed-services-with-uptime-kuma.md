### Follow the availability of the exposed services and their certificates with Uptime Kuma (controls A.8.16, A.5.30 and A.8.24)

Uptime Kuma regularly checks that your websites and services answer, and reads the expiry date of their
TLS certificates. The plugin reads it **in read only**: nothing is changed in Uptime Kuma and no
certificate is renewed.

1. In Uptime Kuma, menu **Settings > API Keys**, create a key (it only gives access to the `/metrics`
   page, to read). You can also, or instead, list public status pages.
2. In GLPI, open **Technical security > Exposed services (Uptime Kuma)**, frame **Connection to Uptime
   Kuma (read only)**: enter the address (for example `https://status.example.test`), the API key and,
   if needed, the status pages (end of their address, for example `customers`), then **Save** and
   **Read now**. A server on an internal address must be allowed in the plugin configuration
   (**Outgoing connections**).
3. The **Monitored services** table shows each monitor: its status, its availability over the period,
   the GLPI asset recognised (computer, network device or appliance, by the monitored host name) and the
   longest outage, compared with the RTO of the business processes that depend on the asset. Choose a
   business process in the list of the row for a monitor without an asset.
4. For a long outage, click **Propose as security incident**: the incident is created and linked to the
   asset; the **Announce to the users** badge then opens its crisis communication. Nothing is created
   without your click.
5. The **TLS certificates** frame ranks the certificates expired, not valid or expiring soon (threshold
   set in **Thresholds**). To be warned, create a risk indicator "TLS certificates to renew (Uptime
   Kuma)" with an alert threshold.
6. The **Assets published on the Internet without a monitor** frame lists the assets of the network flow
   matrix that nobody watches.
7. For regular readings (the availability is then more precise), tick **Read automatically every
   hour**: the automatic action `uptimekuma` is disabled after the installation.

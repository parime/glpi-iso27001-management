### Spot old TLS protocols on the server controllers (controls A.8.9 and A.8.24)

Each Redfish reading of a controller ({{step:0440-review-server-management-controllers}}) also looks
for the TLS versions accepted by its HTTPS interface. Nothing is changed: only read requests (GET) to
the configured address.

1. Click **Read now** on the **Server management controllers** page.
2. The **Old protocols (SSLv3, TLS 1.0 or 1.1)** gap shows when the controller still accepts one.
   Depending on the vendor, the setting comes from the iLO security service (HPE), the
   `WebServer.1.TLSProtocol` attribute of the iDRAC (Dell), the Oem section of the XClarity
   Controller (Lenovo) or of another controller, or the standard Redfish security policy.
3. The reading account must be allowed to read these settings. If it is not, or if the firmware does
   not publish them, the value stays **unknown**: then fill in the `weak_tls` column of the CSV
   import.
4. Fix it on the controller itself (TLS 1.2 and later only), then read it again: the gap goes away.

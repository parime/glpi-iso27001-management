### Follow the health of the servers and the log of their controller (controls A.7.13 and A.8.16)

Once the controllers are read ({{step:0440-review-server-management-controllers}}), each Redfish
reading also takes the detailed health of the server and the latest events of the controller log.
Nothing is sent to the server: only read requests (GET) to the configured address.

1. Click **Read now** on the **Server management controllers** page.
2. Open the computer, **Management controller** tab, **Server health** box: power supplies, disks (a
   predicted failure counts as a warning), fans and temperatures, degraded components first, then the
   recent events of the log (IML, SEL or Lifecycle depending on the vendor). Empty slots are not
   listed.
3. The **Security 360°** view of the computer adds the **Server health** source: critical if a
   component or an event of the last seven days is, to watch for a warning.
4. For a daily reading, enable the automatic action `bmcread` (link **Set the automatic action** on
   the controllers page). It is disabled after installation; a failed reading keeps the previous one.

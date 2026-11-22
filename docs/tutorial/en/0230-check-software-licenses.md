### Check software licenses (control A.5.32)

The plugin reads the software and licenses already recorded in GLPI (Assets > Software, Licenses
tab); it changes nothing. You need the "Sensitive registers" right of the plugin, and the native
rights to read software and licenses.

1. Open Technical security > **Software licenses**. The "What is this page for?" box explains what
   is expected.
2. At the bottom of the page, check the list of **watched commercial publishers** (Microsoft, Adobe,
   Oracle, Autodesk...): it is the "Manufacturer" field of the software in GLPI. The second list
   leaves out the free tools of these publishers ("Redistributable", "Acrobat Reader"...). Only a
   person who can change the plugin configuration can edit these lists, with the **Change in the
   configuration** button (Setup > Plugins > GRC Manager, **Fleet** tab).
3. The counters give the number of findings, then grouped by entity:
   - **Commercial software without a license**: software from a watched publisher is installed, and
     no license counts for this entity. A license counts for an entity when it belongs to it, or when
     it is recursive and belongs to a parent entity.
   - **More installations than licensed seats**: for instance 12 computers for a 10-seat license. An
     unlimited license (number 0 or less) covers everything; an expired license no longer counts. A
     recursive license is shared by the sub-entities: "Shared with the sub-entities" counts their
     installations together. A child license ("As child of" field in GLPI) shares the seats of
     its parent: the family counts for the larger of the parent number and the sum of the children,
     with the **Child licenses counted once** badge.
   - **Expired license still in use**: computers are still assigned to it, or the software is still
     installed with no other valid license.
   - **License expiring soon** (a reminder, not a gap): a license in use expires within the next 60
     days and no renewal is recorded. Change this delay in the Fleet tab of the configuration ("Warn
     before a license expires", 0 to turn off).

   The **Over-quota allowed** badge marks an over-deployment on a license that allows it: it still
   has to be settled with the publisher. Above the findings, the **license coverage** gives the share
   of installed watched commercial software that has a valid license.
4. For each finding, open the software or the license (links) and fix it in GLPI: record the
   license you bought, add seats, uninstall the software, or renew the license and move the assigned
   computers.
5. Click **Export (CSV)** to hand the list to the auditor.
6. On control A.5.32 of the SoA, the "Software license compliance" box shows the counters, the
   licenses expiring soon and the license coverage; the ISMS consistency checks (tab of the
   dashboard) list each gap.

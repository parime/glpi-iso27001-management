### Appoint the CISO and the DPO (ISMS roles)

Control A.5.2 asks for the information security roles to be defined and allocated. The plugin
knows two key roles, the **CISO** and the **DPO**; for each one, it keeps a GLPI group and a
generic e-mail address.

1. Create in GLPI (**Administration > Groups**) one group per role, for example "CISO" and "DPO",
   and add the holder and their substitute. Leave **Can be notified** set to Yes on the group
   (the default) so that it receives the e-mails.
2. Open **Setup > Plugins > GRC Manager**, **People and policies** tab, **ISMS roles** block. For
   each role, choose the group and enter the generic address (a shared mailbox or a list, for
   example `dpo@example.org`, never a personal address), then **Save**. An address with an
   invalid format is refused.
3. If you had entered the DPO in the former text field, the update took it over: the address found
   became the generic address, the rest (name, phone) appears in a **To be reworked** note. Choose
   the group, then tick **The note has been reworked: delete it**.
4. Check the result in **GRC & Compliance > ISMS organisation > Responsibility matrix**: both
   roles, the active members of their group and their addresses are at the top of the page and in
   the PDF of the matrix, to show to the auditor.

The CISO group reads the documents of all internal audits; the record of processing activities
and the register of data breaches show the DPO group, its members and the generic address. For
the **New data breach exposures** notification (**Setup > Notifications**), add the **DPO group
(GRC Manager)**, **DPO generic address (GRC Manager)** or **CISO generic address (GRC Manager)**
recipients if needed.

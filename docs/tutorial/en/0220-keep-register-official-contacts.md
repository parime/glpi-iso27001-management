### Keep the register of official contacts (controls A.5.5 and A.5.6)

1. Open Compliance > **Official contacts** (tab next to "Context of the organization"). The "What is
   this page for?" box explains what is expected.
2. Click **Add an official contact**. The record is an interested party of the context, already
   ticked "Official contact": there is no second register to maintain. An existing party (an
   insurer, a hosting provider recorded as a supplier) becomes an official contact by ticking the box
   on its record.
3. Fill in for instance "Data protection authority (fictitious)": category "Supervisory authority",
   contact details (phone, online form), **when to contact** ("Personal data breach with a risk for
   the persons, GDPR article 33"), **time limit** ("72 hours"), the authorised internal contact (the
   DPO) and, as a backup, a group (the crisis team). Tick "To contact for a personal data breach".
4. Add the national CSIRT and the police the same way (tick "To contact for a security incident"),
   your insurer and the special interest groups you follow (category "Special interest group",
   evidence for control A.5.6).
5. After checking the details, click **Record the verification**: the date and your name are kept in
   the history. A contact not verified for 12 months is flagged in the register, and its owner gets
   a reminder (at most once a month).
6. Click **Print the register (PDF)** and keep the copy with the crisis management plan: it stays
   usable when GLPI is down.
7. From a security incident or a personal data breach, the **Official contacts** tab shows the
   contacts to inform, with the time limit and the authorised person. Only contacts of the entity of
   the incident or of a parent entity (record visible in sub-entities) are listed.
8. On controls A.5.5 and A.5.6 of the SoA, the "Register of official contacts" box shows the number
   of contacts, verified or to verify, with links to the register and its PDF.
9. Also fill in the contact's **time limit in hours** (72 for the data protection authority, 24 for
   the early warning to the CSIRT): the **Official contacts** tab of an incident or a breach then
   shows each contact's deadline, counted from the detection of the incident or the awareness of
   the breach.
10. Once you have called or written to the contact, click **Record a contact** on its line: date and
    time, file number (for example "DOSSIER-FICTIF-001"), comment. If you decide it did not need to
    be informed, choose "Not required (decision)" and give the reason. The badge becomes "Contacted
    on time", "Contacted late" or "Not required (decision recorded)". Nothing is sent by the plugin.
11. The contact's record has a **Recorded contacts** tab: the history of your relations with this
    authority or special interest group, to show to the auditor.
12. When a contact only matters for some incidents (the sector CERT for malware, for example),
    choose these types in **Only for these types of incident**: it is then listed only on incidents
    of this type. Left empty, it is listed on every incident. An incident of the "Data breach" type
    also lists the contacts to inform for a personal data breach.
13. In the **Crisis communication** tab of an incident, a template whose distribution list holds an
    official contact offers the box **Also record it as a contact with the ticked official
    contacts**: recording the message you sent also records the contact (badge "From a crisis
    communication" in the Official contacts tab).
14. After each review of the register, click **Archive on controls A.5.5 and A.5.6**: the dated PDF
    is attached to both controls of the SoA (Documents tab), and the latest archived registers are
    listed at the top of the register.
15. The official contacts also appear at the end of the **business continuity plan (PDF)** of the
    business processes, and in the crisis room of an exercise or of a real crisis ("Official
    contacts to inform" box, included in the exercise report).

### Assess the physical security of the sites (controls A.7.1 to A.7.14)

1. The sites are the **locations of GLPI** (Setup > Dropdowns > Locations): create for instance
   "Fictitious head office, 12 Test Street, Sampletown" and "Fictitious warehouse, 4 Demo Lane".
   There is no second list of sites.
2. Open Compliance > **Physical security**, then **Assess a site**. Choose the site, the date and
   the assessor (who receives the reminder), and link the **physical and environmental security
   policy**: it stays a document of the policy library.
3. Once the assessment is saved, the **Physical security checklist** tab has one line per control
   A.7.1 to A.7.14 ("Not applicable" from the start for a control excluded from the SoA). For each
   line, click **Fill in**: state (compliant, partially compliant, non-compliant, not applicable),
   finding, evidence examined. Add the photos and reports to the "Documents" tab of the assessment,
   then cite them on the line: they can only be read with the right to read the assessment.
4. For a partial or non-compliant line ("Server rack door not locked", for instance), click **Create
   the action**: a pre-filled non-conformity opens (site, control, finding). Set the owner and the
   due date, then **Add**: the usual CAPA workflow takes over and the line shows the action and its
   status.
5. The **Zones of the site** tab lists the zones (public, controlled, sensitive, technical room),
   their access controls (badge, key, door code, video surveillance...), the authorized people and
   whether the access instructions are posted. A sensitive area or a technical room without access
   control or without instructions is flagged.
6. Set the assessment to **Completed**: the next assessment is set 12 months later. The automatic
   task "reassessmentreminder" warns the assessor 30 days before, then once a month until the site
   is assessed again; the date is also in the compliance calendar. The reassessment takes over the
   zones and shows, line by line, the state of the previous assessment.
7. The **Physical security** page compares the last assessment of each site, control by control,
   with the score and the due date. On an A.7.x control of the SoA, the "Physical security of the
   sites" box gives the state of the control on each site. The GLPI location also has a "Physical
   security" tab with the history of its assessments.
8. For the auditor, click **Report (PDF)** at the top of the assessment form: summary (assessor,
   status, policy, score), A.7.1 to A.7.14 checklist with findings, evidence and actions, then the
   zones and their access controls. The report is computed on demand with your rights.

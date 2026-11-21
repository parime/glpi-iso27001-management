### Create an audit and a non-conformity, through to CAPA closure

From **GRC & Conformité > Audits et amélioration**, the list is empty until an audit exists.

![Empty internal audit list](screenshots/tutorial/17-audit-list-empty.png)

An audit is defined by a title, a status, a planned date and a free-text scope.

![Audit creation form](screenshots/tutorial/18-audit-form-filled.png)

![Saved audit](screenshots/tutorial/19-audit-saved.png)

The documents of the audit (audit plan, collected evidence, signed report) and of its findings can
only be read by its **team**: the auditor and the owner set on the form, the members added in the
**Équipe** tab (auditors, or read-only auditees) and the **CISO group** chosen in the plugin
configuration. The GLPI Documents right is not enough. The **Accès aux documents** tab lists every
download (date, user, document) and the **Notes** tab keeps dated working notes with their author.

**Internal audit according to ISO 19011** (ISO 27001 9.2):

1. **Multi-year programme**: the **Programme d'audit pluriannuel** page of the section shows the
   ISMS processes (the activities of the responsibility matrix) by year of the certification cycle
   (3 years, start year chosen on the page). A process in red has no audit over the cycle, in
   orange its audit is overdue. The ISMS requirements that no audit checklist of the cycle covers
   are listed too.
2. **Audited processes**: in the **Programme et indépendance** tab of the audit, choose the processes
   it audits.
3. **Independence**: an auditor (the auditor of the form or an auditor of the team) who performs or
   approves an audited process in the responsibility matrix, directly or through a group, is in
   conflict. The audit cannot be started or closed until each conflict has a justification, kept
   with its author and date and in the history of the audit.
4. **Competence**: choose on the programme page the trainings that qualify an internal auditor
   (for example "ISO 27001 internal auditor"). The **Équipe** tab then shows for each auditor
   whether they are qualified, have to renew the qualification, or have none.
5. **Report to management**: enter the delivery date of the report and its recipient in the same
   tab. Both appear in the management review dossier (9.3.2 d 3).
6. **Objectives and criteria**: on the audit form, fill in its **Objectives** (what the audit must
   establish) and its **Audit criteria** (clauses of the standard, policies, procedures the evidence
   is compared with), next to the scope.
7. **Checkpoints**: the **Audit checkpoints** box, at the top of the **Programme et indépendance**
   tab, shows in colour what the certification auditor will check: objectives, scope and criteria
   defined, processes chosen, auditors appointed, qualified and independent, report delivered to
   management. The programme page shows these points for each audit of the cycle and flags the
   incomplete ones. The PDF report of the audit includes them too, with the competence of the
   auditors and the justification of each conflict.
8. **Independence through the audited controls**: a process whose responsibility matrix lists an
   Annex A control that the audit covers (a "control" line of the audit checklist, or a control
   linked on the form) is checked too, even if it is not chosen in the tab. The conflict shows the
   controls concerned ("Through the audited controls A.8.13") and is justified the same way.
9. **Plan from the programme**: on the programme page, the **Planifier** button of a cell with no
   upcoming audit (this year or a later one) creates an internal audit for that process and year,
   in the entity of the process, and opens its form. Then complete its objectives, scope, criteria
   and auditors.

From the section's **Non-conformités** tab, a non-conformity can be linked to this internal audit, with a
severity, a due date, a description and a root cause.

![Empty non-conformity list](screenshots/tutorial/20-nonconformity-list-empty.png)

![Non-conformity form linked to the audit, open status](screenshots/tutorial/21-nonconformity-form-open.png)

The CAPA cycle (ISO/IEC 27001:2022 clause 10.2) requires a corrective action before a
non-conformity can be closed or verified: trying to switch to "Verified" status without a
corrective action blocks the save.

![Switching to Verified before saving, empty corrective action](screenshots/tutorial/22-nonconformity-closure-before-save.png)

![Error: corrective action required](screenshots/tutorial/23-nonconformity-closure-error.png)

Once the corrective action (and, where relevant, the preventive action) is filled in, the
non-conformity can be verified: the closure verification date is then automatically stamped with
today's date if it was left blank.

![Corrective and preventive action filled in, Verified status](screenshots/tutorial/24-nonconformity-capa-filled.png)

![Closed non-conformity, auto-stamped verification date](screenshots/tutorial/25-nonconformity-closed.png)

### Keep the register of operating procedures (control A.5.37)

1. Open Compliance > **Operating procedures** (tab next to "Security policies"). The page shows the
   coverage of the expected domains: backup, restore, monitoring, logs, updates, deployment, access
   management, incident management, changes, maintenance. Each domain is "Up to date", "Review due",
   "Not approved" or "No procedure".
2. On the row of a domain without a procedure, click **Add the procedure**. The record is a document
   of the policy library, already in the "Operating procedure" category and in the right domain:
   there is no separate object. An existing document becomes a procedure by choosing this category
   on its record.
3. Fill in for instance "Server backup procedure (fictitious)", version 1.0, its owner, and attach
   the document in the "Documents" tab. Have it approved ("Approved" status with its date, or the
   "Approval" tab when the workflow is enabled), then set the **next review**: without a review
   date, or once the date has passed, the domain shows "Review due".
4. In the **Reading acknowledgement** tab, add the groups or profiles that carry out the procedure
   (the operations team, for instance) to the audience, then launch the campaign: the register then
   shows the reading rate of the current version.
5. The list of domains is set in the plugin configuration ("Expected domains of operating
   procedures" box): untick a domain that does not apply to you, add your own, one per line.
6. Before the audit, click **Archive on control A.5.37**: the register is saved as a dated PDF on the
   control. **Print the register (PDF)** downloads it without saving it. On control A.5.37, the
   "Register of operating procedures" box shows the coverage; the approval records and the
   acknowledgement reports of a procedure are archived there (those of a policy stay on A.5.1).
7. The text of the procedure can be a GLPI **knowledge base article**: on the procedure, "Knowledge
   base" tab, link the article (for instance "Server backup: step by step (fictitious)"). The
   register shows the article and the attached documents under the title; a procedure with neither
   shows the "No content" badge.
8. Every year, read the procedure again with its owner. If it is still right, click **Record the
   review** on its record: today's date and your name are kept, without a new version, and the next
   review moves one year later (or to the date you set). The register shows "Reviewed on ... by ..."
   and the audit log keeps every review. If it changed, prepare a new version.
9. The consistency check lists the expected domains without an approved procedure (rule A.5.37).
10. On a procedure, the **Review history** frame lists every recorded review, the most recent first:
    date, person who did it and next review planned. The PDF of the register repeats this history,
    procedure by procedure: the auditor sees that the review is done every year, not only the last
    one.

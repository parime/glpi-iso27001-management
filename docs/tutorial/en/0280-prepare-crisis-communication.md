### Prepare crisis communication (control A.5.26, clause 7.4)

1. Menu **Assistance > Crisis and continuity**, **Crisis message templates** tab. Five templates
   are provided: major incident (first internal message and end of crisis), data breach
   (information of the people concerned), unavailability (message to users and customers) and
   ransomware (instructions to staff). Adapt them to your organization: subject, text, situation,
   stage and audience.
2. **Variables** are written between braces, for instance `{reference}`, `{title}`,
   `{detection_date}`, `{affected_systems}` or `{next_update}`: the template record lists them. They
   are replaced with the values of the incident when the message is prepared; a variable without a
   value becomes "[to be completed]".
3. In the **Distribution list** tab of the template, add the internal recipients (GLPI users and
   groups) and the external ones. External recipients are the **official contacts** of step {{step:0220-keep-register-official-contacts}}
   (insurer, authority, provider...): record a missing recipient in the register of official
   contacts first. A contact without details, a user without an email address or a group without
   members is flagged "No address recorded".
4. During a crisis, open the incident (for instance "File server encrypted (fictitious)"),
   **Crisis communication** tab. The template suggested for the situation of the incident comes
   first; the subject and the text are already filled in. Read it, replace each "[to be
   completed]", untick the recipients not to inform, and add other recipients if needed (one per
   line).
5. Click **Copy the message** and **Copy the email addresses**, or **Export (.eml file)** to open a
   draft in your mail client. **The plugin never sends any email**: you send the message yourself.
6. Once the message is sent, click **Record as sent** (correct the date if it was sent earlier). The
   communication is added to "Communications made" with the date, the author, the recipients, the
   subject and the text; it is also written in the crisis log of the incident (private followup,
   without notification) and shown in the timeline of the post-incident report. The same tab exists
   on the data breaches of the GDPR register.
7. Recording a communication requires the right to update the incident (or the breach); reading it
   is enough to see the tab. Official contacts are shown only with the right on the organization
   context, and only those of the entity of the incident or of a parent entity.
8. On control **A.5.26** and on ISMS requirement **7.4**, the "Crisis communication" box shows the
   number of active templates, the templates without recipients, the situations without a template
   and the communications recorded within 12 months. During a crisis exercise, use the same
   templates from the exercise incident.
9. **Deputies**: when adding a recipient to the distribution list, choose in "Role in the list" the
   main recipient it stands in for (for instance the deputy of the fictitious director). The deputy
   is shown under its main recipient; a main recipient without a deputy has the "No deputy" badge
   (a group does not need one). In the incident tab, a deputy is ticked by default only when its
   main recipient is hidden or has no address. Removing a main recipient turns its deputies into
   main recipients.
10. **Groups**: the email addresses of the active members of a group are included by "Copy the
   email addresses" and by the `.eml` export; members without an address are counted.
11. **Annual review**: once a year, read the distribution list of each template again, then click
   **Mark the list as reviewed today** (right to update the templates). The box of control A.5.26
   counts the lists not reviewed within 12 months and the main recipients without a deputy.
12. **Exercises**: the communications recorded on the incident of an exercise (or of a crisis team)
   are shown in the crisis room, with a "Prepare a message" link, and in the PDF report of the
   exercise.

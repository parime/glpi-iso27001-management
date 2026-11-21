### Describe the security crisis scenarios of the continuity plan (control A.5.29)

The continuity plan describes the technical recovery of each process (recovery strategy, RTO). This
step adds information security during the crisis: how to keep working without lowering security
when the crisis is itself an attack.

1. Menu **GRC & Compliance > Operations**, **Security crisis scenarios** tab (next to the
   business processes). The **Business continuity** right of the profile is required.
2. Click **Add the default scenarios**: five scenarios are added to the active entity, ready to
   adapt: ransomware attack, data leak, compromise of privileged accounts, unavailability of a
   critical supplier, denial of service attack. Nothing existing is changed; a default scenario
   that was deleted comes back on the next click.
3. Open a scenario, for instance "Ransomware attack". In **Impacted processes**, choose the
   business processes this crisis would stop (for instance "Billing (fictitious)"): only the
   processes of the entity of the scenario are offered.
4. Read the **secure degraded mode** (limited access, actions validated by a second person,
   controlled communication) and the **roles** of management, the CISO and the IT department, then
   adapt them to your organization.
5. The **response playbook** is a remediation playbook of the incident library: the default
   scenarios use the ones provided (ransomware, data leak, compromised account). For denial of
   service and supplier unavailability, create the playbook in the library, then choose it here.
   **Crisis communication** points to the message templates of step {{step:0280-prepare-crisis-communication}}.
6. **Exercise**: a finished crisis exercise (**Crisis exercises** page of the same section) of the
   chosen exercise scenario counts as an exercise of the scenario. A tabletop exercise held without
   the plugin is recorded in **Last exercise outside the plugin**, with its report attached.
7. At the top of the page, the coverage: **critical processes without scenario** (RTO of 24 hours or
   less), **scenarios never exercised** or not exercised for more than a year. Each scenario lists
   what remains to complete before the audit. The **Security crisis scenarios** tab of a business
   process shows the scenarios that impact it.
8. **Print the scenarios (PDF)** gives the document to show the auditor. On control **A.5.29**, the
   "Security crisis scenarios" box shows the coverage.
9. The **Preparedness** tab of a scenario gathers the evidence that it is ready, without copying
   anything:
   - **crisis exercises and review**: the finished exercises of the chosen exercise scenario, the
     review of the last one (objectives achieved, findings, improvement actions, validation) and what
     was recorded during the exercise (communications, official contacts, measured recovery times).
     The **Launch this exercise** button opens the crisis room. A review that is not validated
     appears in what remains to complete;
   - **recovery tests of the impacted processes**: the last test of each process, compared with its
     RTO. A critical process never tested appears in what remains to complete;
   - **crisis communication**: the message templates of the chosen situation and the number of
     recipients of their distribution list;
   - **official contacts** to inform: those marked "security incident", plus those marked "data
     breach" for a data leak situation, visible from the entity of the scenario, with their time
     limit and their last verification. They are also in the PDF.

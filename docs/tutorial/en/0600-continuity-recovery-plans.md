### Write, approve and test the continuity and recovery plans

The continuity and recovery plans answer controls A.5.29 and A.5.30 of ISO 27001:
**Assistance > Crisis and continuity > Continuity and recovery plans**.

1. Create a plan with one of the four buttons: **BCP** and **BRP** for a business activity, **ITCP**
   and **DRP** for an IT system. The plan starts from a short model to replace (set in
   **Setup > Plugins > GRC Manager**, **Incidents and continuity** tab).
2. Choose the owner, the approver (usually a member of management), the crisis team and the
   on-call team (GLPI groups), then the **covered processes**: their RTO and RPO come from their
   BIA, with the last recovery test of each one.
3. Fill in the content: activation, roles and contacts, steps, fallback, dependencies,
   communication.
4. **Versions and approval** tab: **Freeze and send for approval**. The approver approves or
   rejects from the same tab; once approved, the plan can no longer be changed and its next review
   is dated. To change it, prepare a new version.
5. **Plan tests** tab: record each exercise (date, type, result, gaps). A gap becomes a
   nonconformity with **Create a nonconformity**.
6. **Print the plan (PDF)** to keep a copy outside GLPI.

The home page of the section and the sheet of controls A.5.29 and A.5.30 show the plans not
approved, the reviews overdue, the plans not tested within a year and the critical processes
without plan.

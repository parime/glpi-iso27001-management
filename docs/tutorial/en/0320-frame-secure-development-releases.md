### Frame secure development and releases (controls A.8.25 to A.8.31)

The plugin relies on the **appliances** of GLPI (Assets > Appliances) and on the **changes**
(Assistance > Changes): it creates no other object. You need the general right of the plugin; to
approve a release, the **Decide on releases** right (GRC Manager tab of the profile, given to
Super-Admin at installation).

1. Open a fictitious in-house application, for instance "HR portal", then the **Secure
   development** tab. Fill in the owner (empty: the technical owner of the application), the code
   repository (https://, ssh:// or git@ address), the **framework followed** (OWASP ASVS, OWASP Top
   10, OWASP SAMM, ANSSI recommendations or other) and its level, then tick **Mandatory code
   review**, **Static analysis (SAST)**, **Dynamic analysis (DAST)** and **Dependency analysis**.
   Give the tools and their frequency.
2. Answer **Separated environments** (yes or no). When yes, tick what is actually separated
   between development, test and production: **access**, **data** (no production data outside
   production, or anonymised) and **configuration**; a missing box shows a partial separation. When
   the answer is no, the justification is mandatory (accepted risk, compensating measure).
   Then describe the **security requirements** of the application (A.8.26) and the **secure
   architecture principles** applied (A.8.27), or link the specification and the architecture
   document. When the development is entrusted to a supplier (A.8.30), tick it, choose the supplier
   of GLPI, tick **Security clauses in the contract** and describe the supervision of the supplier
   (review of deliveries, acceptance tests, right to audit). Save: the application enters the
   register.
3. Create the change of the new version and link the application to it (**Items** tab of the
   change). A **Security release validation** tab appears.
4. Before the release, tick the **checklist**: tests passed (including security tests), code review
   done, vulnerabilities found treated, and write down the evidence (SAST report, review link). Save.
5. The CIO or the CISO chooses their role and clicks **Approve the release** (only possible when
   the whole checklist is ticked) or **Reject the release** (reason mandatory). The decision is
   recorded with its author and date, and written in the history of the change; the checklist is
   then frozen. **Reopen the decision** makes it editable again, and the history keeps the previous
   decision.
6. Open Compliance > **Secure development**. The page gives the register of applications, their
   practices, the **applications without separation of environments** and the **releases without
   security validation** of the last 12 months (solved or closed change linked to an application of
   the register, without validation, rejected, or approved after the release). Only your entities
   are shown; the releases require the right to see all changes.
7. On controls A.8.25 to A.8.31 of the SoA, the **Secure development** box shows the indicators
   useful to each control: documented requirements (A.8.26), architecture principles (A.8.27),
   outsourced applications without supplier or clauses (A.8.30), partial separation (A.8.31). This
   evidence helps lift the audit findings on the development life cycle, secure coding, security
   testing and separation of environments.

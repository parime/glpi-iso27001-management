### Follow the Active Directory security audit (controls A.5.15 to A.5.18, A.8.2 and A.8.5)

The Active Directory is the first target of attacks: privileged accounts, delegations, inactive
accounts. The plugin keeps the results of the audits your team runs with PingCastle; it runs no
tool and never queries the directory. The page is reserved to the sensitive registers right: these
reports describe the weaknesses of the domain.

1. On a computer of the domain, run PingCastle and choose the health check (`--healthcheck`
   option). It produces an `ad_hc_<domain>.xml` file (and an HTML file, not needed here). PingCastle
   is free for internal use; a service provider auditing a customer needs a licence.
2. Open Technical security > **Active Directory security audit**. The "What is this page for?" box
   recalls what is expected. To try it, download the fictitious example report (domain
   `exemple.test`).
3. In **Import a PingCastle report**, choose the XML file and leave the archive box ticked: the
   report, stripped of its passwords, is kept as a restricted document. This requires the right to
   update the sensitive registers. A file that declares a DOCTYPE or entities is refused, and the
   same file is imported only once.
4. The card of each domain gives the last score (from 0, the best, to 100) with a green, orange or
   red badge, and its change since the previous report. The details show the score of each category
   (stale objects, privileged accounts, trust relationships, anomalies).
5. The list of **rules triggered** gives, for each one, the severity, the points, the PingCastle
   finding and the Annex A controls it concerns. Only these items are kept: never the list of
   accounts, nor a password found in the group policies (a red badge reports them: change them).
6. For a rule you treat, click **Propose an action**: the non-conformity form opens pre-filled
   (domain, rule, controls, finding). Review it, set an owner and a due date, then save. Or click
   **Open a ticket**. Nothing is created without your click, and these links follow the rule in the
   next reports.
7. Import a new report after the fixes: the page shows the rules that appeared ("New" badge) and the
   rules resolved since the previous report.
8. On controls A.5.15, A.5.17, A.5.18, A.8.2 and A.8.5, the "Active Directory security audit" box
   shows the score of each domain and the number of rules that concern the control. The dashboard
   offers the card "Active Directory security score" and risk indicators the source of the same
   name.

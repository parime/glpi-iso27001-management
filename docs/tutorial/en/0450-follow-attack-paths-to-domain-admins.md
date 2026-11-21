### Follow the attack paths to Domain Admins (controls A.5.15, A.5.18 and A.8.2)

An attack path is a chain of rights, group memberships or open sessions that lets an ordinary
account take control of the **Domain Admins** group. BloodHound CE and Adalanche draw these paths;
the **Active Directory security audit** page reads their export, without running the tool or
querying the directory.

1. **BloodHound CE**: in the **Explore** page, run the query of the shortest paths to Domain Admins,
   then export the graph as JSON. **Adalanche**: run the analysis with Domain Admins as target, then
   export the graph as JSON (Cytoscape format). A fictitious example of each tool can be downloaded
   from the **Attack paths to Domain Admins** part.
2. Import the file in that part (not in the report form, which sends you there). If the export does
   not give the domain, fill in the **Domain** field.
3. The domain card gives the **number of accounts with a path** to Domain Admins and the change
   since the previous export. The details split users and computers, give the shortest path, then
   the list of the **most exposed accounts** (number of steps, number of routes); a **New** badge
   marks an account missing from the previous export. Accounts already tier zero in BloodHound CE
   are not counted.
4. The **Create the risk in the register** button creates, on click only, a pre-filled risk
   (maximum impact) in the entity of the import; the next exports of the domain stay linked to it.
   Then adjust the rating and the treatment in the register.
5. The forms of controls A.5.15, A.5.18 and A.8.2 show the evidence (latest export of each domain),
   and the **Accounts with an attack path to Domain Admins** risk indicator follows the figure over
   time.
6. Only the number of accounts and the most exposed accounts (name, type, steps) are kept. Files
   that are too large, too deep or malformed are refused.

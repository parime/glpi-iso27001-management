### Let a substitute decide during an absence

When the SoA approver or the reviewer of an access review is on leave, their decisions can be
taken by their substitute, without changing the owner or granting any right (clause 5.3, roles
and responsibilities):

1. The administrator opens the GRC Manager **Configuration** and, in the **Delegation during an
   absence** box, ticks the delegable decisions (access review, SoA approval, policy approval),
   then clicks **Save**. None is delegable by default.
2. Before leaving, the absent person opens their **Preferences** in GLPI,
   **Authorized substitutes** tab, chooses a substitute and enters the **Substitution start date**
   and **Substitution end date**. Without an end date, nothing is delegated.
3. During the absence, the substitute finds in **My GRC space** the **You are standing in for**
   box and the decisions waiting for the absent person, marked **On behalf of ...**. They decide
   from **My access reviews** or the approval pages, as for their own decisions.

Fictitious example: Marie Exemple, purchasing manager, is away from 1 to 15 August; Paul Test, her
substitute, reviews the accounts of the accounting software in her place. The history and the
review report show "Paul Test on behalf of Marie Exemple".

The substitute sees nothing else, never decides on their own account and does not answer for the
absent person on a version they approve themselves. At the end of the absence, the decisions
disappear from their space. The **Delegated decisions** page (link from the configuration box)
lists each decision taken by a substitute, with the declared absence period: evidence to show in
an audit.

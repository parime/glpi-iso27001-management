### Split the ISMS by entity (perimeters)

With several GLPI entities (for instance "North subsidiary" and "South subsidiary" under the root
entity), each risk belongs to an entity: a user limited to the North subsidiary only sees its risks.
To give a subsidiary its own Statement of Applicability:

1. Pick the North subsidiary in the GLPI entity selector.
2. Open Compliance > Annex A controls: the banner says the subsidiary follows the SoA of the root
   entity. Click **Create the SoA of this entity** and confirm.
3. The 93 controls of the subsidiary keep the applicability and justifications of the common SoA;
   review them one by one, then ask for management approval as for the common SoA.
4. Go back to the root entity in tree view: Dashboard > **Perimeters** compares both subsidiaries
   (SoA followed, implementation rate, approved version, open risks by level, open
   non-conformities by severity).
5. Audits follow the same logic: created in the North subsidiary, an audit, its findings and its
   evidence requests are only visible from the North subsidiary (and from the root entity in tree
   view). The team, evidence and auditor access lists only offer accounts with a profile in the North
   subsidiary. The treatment actions of a risk follow the entity of the risk.

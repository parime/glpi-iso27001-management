### Suivre sa mise en conformité NIS2

La page **GRC & Conformité > Tableau de bord > Parcours de mise en conformité NIS2** reprend, pour
la directive (UE) 2022/2555, le principe de la préparation à la certification ISO 27001 : une liste
de critères, chacun avec l'article de la directive concerné, son état calculé à partir des données
déjà saisies et le bouton qui ouvre l'écran à compléter (avec son chemin dans le menu). Elle
n'apparaît que si le référentiel **NIS2** est activé dans **Configuration > Plugins > GRC Manager**,
onglet **Référentiels**.

1. En France, activez aussi le **ReCyF** (NIS2 France) et réglez, dans le bloc **ReCyF** de la
   configuration, la catégorie de chaque entité concernée : **entité essentielle** ou **entité
   importante**.
2. Ouvrez la page et commencez par le critère marqué **Prochaine étape**. Le premier groupe vous
   demande d'inscrire l'autorité ou le CSIRT comme **contact officiel** une fois votre inscription
   faite auprès de l'ANSSI.
3. Les groupes **Gouvernance** et **Mesures de gestion des risques** reprennent les étapes communes
   avec l'ISO 27001 (politiques approuvées, rôles, risques, formation, exercices, audit,
   non-conformités) et y ajoutent les fournisseurs, la revue des accès et la couverture MFA.
4. Le groupe **Notification des incidents** signale les incidents soumis à NIS2 dont l'alerte de
   24 h, la notification de 72 h ou le rapport final d'un mois est en retard.
5. Le groupe **Conformité au quotidien** liste les mesures du ReCyF non couvertes, celles mises en
   œuvre sans document joint, et les actions récurrentes : revue des droits, revue des comptes à
   privilèges, test de restauration des sauvegardes et test du plan de continuité (à activer dans
   **Revues périodiques**).

La page est recalculée à chaque ouverture et ne modifie rien.

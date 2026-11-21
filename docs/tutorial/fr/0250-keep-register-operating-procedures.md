### Tenir le registre des procédures d'exploitation (mesure A.5.37)

1. Ouvrez Conformité > **Procédures d'exploitation** (onglet à côté de « Politiques de sécurité »).
   La page montre la couverture des domaines attendus : sauvegarde, restauration, supervision,
   journaux, mises à jour, déploiement, gestion des accès, gestion des incidents, changements,
   maintenance. Chaque domaine est « À jour », « Revue à faire », « Non approuvée » ou « Aucune
   procédure ».
2. Sur la ligne d'un domaine sans procédure, cliquez sur **Ajouter la procédure**. La fiche est un
   document de la bibliothèque des politiques, déjà en catégorie « Procédure d'exploitation » et
   dans le bon domaine : pas d'objet à part. Un document existant devient une procédure en
   choisissant cette catégorie sur sa fiche.
3. Renseignez par exemple « Procédure de sauvegarde des serveurs (fictive) », version 1.0, son
   responsable, et joignez le document dans l'onglet « Documents ». Faites-la approuver (statut
   « Approuvée » avec sa date, ou onglet « Approbation » si le circuit est activé), puis fixez la
   **prochaine revue** : sans date de revue, ou une fois la date passée, le domaine passe en « Revue
   à faire ».
4. Dans l'onglet **Prise de connaissance**, ajoutez au public les groupes ou profils qui exécutent la
   procédure (l'équipe d'exploitation, par exemple), puis lancez la campagne : le registre affiche
   ensuite le taux de lecture de la version en vigueur.
5. La liste des domaines se règle dans la configuration du plugin (cadre « Domaines attendus des
   procédures d'exploitation ») : décochez un domaine qui ne vous concerne pas, ajoutez les vôtres,
   un par ligne.
6. Avant l'audit, cliquez sur **Archiver sur la mesure A.5.37** : le registre est enregistré en PDF
   daté sur la mesure. **Imprimer le registre (PDF)** le télécharge sans l'enregistrer. Sur la fiche
   de la mesure A.5.37, le cadre « Registre des procédures d'exploitation » rappelle la couverture ;
   les procès-verbaux d'approbation et les rapports de prise de connaissance d'une procédure y sont
   archivés (ceux d'une politique restent sur A.5.1).
7. Le texte de la procédure peut être un **article de la base de connaissances** de GLPI : sur la
   fiche de la procédure, onglet « Base de connaissances », liez l'article (par exemple « Sauvegarde
   des serveurs : pas à pas (fictif) »). Le registre affiche l'article et les documents joints sous
   le titre ; une procédure sans article ni document porte le badge « Sans contenu ».
8. Chaque année, relisez la procédure avec son responsable. Si elle reste juste, cliquez sur
   **Enregistrer la revue** sur sa fiche : la date du jour et votre nom sont tracés, sans nouvelle
   version, et la prochaine revue passe à un an (ou à la date que vous avez saisie). Le registre
   affiche « Revue le … par … » et le journal d'audit garde chaque revue. Si elle a changé, préparez
   une nouvelle version.
9. Le contrôle de cohérence signale les domaines attendus sans procédure approuvée (règle A.5.37).
10. Sur la fiche d'une procédure, le cadre **Historique des revues** liste toutes les revues
    enregistrées, la plus récente d'abord : date, personne qui l'a faite et prochaine revue prévue.
    Le PDF du registre reprend cet historique, procédure par procédure : l'auditeur voit que la
    revue est faite chaque année, pas seulement la dernière.

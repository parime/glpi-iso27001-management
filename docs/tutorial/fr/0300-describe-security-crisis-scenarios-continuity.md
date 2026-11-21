### Décrire les scénarios de crise sécurité du PCA (mesure A.5.29)

Le plan de continuité décrit la restauration technique de chaque processus (stratégie de reprise,
RTO). Cette étape y ajoute la sécurité de l'information pendant la crise : comment continuer à
travailler sans baisser la sécurité quand la crise est elle-même une attaque.

1. Menu **GRC & Conformité > Opérations**, onglet **Scénarios de crise sécurité** (à côté des
   processus métier). Il faut le droit **Continuité d'activité** du profil.
2. Cliquez sur **Ajouter les scénarios par défaut** : cinq scénarios sont ajoutés dans l'entité
   active, prêts à adapter : attaque par rançongiciel, fuite de données, compromission de comptes à
   privilèges, indisponibilité d'un prestataire critique, attaque par déni de service. Rien
   d'existant n'est modifié ; un scénario par défaut supprimé revient au prochain clic.
3. Ouvrez un scénario, par exemple « Attaque par rançongiciel ». Dans **Processus impactés**,
   choisissez les processus métier que cette crise arrêterait (par exemple « Facturation (fictif) ») :
   seuls les processus de l'entité du scénario sont proposés.
4. Relisez le **mode dégradé sécurisé** (accès limités, actions validées par une seconde personne,
   communication contrôlée) et les **rôles** de la direction, du RSSI et de la DSI, puis adaptez-les
   à votre organisation.
5. Le **guide de réponse** est une procédure de remédiation de la bibliothèque des incidents : les
   scénarios par défaut reprennent celles fournies (rançongiciel, fuite de données, compte
   compromis). Pour le déni de service et l'indisponibilité d'un prestataire, créez la procédure
   dans la bibliothèque, puis choisissez-la ici. La **communication de crise** renvoie aux modèles de
   messages de l'étape {{step:0280-prepare-crisis-communication}}.
6. **Exercice** : un exercice de crise terminé (page **Exercices de crise** de la même rubrique) du
   scénario d'exercice choisi compte comme exercice du scénario. Un exercice sur table tenu sans le plugin se note dans
   **Dernier exercice hors du plugin**, son compte rendu en document joint.
7. En haut de la page, la couverture : **processus critiques sans scénario** (RTO de 24 heures ou
   moins), **scénarios jamais exercés** ou pas exercés depuis plus d'un an. Chaque scénario liste ce
   qui reste à compléter avant l'audit. L'onglet **Scénarios de crise sécurité** d'un processus
   métier montre les scénarios qui le touchent.
8. **Imprimer les scénarios (PDF)** donne le document à présenter à l'auditeur. Sur la fiche de la
   mesure **A.5.29**, le cadre « Scénarios de crise sécurité » reprend la couverture.
9. L'onglet **Préparation** d'un scénario rassemble les preuves qu'il est prêt, sans rien recopier :
   - **exercices de crise et bilan** : les exercices terminés du scénario d'exercice choisi, le bilan
     du dernier (objectifs atteints, constats, actions d'amélioration, validation) et ce qui a été
     tracé pendant l'exercice (communications, prises de contact officielles, temps de reprise
     mesurés). Le bouton **Lancer cet exercice** ouvre la salle de crise. Un bilan non validé
     apparaît dans ce qui reste à compléter ;
   - **tests de reprise des processus impactés** : le dernier test de chaque processus, comparé à son
     RTO. Un processus critique jamais testé apparaît dans ce qui reste à compléter ;
   - **communication de crise** : les modèles de messages de la situation choisie et le nombre de
     destinataires de leur liste de diffusion ;
   - **contacts officiels** à prévenir : ceux marqués « incident de sécurité », plus ceux marqués
     « violation de données » pour une situation de fuite de données, visibles depuis l'entité du
     scénario, avec leur délai et leur dernière vérification. Ils figurent aussi dans le PDF.

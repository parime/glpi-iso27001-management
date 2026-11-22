### Added

- **Plans de continuité et de reprise** (#1083, mesures A.5.29 et A.5.30, ISO 22301) : nouvel
  onglet **Assistance > Crise et continuité > Plans de continuité et de reprise**, dans le groupe
  « Se préparer », juste après les scénarios de crise.
  - Quatre types : PCA et PRA (activités métier), PCI et PRI (systèmes informatiques). Chaque plan
    réunit son périmètre, ses critères et son autorité de déclenchement, ses rôles et contacts, ses
    étapes, ses solutions de repli, ses dépendances et ordre de reprise, sa communication, sa
    cellule de crise et son astreinte (groupes GLPI).
  - Le plan est lié aux processus métier qu'il couvre, avec leurs DMIA/PDMA (RTO/RPO) et leur durée
    maximale d'interruption issues du BIA, et le dernier test de reprise de chaque processus (écart
    signalé au-delà du RTO), ainsi qu'aux scénarios de crise et aux fournisseurs concernés. Un
    nouvel onglet sur la fiche d'un processus liste les plans qui le couvrent.
  - Versions et approbation : la demande fige la version (contenu et empreinte SHA-256, gardés
    dans l'historique et imprimables) ; l'approbateur désigné sur le plan, sinon les approbateurs
    par défaut des politiques, approuve ou refuse (commentaire obligatoire). Le contenu reste figé
    pendant l'approbation et après ; une nouvelle version le rouvre, la version approuvée restant
    en vigueur jusque-là. La date de revue est calculée à l'approbation.
  - Tests du plan : date, type d'exercice, résultat, exercice de crise du plugin, écarts constatés,
    transformés à la demande en non-conformité (A.5.30) rattachée au test.
  - Impression en PDF (version actuelle ou version figée) pour consulter le plan quand GLPI est
    indisponible, avec les contacts officiels à prévenir.
  - Indicateurs sur la page d'accueil de la rubrique et dans la liste : plans non approuvés, revue
    dépassée, plans jamais testés ou testés il y a plus d'un an, processus critiques sans plan.
    La fiche signale aussi un responsable ou un approbateur parti (compte inactif).
  - Preuve sur les mesures A.5.29 et A.5.30 de la SoA : plans par type, approuvés, testés dans
    l'année, revues dépassées.
  - Réglages dans **Configuration > Plugins > GRC Manager**, onglet **Incidents et continuité** :
    périodicité de revue par défaut, rappel avant la date de revue, modèle proposé pour un nouveau
    plan. Le menu étant gardé dans la session, l'onglet apparaît à la connexion suivante.

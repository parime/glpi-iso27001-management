### Faire décider un suppléant pendant une absence

Quand l'approbateur de la SoA ou le responsable d'une revue des accès est en congé, ses décisions
peuvent être prises par son suppléant, sans changer le responsable ni donner de droits
(article 5.3, rôles et responsabilités) :

1. L'administrateur ouvre **Configuration** de GRC Manager et, dans l'encadré **Délégation
   pendant une absence**, coche les décisions délégables (revue des accès, approbation de la SoA,
   approbation des politiques), puis clique sur **Sauvegarder**. Aucune ne l'est par défaut.
2. Avant de partir, la personne absente ouvre ses **Préférences** dans GLPI, onglet
   **Suppléants autorisés**, choisit son suppléant et indique la **Date de début de substitution**
   et la **Date de fin de substitution**. Sans date de fin, rien n'est délégué.
3. Pendant l'absence, le suppléant trouve dans **Mon espace GRC** l'encadré **Vous remplacez** et
   les décisions qui attendent l'absent, marquées **Pour le compte de ...**. Il décide depuis
   **Mes revues d'accès** ou les pages d'approbation, comme pour ses propres décisions.

Exemple fictif : Marie Exemple, responsable des achats, est absente du 1er au 15 août ; Paul Test,
son suppléant, revoit à sa place les comptes du logiciel de comptabilité. L'historique et le
rapport de la revue indiquent « Paul Test pour le compte de Marie Exemple ».

Le suppléant ne voit rien d'autre, ne décide jamais de son propre compte et ne répond pas pour
l'absent à une version qu'il approuve lui-même. À la fin de l'absence, les décisions disparaissent
de son espace. La page **Décisions déléguées** (lien depuis l'encadré de la configuration) liste
chaque décision prise par un suppléant, avec la période d'absence déclarée : une preuve à montrer
en audit.

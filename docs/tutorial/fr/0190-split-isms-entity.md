### Découper le SMSI par entité (périmètres)

Avec plusieurs entités GLPI (par exemple « Filiale Nord » et « Filiale Sud » sous l'entité racine),
chaque risque appartient à une entité : un utilisateur limité à Filiale Nord ne voit que les risques
de Filiale Nord. Pour qu'une filiale tienne sa propre Déclaration d'Applicabilité :

1. Choisissez l'entité Filiale Nord dans le sélecteur d'entité de GLPI.
2. Ouvrez Conformité > Contrôles Annexe A : l'encadré indique « Filiale Nord suit la SoA de Entité
   racine ». Cliquez sur **Créer la SoA de cette entité** et confirmez.
3. Les 93 mesures de Filiale Nord reprennent l'applicabilité et les justifications de la SoA commune ;
   revoyez-les une à une, puis demandez l'approbation de la direction comme pour la SoA commune.
4. Revenez à l'entité racine en vue arborescente : Tableau de bord > **Périmètres** compare les deux
   filiales (SoA suivie, taux de mise en œuvre, version approuvée, risques ouverts par niveau,
   non-conformités ouvertes par gravité).
5. Les audits suivent la même logique : créé dans Filiale Nord, un audit, ses constats et ses demandes
   de preuves ne sont visibles que de Filiale Nord (et de l'entité racine en vue arborescente). Les
   listes de l'équipe, des preuves et de l'accès auditeur ne proposent que les comptes qui ont un
   profil dans Filiale Nord. Les actions de traitement d'un risque suivent l'entité du risque.
6. Les autres registres aussi : un risque fournisseur, une formation, une revue de direction, un
   objectif, une obligation, une dérogation, un flux réseau ou une étude EBIOS créé dans Filiale
   Nord reste dans Filiale Nord. Après une mise à jour, ouvrez chaque liste à l'entité racine,
   cochez les éléments d'une filiale, puis **Actions > Modifier > Entité** pour les y ranger.

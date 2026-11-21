### Suivre les chemins d'attaque vers Domain Admins (mesures A.5.15, A.5.18 et A.8.2)

Un chemin d'attaque est une suite de droits, d'appartenances à des groupes ou de sessions ouvertes qui
permet à un compte ordinaire de prendre le contrôle du groupe **Domain Admins**. BloodHound CE et
Adalanche dessinent ces chemins ; la page **Audit de sécurité de l'Active Directory** lit leur export,
sans lancer l'outil ni interroger l'annuaire.

1. **BloodHound CE** : dans la page **Explore**, lancez la requête des plus courts chemins vers
   Domain Admins, puis exportez le graphe en JSON. **Adalanche** : lancez l'analyse avec Domain Admins
   comme cible, puis exportez le graphe en JSON (format Cytoscape). Un exemple fictif de chaque outil
   se télécharge depuis la partie **Chemins d'attaque vers Domain Admins**.
2. Importez le fichier dans cette partie (pas dans le formulaire des rapports, qui vous y renvoie). Si
   l'export ne donne pas le domaine, saisissez-le dans le champ **Domaine**.
3. La carte du domaine donne le **nombre de comptes ayant un chemin** vers Domain Admins et l'écart
   avec l'export précédent. Le détail sépare utilisateurs et ordinateurs, donne le plus court chemin,
   puis la liste des **comptes les plus exposés** (nombre de sauts, nombre de routes) ; un badge
   **Nouveau** signale un compte absent de l'export précédent. Les comptes déjà de niveau zéro dans
   BloodHound CE ne sont pas comptés.
4. Le bouton **Créer le risque dans le registre** crée, sur clic seulement, un risque pré-rempli
   (impact maximal) dans l'entité de l'import ; les exports suivants du domaine y restent reliés.
   Ajustez ensuite la cotation et le traitement dans le registre.
5. Les fiches des mesures A.5.15, A.5.18 et A.8.2 montrent la preuve (dernier export de chaque
   domaine), et l'indicateur de risque **Comptes ayant un chemin d'attaque vers Domain Admins** suit le
   chiffre dans le temps.
6. Seuls le nombre de comptes et les comptes les plus exposés (nom, type, sauts) sont gardés. Les
   fichiers trop gros, trop profonds ou mal formés sont refusés.

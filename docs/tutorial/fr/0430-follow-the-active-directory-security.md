### Suivre l'audit de sécurité de l'Active Directory (mesures A.5.15 à A.5.18, A.8.2 et A.8.5)

L'Active Directory est la première cible des attaques : comptes privilégiés, délégations, comptes
inactifs. Le plugin garde les résultats des audits que votre équipe réalise avec PingCastle ; il ne
lance aucun outil et n'interroge jamais l'annuaire. La page est réservée au droit des registres
sensibles : ces rapports décrivent les faiblesses du domaine.

1. Sur un poste du domaine, lancez PingCastle et choisissez le contrôle de santé (option
   `--healthcheck`). Il produit un fichier `ad_hc_<domaine>.xml` (et un fichier HTML, inutile ici).
   PingCastle est gratuit pour un usage interne ; un prestataire qui audite un client a besoin d'une
   licence.
2. Ouvrez Sécurité technique > **Audit de sécurité de l'Active Directory**. L'encadré « À quoi sert
   cette page ? » rappelle ce qui est attendu. Pour essayer, téléchargez le rapport d'exemple fictif
   (domaine `exemple.test`).
3. Dans **Importer un rapport PingCastle**, choisissez le fichier XML et laissez cochée la case
   d'archivage : le rapport, débarrassé de ses mots de passe, est gardé comme document restreint.
   Il faut le droit de modifier les registres sensibles. Un fichier qui déclare un DOCTYPE ou des
   entités est refusé, et le même fichier n'est importé qu'une fois.
4. La carte de chaque domaine donne le dernier score (de 0, le meilleur, à 100) avec un badge vert,
   orange ou rouge, et son évolution depuis le rapport précédent. Le détail montre le score de
   chaque catégorie (objets obsolètes, comptes privilégiés, relations d'approbation, anomalies).
5. La liste des **règles déclenchées** donne, pour chacune, la gravité, les points, le constat de
   PingCastle et les mesures de l'Annexe A concernées. Seuls ces éléments sont gardés : jamais la
   liste des comptes, ni un mot de passe trouvé dans les stratégies de groupe (un badge rouge signale
   leur présence : changez-les).
6. Pour une règle que vous traitez, cliquez sur **Proposer une action** : la fiche de non-conformité
   s'ouvre pré-remplie (domaine, règle, mesures, constat). Relisez-la, désignez un responsable et une
   échéance, puis enregistrez. Ou cliquez sur **Ouvrir un ticket**. Rien n'est créé sans votre clic,
   et ces liens suivent la règle dans les rapports suivants.
7. Importez un nouveau rapport après les corrections : la page montre les règles apparues (badge
   « Nouvelle ») et les règles résolues depuis le rapport précédent.
8. Sur la fiche des mesures A.5.15, A.5.17, A.5.18, A.8.2 et A.8.5, le cadre « Audit de sécurité de
   l'Active Directory » montre le score de chaque domaine et le nombre de règles qui concernent la
   mesure. Le tableau de bord propose la carte « Score de sécurité de l'Active Directory » et les
   indicateurs de risque la source du même nom.

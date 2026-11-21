### Ajouter Purple Knight, Maester et ScubaGear à l'audit de sécurité (mesures A.5.15 à A.5.18, A.5.23, A.8.2 et A.8.5)

La page **Audit de sécurité de l'Active Directory** lit aussi les fichiers de trois autres outils
gratuits. Le type de fichier est reconnu tout seul ; le plugin ne lance aucun outil et n'interroge
ni l'annuaire ni le locataire Microsoft 365.

1. **Purple Knight** (Active Directory, indicateurs d'exposition et de compromission) : après
   l'analyse, enregistrez les indicateurs de sécurité au format CSV, puis importez ce fichier. Si
   l'export n'a pas de colonne de domaine, saisissez le domaine dans le champ **Domaine (si le
   fichier n'en donne pas)**. Le score de Purple Knight va de 0 à 100, 100 étant le meilleur : la
   page le retourne (100 moins le score) pour garder la même lecture que PingCastle, 0 le meilleur.
2. **Maester** (Entra ID et Microsoft 365) : lancez `Invoke-Maester`, puis importez le fichier
   `TestResults-<date>.json` du dossier `test-results`. **ScubaGear** : lancez `Invoke-SCuBA`, puis
   importez le fichier `ScubaResults_<identifiant>.json` du dossier de sortie. Le score de la page est
   la part des contrôles en échec.
3. Chaque carte porte le badge de l'outil. Le détail donne le nombre de contrôles réussis, puis la
   liste des **contrôles en échec** avec leur gravité, leur catégorie (sécurité des comptes,
   délégation, Kerberos, identité Entra ID, services Microsoft 365...) et les mesures de l'Annexe A
   concernées (A.5.23 pour les services en nuage). Les boutons **Proposer une action** et
   **Ouvrir un ticket** fonctionnent comme pour PingCastle.
4. Seuls le score et les contrôles en échec sont gardés : jamais la liste des comptes, la
   configuration brute du locataire (section `Raw` de ScubaGear), le compte qui a lancé Maester, ni un
   texte qui ressemble à un mot de passe. Les fichiers trop gros, trop profonds ou mal formés sont
   refusés. Seul le rapport PingCastle peut être archivé comme document restreint.
5. Pour un locataire Entra ID, un encadré renvoie à la page **Couverture MFA** : Maester et
   ScubaGear contrôlent la configuration (accès conditionnel, méthodes d'authentification, rôles),
   tandis que la couverture MFA compte par compte vient de cette page (lecture par Microsoft Graph ou
   import CSV). Le score de sécurité de l'AD du tableau de bord et des indicateurs de risque ne
   retient que PingCastle et Purple Knight.

### Added

- **Audit de sécurité de l'Active Directory, lot 2 : Purple Knight, Maester et ScubaGear** (issue
  #835, mesures A.5.15 à A.5.18, A.5.23, A.8.2 et A.8.5). La même page importe aussi l'export CSV des
  indicateurs de sécurité de Purple Knight (indicateurs d'exposition et de compromission de l'AD) et
  les résultats JSON de Maester (`TestResults-<date>.json`) et de ScubaGear
  (`ScubaResults_<identifiant>.json`) pour la configuration d'Entra ID et de Microsoft 365 ; le type
  de fichier est reconnu tout seul. Les scores gardent la lecture de PingCastle (0 le meilleur) : le
  score de Purple Knight est retourné, celui de Maester et de ScubaGear est la part des contrôles en
  échec ; le nombre de contrôles évalués et réussis est affiché. Seuls les contrôles en échec sont
  gardés, avec leur gravité, une catégorie (sécurité des comptes, délégation, infrastructure,
  stratégies de groupe, Kerberos, identité Entra ID, services Microsoft 365) et les mesures de
  l'Annexe A concernées, A.5.23 comprise ; action ou ticket sur clic, historique et comparaison
  comme pour PingCastle. Champ « Domaine » pour un export sans colonne de domaine. Le score de
  sécurité de l'AD (KRI, carte du tableau de bord) ne retient que PingCastle et Purple Knight ; la
  préparation à la certification ajoute le constat Entra ID. Pour un locataire Entra ID, la page
  renvoie à la couverture MFA par compte (issue #852) au lieu de la recalculer. Lecture sûre : taille
  limitée, JSON borné en profondeur et en nombre de contrôles, balises retirées, texte ressemblant à
  un mot de passe écarté ; ni la section `Raw` de ScubaGear, ni le compte, la machine ou le détail
  des résultats de Maester ne sont lus. Exemples fictifs pour chaque outil, tests unitaires avec
  fichiers piégés, tests d'intégration, matrice d'accès et contrôle XSS.

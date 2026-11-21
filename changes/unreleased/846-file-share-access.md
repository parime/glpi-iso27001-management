### Added

- **Droits d'accès aux partages de fichiers Windows (SMB et NTFS)** (issue #846, lot 1, mesures
  A.5.15, A.5.18 et A.8.3). Nouvelle page « Droits sur les partages de fichiers » dans la rubrique
  Revue des accès, en lecture seule : import du fichier CSV produit par le script PowerShell fourni
  (`Export-GrcShareAccess.ps1`, téléchargeable depuis la page : `Get-SmbShare`,
  `Get-SmbShareAccess`, `Get-Acl` jusqu'à une profondeur réglable, héritage, propriétaire), ou par
  un outil tiers (colonnes reconnues par leurs noms usuels). Le plugin ne se connecte à aucun
  serveur et ne modifie aucun droit. Comptes rapprochés des utilisateurs et groupes GLPI ; comptes
  larges et comptes système reconnus par leur SID, quelle que soit la langue de Windows. Écarts
  signalés : tout le monde peut modifier, compte supprimé (SID non résolu) ou désactivé qui a
  encore des droits, droit nominatif, héritage cassé sur un partage sensible, partage sans
  propriétaire. Classification C/I/D par partage et score pondéré, suivi d'un import à l'autre
  (droits ajoutés et retirés, historique des imports), revue des droits par le propriétaire désigné
  (garder, modifier, retirer, décision tracée ; rien n'est appliqué sur le serveur). Preuve sur les
  fiches des mesures A.5.15, A.5.18 et A.8.3. Données réservées au droit des registres sensibles.
  Point d'extension `ShareAccess::accessesForUser()` pour la revue des accès. Trois nouvelles
  tables, créées à l'installation et à la mise à jour si absentes (rejouable), supprimées à la
  désinstallation. Matrice d'accès et contrôle XSS étendus.

### Added

- **Droits d'accès à SharePoint, OneDrive et Teams** (issue #846, lot 2, mesures A.5.15, A.5.18 et
  A.8.3). Sur la page « Droits sur les partages de fichiers », un cadre **Lire les droits SharePoint,
  OneDrive et Teams** lit les droits par Microsoft Graph, sur clic et en lecture seule, avec
  l'application et le secret chiffré déjà réglés pour la couverture MFA (#852) : droits des
  bibliothèques de documents des sites et de leurs éléments partagés à part, éléments partagés des
  OneDrive, propriétaires, membres, invités et visibilité des équipes Teams. Permissions
  d'application à accorder : Sites.Read.All, Files.Read.All, User.Read.All et GroupMember.Read.All.
  Import CSV en solution de repli (fichier d'exemple fourni). Mêmes écarts, même score et même
  revue par le propriétaire que les partages Windows, avec deux écarts en plus : **lien public**
  (n'importe qui ayant le lien, sans connexion) et **invité externe**. Un lien « personnes de
  l'organisation » et une équipe publique comptent comme « tout le monde ». Une lecture incomplète
  ou refusée ne remplace rien.

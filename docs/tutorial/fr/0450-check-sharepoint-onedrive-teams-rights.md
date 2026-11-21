### Contrôler les droits d'accès à SharePoint, OneDrive et Teams (mesures A.5.15, A.5.18 et A.8.3)

Les documents ne sont plus seulement sur des serveurs de fichiers : l'auditeur regarde aussi les
liens de partage et les invités de Microsoft 365. Le plugin lit les droits par Microsoft Graph, en
lecture seule, et ne modifie rien dans Microsoft 365. Il faut le droit du plugin et le droit des
registres sensibles (en modification pour lire ou importer).

1. Réglez une fois l'application Microsoft Graph sur la page **Couverture MFA** (locataire,
   identifiant d'application, secret)  (voir étape {{step:0400-measure-multi-factor-authentication-coverage}}). Accordez-lui en
   plus, avec le consentement de l'administrateur, les permissions d'application
   `Sites.Read.All`, `Files.Read.All`, `User.Read.All` et `GroupMember.Read.All`.
2. Ouvrez Revue des accès > **Droits sur les partages de fichiers**, cadre **Lire les droits
   SharePoint, OneDrive et Teams**. Cliquez sur **Lire SharePoint maintenant**, **Lire OneDrive
   maintenant** ou **Lire Teams maintenant**.
   - SharePoint : droits de la bibliothèque de documents de chaque site et des éléments de premier
     niveau partagés à part ;
   - OneDrive : éléments de premier niveau que chaque personne a partagés (les droits du titulaire
     sont ignorés) ;
   - Teams : propriétaires, membres, invités et visibilité de chaque équipe.
   Si Microsoft Graph refuse l'accès ou si le locataire est trop grand pour une lecture, rien n'est
   remplacé et un message indique quoi faire.
3. Sans accès à Microsoft Graph, importez un fichier CSV (une ligne par droit, colonnes telles que
   `Source`, `Site`, `SiteUrl`, `Item`, `Identity`, `Type`, `Roles` et `LinkScope`) ;
   **Télécharger un fichier d'exemple** en montre un.
4. Les sites, OneDrive et équipes rejoignent la liste des partages, avec les mêmes écarts et le même
   score, plus deux écarts : **lien public** (n'importe qui ayant le lien, sans se connecter) et
   **invité externe**. Un lien « personnes de l'organisation » et une équipe publique comptent comme
   « tout le monde » ; un membre nommé d'une équipe n'est pas un écart.
5. Classez chaque site, désignez son propriétaire et lancez la revue comme pour un partage Windows.
   Les décisions s'appliquent ensuite dans Microsoft 365 ; la lecture suivante montre les droits
   retirés.

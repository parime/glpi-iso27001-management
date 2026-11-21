### Contrôler les droits d'accès aux partages de fichiers Windows (mesures A.5.15, A.5.18 et A.8.3)

L'auditeur demande la preuve que les accès aux dossiers partagés sont limités au besoin et revus.
Le plugin ne se connecte à aucun serveur de fichiers et ne modifie aucun droit : un administrateur
lance un script PowerShell en lecture seule, puis importe le fichier CSV qu'il produit. Il faut le
droit du plugin et le droit des registres sensibles (en modification pour importer).

1. Ouvrez Revue des accès > **Droits sur les partages de fichiers**. L'encadré « À quoi sert cette
   page ? » rappelle ce qui est attendu.
2. Dans **Importer les droits d'un serveur de fichiers**, cliquez sur **Télécharger le script
   PowerShell** et copiez `Export-GrcShareAccess.ps1` sur le serveur de fichiers. Lancez-le avec un
   compte qui peut lire les dossiers :

   ```powershell
   .\Export-GrcShareAccess.ps1 -Depth 2 -OutFile shares.csv
   ```

   Le script lit les partages (`Get-SmbShare`, partages d'administration exclus), leurs droits de
   partage (`Get-SmbShareAccess`), les droits NTFS de la racine et ceux posés sur les sous-dossiers
   jusqu'à `-Depth` niveaux (`Get-Acl`), l'héritage et le propriétaire. `-Share Compta,Projets`
   limite la lecture à certains partages ; `-ResolvePrincipalType` demande à Active Directory si
   chaque compte est un utilisateur ou un groupe. Un export d'un autre outil est accepté s'il a des
   colonnes telles que `Server`, `Share`, `Folder`, `Identity`, `Rights`, `AccessType`,
   `IsInherited` et `InheritanceProtected` ; **Télécharger un fichier d'exemple** en montre un.
3. Importez le fichier. Les comptes sont rapprochés des utilisateurs (identifiant) et des groupes
   (nom) de GLPI. Un nouvel import du même serveur montre les **droits ajoutés et retirés** depuis
   le précédent ; l'historique est dans **Imports et évolution dans le temps**.
4. La liste des partages commence par les plus exposés. Les écarts signalés sont : **tout le monde
   peut modifier** (Tout le monde, Utilisateurs authentifiés, Utilisateurs du domaine ou Invités avec
   un droit de modification), **compte supprimé** (SID non résolu ou compte à la corbeille de GLPI)
   ou **désactivé** qui a encore des droits, **droit donné à une personne** plutôt qu'à un groupe,
   **héritage cassé** sur un sous-dossier d'un partage sensible, **partage sans propriétaire**.
5. Ouvrez un partage : classez-le (confidentialité, intégrité, disponibilité) et désignez son
   **propriétaire**. Le score pondéré compte 3 par écart grave et 1 pour les autres, multiplié par 3
   pour un partage classé élevé et par 2 pour moyen.
6. Cliquez sur **Lancer une revue** : le propriétaire décide pour chaque droit (garder, modifier ou
   retirer, avec un commentaire pour les deux derniers), puis **Clôturer la revue**. Les décisions
   sont tracées avec leur auteur et leur date. L'administrateur applique les changements sur le
   serveur ; l'import suivant montre les droits retirés.
7. Sur la fiche des mesures A.5.15, A.5.18 et A.8.3, le cadre « Droits sur les partages de
   fichiers » montre le nombre de partages, ceux qui ont un écart, les droits qui laissent tout le
   monde modifier, ceux des comptes supprimés ou désactivés et les partages revus dans les douze
   derniers mois.

### Suivre les comptes à privilèges du coffre-fort de mots de passe

Les comptes partagés et les mots de passe des équipements doivent être rangés dans un coffre-fort,
partagés au strict besoin et renouvelés (mesures A.5.17 et A.8.2). La page **GRC > Accès et
comptes > Comptes à privilèges** rapproche votre coffre (Bitwarden, Vaultwarden ou une solution de
gestion des accès à privilèges) des comptes GLPI. Seules des **métadonnées** sont lues : jamais un
mot de passe.

1. Dans **Configuration > Plugins > GRC Manager**, onglet **Personnes et politiques**, choisissez le
   **délai de changement attendu** des mots de passe partagés (12 mois par défaut).
2. Avec Bitwarden Teams ou Enterprise, onglet **Connecteurs**, bloc **Connexion à Bitwarden** :
   choisissez le serveur, collez l'identifiant du client (`organization.…`) et le secret de la clé
   d'API de l'organisation, puis **Tester la connexion**. Sur la page, **Lire maintenant dans
   Bitwarden** récupère les membres, leur rôle, leur connexion en deux étapes et leurs collections.
3. Avec Vaultwarden ou une solution PAM, importez les membres en CSV (colonnes `email`, `type`,
   `status`, `collections`, `twoFactorEnabled`).
4. Importez les éléments du coffre en CSV **sans les mots de passe** : `name`, `collectionIds`,
   `username`, `uri`, `passwordRevisionDate`. Avec Bitwarden ou Vaultwarden, l'interface en ligne
   de commande `bw list items` donne ces champs ; ne gardez que ces colonnes. Un fichier qui contient
   une colonne de mot de passe, de code, de note ou de champ personnalisé est refusé en entier.
5. Traitez les écarts signalés : mot de passe non changé dans le délai, personne partie qui a encore
   accès, membre inconnu de GLPI, administrateur du coffre sans connexion en deux étapes, compte
   d'administration d'un contrôleur de serveur (iLO, iDRAC) absent du coffre. Les corrections se
   font dans le coffre ; le plugin ne modifie rien.
6. Les mêmes écarts apparaissent dans l'onglet **Comptes à privilèges** de chaque campagne de revue
   des accès, et en preuve sur les mesures A.5.17 et A.8.2.

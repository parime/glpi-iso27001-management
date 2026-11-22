### Suivre la sécurité du code des applications avec GitHub et GitLab (mesures A.8.28 et A.8.29)

GitHub et GitLab analysent le code de vos applications : bibliothèques tierces vulnérables, mots de
passe ou clés écrits dans le code, faiblesses du code lui-même. Le plugin lit ces alertes **en
lecture seule** : rien n'est modifié dans GitHub ni GitLab, aucune alerte n'est fermée, et la valeur
d'un secret exposé n'est jamais demandée.

1. Pour chaque application développée en interne, ouvrez son onglet **Développement sécurisé** et
   renseignez le **dépôt de code** (par exemple `https://github.com/exemple/portail` ou
   `git@gitlab.example.test:equipe/portail.git`).
2. Préparez un jeton en lecture seule :
   - GitHub : un jeton d'accès personnel à granularité fine, limité aux dépôts concernés, avec les
     permissions en lecture « Dependabot alerts », « Secret scanning alerts » et « Code scanning
     alerts » ;
   - GitLab : un jeton d'accès de projet ou de groupe avec la portée `read_api` (le rapport de
     vulnérabilités nécessite GitLab Ultimate).
3. Ouvrez **Mesures > Sécurité du code**, cadre **Connexion à GitHub et GitLab** : collez le jeton
   (et, si besoin, l'adresse de votre serveur GitHub Enterprise ou GitLab), puis **Enregistrer** et
   **Lire maintenant**.
4. Le cadre **Applications et leurs dépôts** montre, pour chaque application, le nombre d'alertes par
   famille (secrets exposés, dépendances vulnérables, analyse du code) et le badge **Non activée**
   quand une analyse ne tourne pas sur le dépôt : c'est déjà un écart à la mesure A.8.29.
5. Le cadre **Alertes ouvertes** classe les alertes : d'abord les secrets exposés **non révoqués**
   (à révoquer chez le fournisseur, puis à retirer du code et de son historique), puis les alertes
   **hors délai**, par gravité et ancienneté. Les délais sont ceux de la politique de correction des
   vulnérabilités (configuration du plugin) ; un secret exposé prend le délai le plus court.
6. L'onglet **Sécurité du code** de chaque application résume ses alertes ; la fiche des mesures
   A.8.28 et A.8.29 affiche le bilan comme preuve. Pour être prévenu, créez un indicateur de risque
   « Secrets exposés non révoqués dans le code » ou « Alertes de sécurité du code hors délai ».
7. Pour une lecture chaque jour, cochez **Lire automatiquement chaque jour** : la tâche automatique
   `codesecurity` est désactivée après l'installation.

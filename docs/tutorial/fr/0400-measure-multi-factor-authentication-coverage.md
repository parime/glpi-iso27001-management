### Mesurer la couverture de l'authentification multifacteur (mesures A.8.5 et A.5.17)

L'auditeur demande quels comptes, et surtout quels administrateurs et accès distants, sont protégés
par une authentification multifacteur (MFA). Le plugin lit la double authentification de GLPI et
les méthodes que vous importez depuis votre fournisseur d'identité ; il ne modifie rien et ne lit
jamais de secret. Il faut le droit du plugin et la lecture native des utilisateurs.

1. Ouvrez Revue des accès > **Couverture MFA**. L'encadré « À quoi sert cette page ? » rappelle
   ce qui est attendu.
2. Les compteurs donnent le taux de MFA de tous les comptes actifs de vos entités, des
   **administrateurs** (profil qui peut modifier la configuration ou les profils de GLPI, ou rôle
   d'administrateur déclaré par le fournisseur) et des **comptes à accès distant**.
3. Dans **Méthodes enregistrées chez votre fournisseur d'identité**, choisissez le fournisseur
   (Microsoft Entra ID, Okta, Google Workspace, solution sur site) et importez son export CSV. Pour
   Entra ID : Protection > Méthodes d'authentification > Détails de l'inscription des utilisateurs,
   télécharger. Pour un autre outil : une ligne par compte avec les colonnes `login` ou `email`,
   `methods` (ou `mfa` oui/non), et au choix `admin`, `remote_access`, `excluded`,
   `exclusion_reason`. Les comptes sont rapprochés par identifiant, adresse e-mail ou partie de
   l'UPN avant le @ ; ceux qui ne correspondent à aucun compte GLPI sont listés à part.
   Pour Entra ID, vous pouvez aussi remplir **Lire directement Microsoft Entra ID (Microsoft
   Graph)** : inscrivez une application dans le centre d'administration Entra, donnez-lui la
   permission d'application AuditLog.Read.All avec le consentement de l'administrateur, créez un
   secret client, puis saisissez le locataire, l'identifiant d'application et le secret.
   **Lire maintenant dans Microsoft Graph** remplace l'import Entra ID de l'entité ; rien n'est
   écrit dans Entra ID.
4. Dans **Quels comptes ont un accès distant ?**, choisissez les groupes GLPI des utilisateurs du
   VPN ou du bureau à distance.
5. La liste commence par les **écarts** : administrateur sans MFA, accès distant sans MFA, SMS,
   appel ou e-mail seul, compte exclu sans justification. Traitez-les dans votre fournisseur
   d'identité, puis importez un nouvel export.
6. Sur la fiche des mesures A.8.5 et A.5.17, le cadre « Couverture MFA » montre les taux, le nombre
   d'administrateurs sans MFA et la date du calcul. Le tableau de bord propose la carte
   « Administrateurs protégés par MFA (%) », les indicateurs de risque la source « Administrateurs
   sans MFA », et la page Hygiène des comptes rappelle leur nombre.

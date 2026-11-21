### Décrire les compétences requises et suivre les écarts (article 7.2)

1. Ouvrez la rubrique Revue des accès > **Compétences**. Cliquez sur **Ajouter les fonctions par
   défaut manquantes** : RSSI, DPO, direction, administrateur, développeur, technicien et tout le
   personnel arrivent avec leurs compétences requises (niveau attendu et façon de l'acquérir).
2. Ouvrez une fonction, par exemple « Administrateur systèmes et réseaux ». Décrivez sa mission, puis
   indiquez qui l'exerce : un utilisateur, un groupe (par exemple « Équipe infrastructure ») ou un
   titre de GLPI (par exemple « Technicien / Technicienne », tel que créé par le plugin Configuration
   GLPI Auto). Ajustez les compétences requises si besoin.
3. Dans le **Catalogue des compétences**, ouvrez une compétence et liez les formations du plugin qui
   la donnent, avec le niveau atteint. Une personne qui réussit la formation (ou l'une de ses
   sessions) acquiert la compétence ; si la formation se renouvelle, la compétence expire avec elle.
4. Pour un certificat, un diplôme ou l'expérience constatée à l'entretien annuel, utilisez
   **Déclarer une compétence avec sa preuve** : la référence de la preuve est obligatoire, et le
   fichier se joint dans l'onglet Documents (il ne se lit qu'avec les droits de la déclaration).
5. La matrice **Personnes et compétences** montre, pour chaque titulaire, le niveau acquis et le
   niveau attendu. Sous la matrice, chaque écart propose une action : la formation à planifier, ou
   la preuve à déclarer. Rien n'est modifié automatiquement.
6. Chaque fonction a sa **fiche de poste sécurité** en PDF. Avant l'audit, cliquez sur **Archiver
   comme preuve** : le dossier daté (fiches de poste et écarts) est joint à l'exigence 7.2, dont la
   fiche résume l'état des compétences.
7. Chacun retrouve ses propres compétences dans ses préférences (onglet « Mes compétences »). Le
   responsable voit la matrice et l'onglet « Compétences » de chaque utilisateur, avec le droit du
   plugin et la lecture des utilisateurs, pour les personnes de ses entités.
8. Un écart pour lequel la personne est déjà inscrite à une formation (ou à une session) qui donne la
   compétence au niveau attendu affiche **Formation prévue**. La carte **Formations à planifier**
   liste, pour chaque formation qui comble un écart, les personnes pas encore inscrites : **Préparer
   une session** ouvre une nouvelle session de la formation avec ces personnes présélectionnées.
   Vérifiez la date et la liste, puis cliquez sur « Ajouter » : rien n'est enregistré avant.
9. Sur la page **Préparation à la certification**, l'étape « Définir et vérifier les compétences »
   (7.2) est faite quand chaque fonction a ses compétences exigées et ses titulaires, que chaque écart
   a une formation planifiée et que le dossier des compétences est archivé. La carte de tableau de
   bord « Couverture des compétences exigées (%) » suit les mêmes chiffres.
10. Si vos responsabilités sont déjà décrites dans la **Matrice des responsabilités**, une fonction
    peut en reprendre les titulaires : dans la fiche de la fonction, choisissez une **Activité de la
    matrice des responsabilités** et le **Rôle dans l'activité** (par exemple « Sauvegardes » et
    « Réalise »), puis enregistrez. Les personnes et les groupes qui tiennent ce rôle tiennent la
    fonction, avec le badge « Suit la matrice des responsabilités » : quand la matrice change, la
    fonction suit, sans rien recopier.
11. Chaque semaine, la tâche automatique « competencegaps » envoie au responsable de la préparation
    à la certification (et au groupe RSSI) la notification **Écarts de compétence sans formation
    planifiée** : chaque nouvel écart, avec la formation à planifier. Un écart n'est signalé qu'une
    fois. Les destinataires se règlent dans Configuration > Notifications ; seuls ceux qui lisent le
    plugin et les utilisateurs sur toutes les entités la reçoivent. Rien n'est modifié
    automatiquement.

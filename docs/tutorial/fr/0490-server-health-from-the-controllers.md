### Suivre la santé des serveurs et le journal de leur contrôleur (mesures A.7.13 et A.8.16)

Une fois les contrôleurs lus ({{step:0440-review-server-management-controllers}}), chaque lecture
Redfish reprend aussi la santé détaillée du serveur et les derniers événements du journal du
contrôleur. Rien n'est envoyé au serveur : uniquement des requêtes de lecture (GET) vers l'adresse
configurée.

1. Cliquez sur **Lire maintenant** sur la page **Contrôleurs de gestion des serveurs**.
2. Ouvrez l'ordinateur, onglet **Contrôleur de gestion**, cadre **Santé du serveur** : alimentations,
   disques (une panne annoncée compte comme un avertissement), ventilateurs et températures, les
   composants dégradés d'abord, puis les événements récents du journal (IML, SEL ou Lifecycle selon
   le constructeur). Les emplacements vides ne sont pas listés.
3. La vue **Sécurité 360°** de l'ordinateur ajoute la source **Santé du serveur** : critique si un
   composant ou un événement des sept derniers jours l'est, à surveiller pour un avertissement.
4. Pour une lecture chaque jour, activez la tâche automatique `bmcread` (lien **Régler l'action
   automatique** sur la page des contrôleurs). Elle est désactivée après l'installation ; une lecture
   en échec garde la précédente.

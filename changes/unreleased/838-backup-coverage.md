### Added

- **Couverture des sauvegardes** (issue #838, mesures A.8.13 et A.5.30). Nouvelle page « Couverture
  des sauvegardes » dans la rubrique Sécurité technique, en lecture seule : machines sauvegardées lues
  dans **Veeam Backup & Replication** par son API REST (sur clic, compte « Veeam Backup Viewer » :
  points de restauration des 31 derniers jours et dernier résultat de chaque travail ; aucune
  sauvegarde lancée, rien de modifié), ou importées par CSV depuis Acronis, Commvault, Synology
  Active Backup, Proxmox Backup Server ou un autre outil (une ligne par machine ou par exécution).
  Rapprochement avec les ordinateurs et matériels réseau comme la couverture de la journalisation
  (nom, nom court, adresse IP) : actifs sans sauvegarde, sauvegarde plus ancienne que le RPO de
  l'actif ou de ses processus métier (48 heures par défaut, réglable), dernière tentative en échec,
  travaux Veeam en échec, machines inconnues de GLPI. Dernier test de restauration de chaque actif
  repris des exercices de reprise (#733), avec une fréquence attendue réglable (12 mois). Preuve sur
  les mesures A.8.13 et A.5.30 (liste de sources de #781), KRI « actifs critiques sans sauvegarde
  récente (%) ». Le mot de passe Veeam est chiffré par GLPI, jamais réaffiché ni journalisé ; une
  lecture refusée ou incomplète ne remplace rien. Mise à jour : deux tables ajoutées (rejouable),
  supprimées à la désinstallation.

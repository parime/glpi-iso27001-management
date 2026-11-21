### Added

- **Réponse du fournisseur par lien sécurisé, sans compte GLPI** (issue #865, mesures A.5.19 à
  A.5.22). Désactivée par défaut, la fonction s'active dans la configuration du plugin. Un nouvel
  onglet **Lien sécurisé** d'une évaluation crée un lien personnel à envoyer au fournisseur : jeton
  aléatoire de 32 octets dont GLPI ne garde que l'empreinte SHA-256, lié à une seule évaluation, avec
  une durée de validité (14 jours par défaut, 60 au plus) et un nombre d'envois limité (5 au plus),
  révocable à tout moment. La page publique ne montre que les questions de cette évaluation (ni
  entité, ni utilisateur, ni nom du fournisseur) ; elle est protégée par un jeton CSRF, une
  limitation de débit par adresse IP et par lien, et des en-têtes de sécurité (politique de contenu
  sans script, pas d'affichage dans un cadre, pas de référent). Les pièces jointes passent les mêmes
  contrôles que les autres preuves (types acceptés par GLPI, taille, contenu réel). Les réponses
  reçues restent en brouillon : un utilisateur GLPI les relit dans le même aperçu que le fichier,
  puis les enregistre (origine « lien ») ou les rejette. Création du lien, ouverture, envoi,
  révocation et relecture sont inscrits dans le journal d'audit sécurité.

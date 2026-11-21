### Remettre le dossier de preuves à l'auditeur (articles 7.5.3 et 9.2)

L'auditeur demande souvent, avant sa visite, l'ensemble des preuves du périmètre audité. La page
**Conformité > Dossier de preuves** le prépare en une archive ZIP.

1. Choisir le **périmètre** (une entité) et cliquer sur **Afficher le contenu** : nombre de pièces
   incluses, expirées, sans fichier ou au-delà de la limite de taille, mesures applicables sans
   preuve, et liste des preuves expirées.
2. Renouveler les preuves expirées (onglet **Validités des preuves** de la mesure) et joindre ce qui
   manque.
3. Cliquer sur **Générer le dossier de preuves**, puis le télécharger dans **Dossiers de preuves
   archivés** : l'archive contient un index PDF (mesure par mesure, sous-article par sous-article,
   avec la date, l'état et l'empreinte SHA-256 de chaque pièce), un fichier `SHA256SUMS.txt` et
   les pièces, chacune une seule fois dans le dossier `evidence`.

Seules les pièces que vous pouvez lire entrent dans l'archive ; une preuve expirée figure dans
l'index sans être incluse. La limite de taille se règle sur la page (200 Mo par défaut). L'archive
est gardée comme document restreint : vous, et toute personne qui peut lire chacune de ses pièces,
pouvez la télécharger. Rien n'est modifié dans les registres.

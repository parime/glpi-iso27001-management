### Added

- **Réponse du fournisseur hors ligne, par fichier** (partie de l'issue #865, mesures A.5.19 à
  A.5.22). Un nouvel onglet **Fichier fournisseur** d'une évaluation télécharge le questionnaire à
  remplir (CSV « ; » en UTF-8, une ligne par question, colonnes réponse, commentaire et nom de la
  pièce jointe), que le RSSI envoie par le moyen de son choix. Le fichier retourné est réimporté
  avec contrôle : repère de l'évaluation et empreinte de ses questions (un fichier d'une autre
  évaluation ou d'autres questions est refusé), réponses rattachées par code, écarts signalés
  (question inconnue ou absente, réponse hors liste, doublon), aperçu des changements, et rien
  n'est enregistré sans confirmation. Les pièces envoyées par le fournisseur sont déposées au même
  moment et rattachées aux questions par leur nom, avec les contrôles habituels des preuves. Les
  réponses importées portent l'origine « fichier », le fichier reçu rejoint les documents du risque
  fournisseur et chaque export ou import est tracé dans l'historique (qui, quand, quel fichier,
  empreinte). Fichiers piégés écartés : classeur ou binaire renommé, formules neutralisées à
  l'export, caractères de contrôle et chemins retirés, taille et nombre de lignes limités.

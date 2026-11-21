### Added

- **Questionnaires fournisseurs configurables** (issue #864, mesures A.5.19 à A.5.21, NIS2
  art. 21 §2 d), DORA art. 28). Les douze questions fixes du questionnaire de sécurité deviennent
  des modèles éditables :
  - bibliothèque « Modèles de questionnaire » ouverte depuis la liste des risques fournisseurs
    (aucune nouvelle entrée de menu) : nom, domaine (sécurité de l'information, données
    personnelles, intelligence artificielle, continuité d'activité, autre), entité et
    sous-entités comme les risques fournisseurs, copie d'un modèle ;
  - onglet « Questions » du modèle : sections, texte, aide, réponse attendue (« oui » ou « non »),
    pondération de 0 à 10 (0 : question informative), pièce justificative exigée, ordre ; chaque
    changement crée une nouvelle version, et une empreinte des questions est calculée pour
    reconnaître plus tard un fichier retourné par un fournisseur (#865) ;
  - plusieurs évaluations par risque fournisseur (onglet « Questionnaires »), chacune avec son
    modèle, son score pondéré (réponse attendue 1 point, partiel 0,5, autre réponse 0, multiplié
    par la pondération ; « sans objet » et l'absence de réponse ne comptent pas), son évaluateur, sa
    date, son historique et l'évolution entre les deux dernières évaluations d'un même domaine ;
    les questions sont copiées dans l'évaluation, qui ne change pas si le modèle change ensuite ;
  - pièce justificative envoyée question par question : Document GLPI joint au risque fournisseur
    (onglet Documents), dans son entité, lisible avec ses seuls droits, avec les contrôles des
    preuves (types autorisés par GLPI, taille maximale, type réel du contenu) ; badges « pièce
    manquante » et « écart » ;
  - une évaluation terminée ne se modifie plus sauf à la rouvrir ; la dernière évaluation terminée
    du domaine sécurité alimente le score affiché sur le risque et la carte du tableau de bord. Le
    niveau de risque n'est jamais modifié.

  Reprise sans perte : les douze questions d'origine forment le modèle « Questionnaire
  historique » (désactivable, non supprimable) et chaque questionnaire déjà rempli devient une
  évaluation terminée avec ses réponses, son score, sa date et son auteur d'origine (colonnes
  d'origine conservées). Quatre nouvelles tables, créées à l'installation et à la mise à jour si
  absentes (rejouable), supprimées à la désinstallation. Matrice d'accès et contrôle XSS étendus.

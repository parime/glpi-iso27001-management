### Faire répondre un fournisseur par fichier

Quand le fournisseur n'a pas accès à GLPI, envoyez-lui le questionnaire d'une évaluation sous forme
de fichier (voir {{step:0075-evaluate-supplier-questionnaires.md}}).

1. Dans l'onglet **Fichier fournisseur** de l'évaluation, cliquez sur **Télécharger le
   questionnaire à remplir**. Le fichier CSV contient une ligne par question ; sa première ligne
   identifie l'évaluation et ses questions, elle ne doit pas être modifiée.
2. Envoyez-le par le moyen de votre choix. Le fournisseur remplit dans un tableur les colonnes
   **Réponse du fournisseur** (Oui, Partiel, Non, Sans objet), **Commentaire** et, pour chaque document fourni,
   **Nom de la pièce jointe**, puis l'enregistre au format CSV.
3. Au retour, choisissez le fichier dans le même onglet et cliquez sur **Vérifier les réponses**.
   L'aperçu montre, question par question, la réponse actuelle et celle du fichier, ainsi que les
   points à vérifier (question inconnue ou absente, réponse hors liste).
4. Ajoutez les documents envoyés par le fournisseur : chacun est rattaché aux questions qui citent
   son nom. Cliquez sur **Enregistrer ces réponses** ; rien n'est enregistré avant.
5. Relisez l'onglet **Questionnaire**, puis marquez l'évaluation comme terminée. L'onglet
   **Historique** garde la trace de chaque export et de chaque import.

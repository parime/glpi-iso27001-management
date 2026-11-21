### Faire répondre un fournisseur par lien sécurisé

Si vos fournisseurs peuvent joindre votre GLPI, ils peuvent répondre au questionnaire en ligne, sans
compte GLPI (sinon, voir {{step:0460-supplier-answer-by-file}}).

1. Un administrateur du plugin active **Réponse du fournisseur par lien sécurisé** dans la
   configuration du plugin. La fonction est désactivée par défaut.
2. Dans l'onglet **Lien sécurisé** de l'évaluation, choisissez la durée de validité et le nombre
   d'envois autorisés, puis cliquez sur **Créer un lien**. Copiez le lien affiché : il n'est montré
   qu'une fois, GLPI n'en garde qu'une empreinte.
3. Envoyez-le au fournisseur. Sa page ne montre que les questions de cette évaluation ; il choisit
   ses réponses, ajoute des commentaires et ses documents, puis clique sur **Envoyer mes réponses**.
4. Les réponses reçues apparaissent dans **Réponses à relire**. Cliquez sur **Vérifier les
   réponses** : l'aperçu compare chaque réponse à la réponse actuelle. Enregistrez-les, ou
   rejetez-les ; rien ne compte dans l'évaluation avant.
5. Un lien se révoque à tout moment (bouton **Révoquer**). Le journal d'audit sécurité garde la
   création, chaque ouverture, chaque envoi et chaque révocation.

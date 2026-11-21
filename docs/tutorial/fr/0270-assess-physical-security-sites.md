### Évaluer la sécurité physique des sites (mesures A.7.1 à A.7.14)

1. Les sites sont les **lieux de GLPI** (Configuration > Intitulés > Lieux) : créez par exemple
   « Siège fictif, 12 rue des Essais, Villefictive » et « Entrepôt fictif, 4 allée des Tests ». Aucun
   second référentiel des sites.
2. Ouvrez Conformité > **Sécurité physique**, puis **Évaluer un site**. Choisissez le site, la date
   et l'évaluateur (il recevra le rappel), liez la **politique de sécurité physique et
   environnementale** : elle reste un document de la bibliothèque des politiques.
3. Une fois l'évaluation enregistrée, l'onglet **Grille de sécurité physique** compte une ligne par
   mesure A.7.1 à A.7.14 (« Non applicable » d'office pour une mesure exclue de la SoA). Pour chaque
   ligne, cliquez sur **Renseigner** : état (conforme, partiellement conforme, non conforme, non
   applicable), constat, preuves examinées. Ajoutez les photos et procès-verbaux dans l'onglet
   « Documents » de l'évaluation, puis citez-les sur la ligne : ils ne se lisent qu'avec le droit de
   lire l'évaluation.
4. Pour une ligne partielle ou non conforme (« Porte de la baie serveur non verrouillée », par
   exemple), cliquez sur **Créer l'action** : une non-conformité pré-remplie s'ouvre (site, mesure,
   constat). Indiquez le responsable et l'échéance, puis **Ajouter** : le workflow CAPA habituel
   prend le relais et la ligne affiche l'action et son statut.
5. L'onglet **Zones du site** liste les zones (publique, contrôlée, sensible, local technique), leurs
   contrôles d'accès (badge, clé, digicode, vidéosurveillance...), les personnes autorisées et si les
   consignes d'accès sont affichées. Une zone sensible ou un local technique sans contrôle d'accès ou
   sans consignes est signalé.
6. Passez l'évaluation à **Terminée** : la prochaine évaluation est fixée 12 mois plus tard. La tâche
   automatique « reassessmentreminder » prévient l'évaluateur 30 jours avant, puis une fois par mois
   tant que le site n'est pas réévalué ; l'échéance figure aussi dans le calendrier de conformité. La
   réévaluation reprend les zones et montre, ligne par ligne, l'état de l'évaluation précédente.
7. La page **Sécurité physique** compare la dernière évaluation de chaque site, mesure par mesure,
   avec le score et l'échéance. Sur la fiche d'une mesure A.7.x de la SoA, le cadre « Sécurité
   physique des sites » donne l'état de la mesure sur chaque site. Le lieu de GLPI a aussi un onglet
   « Sécurité physique » avec l'historique de ses évaluations.
8. Pour l'auditeur, cliquez sur **Rapport (PDF)** en haut de la fiche de l'évaluation : résumé
   (évaluateur, statut, politique, score), grille A.7.1 à A.7.14 avec constats, preuves et actions,
   puis les zones et leurs contrôles d'accès. Le rapport est calculé à la demande avec vos droits.

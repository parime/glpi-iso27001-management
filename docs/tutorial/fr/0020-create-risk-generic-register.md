### Créer un risque dans le registre générique

Depuis **GRC & Conformité > Risques**, la liste est vide tant qu'aucun risque n'a été créé.

![Registre de risques vide](screenshots/tutorial/03-risk-list-empty.png)

Un nouveau risque demande un titre, une catégorie (humain/processus/physique/tiers-fournisseur/
technique), une probabilité et un impact.

![Formulaire de création d'un risque](screenshots/tutorial/04-risk-form-filled.png)

Une fois enregistré, le niveau de risque (faible/moyen/élevé/critique) et le score numérique sont
calculés automatiquement à partir de la probabilité et de l'impact, selon la matrice configurée
(voir étape {{step:0030-configure-probability-x-impact-matrix}}).

![Risque enregistré avec niveau et score calculés](screenshots/tutorial/05-risk-created-level.png)

Le propriétaire du risque peut ensuite fixer une décision de traitement (accepter/mitiger/
transférer/éviter) accompagnée d'une justification.

![Décision de traitement et justification avant enregistrement](screenshots/tutorial/06-risk-treatment-filled.png)

![Décision de traitement enregistrée](screenshots/tutorial/07-risk-treatment-saved.png)

La liste affiche ensuite chaque risque avec des badges colorés pour la catégorie, le niveau de
risque, l'impact et le traitement.

![Registre de risques avec badges colorés](screenshots/tutorial/08-risk-list-with-badges.png)

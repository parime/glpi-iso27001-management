### Créer un risque dans le registre fournisseurs/tiers

Depuis **Gestion > Sécurité des fournisseurs > Risques fournisseurs**, la liste affiche les risques fournisseurs déjà
enregistrés sur cette instance.

![Registre de risques fournisseurs](screenshots/tutorial/28-supplierrisk-list.png)

Un nouveau risque fournisseur reprend exactement les mêmes champs catégorie/probabilité/impact
que le registre générique (voir étape {{step:0020-create-risk-generic-register}}), avec en plus un fournisseur GLPI natif (itemtype
`Supplier` du cœur GLPI) obligatoire : ce registre couvre la dépendance à un tiers (clause
6.1.2/8.2, Annexe A.5.19-A.5.22).

![Formulaire de création d'un risque fournisseur](screenshots/tutorial/29-supplierrisk-form-filled.png)

Comme pour le registre générique, le niveau de risque et le score sont calculés automatiquement
à l'enregistrement, selon la même matrice probabilité x impact configurée à l'étape {{step:0030-configure-probability-x-impact-matrix}}.

![Risque fournisseur enregistré avec niveau calculé](screenshots/tutorial/30-supplierrisk-created-level.png)

Le même cycle de décision de traitement (accepter/mitiger/transférer/éviter) et de justification
que le registre générique s'applique ensuite.

![Décision de traitement et justification avant enregistrement](screenshots/tutorial/31-supplierrisk-treatment-filled.png)

![Décision de traitement enregistrée](screenshots/tutorial/32-supplierrisk-treatment-saved.png)

La liste affiche ensuite chaque risque fournisseur avec le fournisseur GLPI lié et les mêmes
badges colorés que le registre générique.

![Registre de risques fournisseurs avec badges colorés](screenshots/tutorial/33-supplierrisk-list-with-badges.png)

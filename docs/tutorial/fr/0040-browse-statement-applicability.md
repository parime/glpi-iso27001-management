### Parcourir la Déclaration d'Applicabilité (SoA)

Depuis **GRC & Conformité > Conformité**, les 93 mesures réelles de l'Annexe A ISO/IEC 27001:2022
sont listées, préchargées à l'installation et marquées "Applicable" par défaut (clause 6.1.3).

![Liste complète des 93 contrôles Annexe A](screenshots/tutorial/10-control-list.png)

La liste peut être filtrée par thème (Organisationnel, Humain, Physique, Technologique) grâce au
formulaire de recherche natif de GLPI.

![Contrôles filtrés sur le thème Technologique](screenshots/tutorial/11-control-list-filtered-theme.png)

Chaque contrôle s'ouvre pour ajuster son applicabilité (applicable/non applicable/partiellement
applicable) et son état de mise en œuvre.

![Formulaire d'un contrôle, applicabilité par défaut](screenshots/tutorial/12-control-form-applicable.png)

Dès que l'applicabilité passe à "Non applicable" ou "Partiellement applicable", une justification
devient obligatoire (clause 6.1.3 d) : tenter d'enregistrer sans la renseigner affiche une erreur
et bloque l'enregistrement.

![Applicabilité partielle avant enregistrement, justification vide](screenshots/tutorial/13-control-form-partial-before-save.png)

![Erreur : justification obligatoire](screenshots/tutorial/14-control-form-justification-error.png)

Une fois la justification renseignée, l'enregistrement réussit.

![Justification renseignée avant enregistrement](screenshots/tutorial/15-control-form-justified-before-save.png)

![Contrôle enregistré avec sa justification](screenshots/tutorial/16-control-form-justified-saved.png)

**Motifs de sélection et mesures compensatoires.** Sous la justification, cochez pourquoi la mesure
est retenue : résultat de l'appréciation des risques, obligation légale ou réglementaire, exigence
contractuelle, attente d'une partie intéressée, bonne pratique (plusieurs motifs possibles). Tant
que personne ne les a renseignés, les motifs déduits des liens sont déjà cochés, avec la mention
« D'après les liens » : un risque lié coche l'appréciation des risques, une obligation du registre
rattachée à ce risque coche le motif légal ou contractuel. Vous pouvez les décocher. Pour une
mesure non applicable ou partiellement applicable, quatre champs apparaissent : catégorie
d'exclusion (activité absente, assurée par un prestataire certifié, hors du périmètre du SMSI),
mesures compensatoires choisies parmi les autres mesures de la même SoA, description libre et
risque résiduel. Exemple fictif : A.7.4 non applicable, aucun local propre, hébergement chez un
prestataire certifié ; mesure compensatoire A.5.20 (clause d'audit du contrat). La justification
reste obligatoire. Ces informations figurent dans la liste (filtres « Motifs de sélection »,
« Catégorie d'exclusion », « Risque résiduel »), dans l'export CSV et dans le PDF, avec une synthèse
par thème (mesures applicables, exclues, mises en œuvre, en cours, planifiées). Une SoA déjà
approuvée n'est pas modifiée : ce que vous ajoutez entre dans la version suivante à faire approuver.

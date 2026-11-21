### Prouver la sensibilisation par les simulations d'hameçonnage (article 7.3, mesure A.6.3)

1. Avant la première simulation, informez le personnel que des simulations d'hameçonnage ont lieu
   (finalité : sensibiliser, pas sanctionner) et inscrivez ce traitement au registre RGPD : les
   résultats par personne sont des données personnelles. Décidez combien de temps vous les gardez
   (par exemple jusqu'à la fin de la formation de rattrapage).
2. Lancez la campagne avec votre outil de simulation (GoPhish, l'outil d'un prestataire...). **Le
   plugin n'envoie jamais aucun courriel.**
3. Dans le menu du plugin, ouvrez **Opérations > Simulations d'hameçonnage** (à côté des
   formations), cliquez sur **Ajouter** et renseignez le nom (par exemple « Faux avis de livraison
   (fictif) »), la date, l'outil, le scénario et la population visée. Choisissez l'entité des
   personnes concernées.
4. Dans l'onglet **Résultats**, choisissez l'export CSV des résultats (une ligne par personne) et
   cliquez sur **Suivant**. Les colonnes habituelles sont reconnues : adresse électronique ou
   identifiant, courriel reçu, ouvert, lien cliqué, données saisies, signalé, ou la colonne
   « status » d'un export GoPhish. Vérifiez-les, puis cliquez sur **Importer**. Un nouvel import
   remplace les résultats de la campagne ; le **Fichier d'exemple** montre le format attendu avec
   des adresses fictives.
5. Chaque ligne est rapprochée d'un utilisateur GLPI actif de l'entité de la campagne par son
   adresse électronique (ou son identifiant). Les lignes non rapprochées (adresse inconnue ou
   ambiguë, personne d'une autre entité) sont listées avec leur numéro de ligne : corrigez l'adresse
   dans GLPI ou dans le fichier, puis importez de nouveau.
6. L'onglet affiche les taux rapportés aux courriels reçus (ouverts, lien cliqué, données saisies,
   signalés). La liste des campagnes montre l'évolution d'une campagne à l'autre. Pour suivre le
   taux de clic avec des seuils, créez un indicateur de risque (KRI) avec la source « Taux de clic
   de la dernière simulation d'hameçonnage (%) ».
7. Cliquez sur **Proposer une formation de rattrapage** : un formulaire de formation s'ouvre avec
   les personnes qui ont cliqué ou saisi des données. Vérifiez-le et cliquez sur **Ajouter** ; rien
   n'est enregistré ni envoyé avant.
8. **Qui voit quoi** : les résultats par personne et les lignes non rapprochées exigent le droit sur
   les registres sensibles (lecture ; modification pour importer ou supprimer). Sans lui, seuls les
   taux sont affichés, et pas du tout en dessous de 5 personnes. Chacun voit ses propres résultats
   dans **Préférences > Mes simulations d'hameçonnage**. Une fois le suivi terminé, cliquez sur
   **Supprimer les résultats par personne** : les taux de la campagne sont gardés.
9. Sur la mesure **A.6.3** et sur l'exigence du SMSI **7.3**, le cadre « Simulations
   d'hameçonnage » montre le nombre de campagnes sur 12 mois, les taux de la dernière campagne et
   l'évolution des clics depuis la précédente.
10. **Taux par groupe et par entité** : l'onglet **Résultats** montre aussi les taux par groupe GLPI
    des personnes rapprochées (une personne de plusieurs groupes compte dans chacun). Sans le droit
    sur les registres sensibles, les groupes de moins de 5 personnes sont réunis dans « Autres
    groupes », masqué lui aussi s'il reste trop petit. Quand vos campagnes couvrent plusieurs
    entités, la liste des campagnes montre la dernière campagne de chaque entité. Ces taux sont
    anonymes et restent après la suppression des résultats par personne. Au tableau de bord,
    ajoutez la carte « Simulations d'hameçonnage : taux par campagne (%) » pour suivre les clics, les
    données saisies et les signalements d'une campagne à l'autre.
11. **Durée de conservation** : dans la configuration du plugin, réglez **Simulations
    d'hameçonnage : conservation des résultats par personne** (en mois à partir de la date de la
    campagne ; 0 = jusqu'à ce que vous les supprimiez à la main). Il faut le droit de modifier les
    registres sensibles. L'action automatique quotidienne « phishingretention » supprime ensuite les
    résultats par personne expirés et garde les taux ; l'onglet **Résultats** affiche la date prévue.
    Inscrivez la même durée dans votre registre RGPD.

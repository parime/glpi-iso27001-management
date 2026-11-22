### Rédiger, faire approuver et tester les plans de continuité et de reprise

Les plans de continuité et de reprise répondent aux mesures A.5.29 et A.5.30 de l'ISO 27001 :
**Assistance > Crise et continuité > Plans de continuité et de reprise**.

1. Créez un plan avec l'un des quatre boutons : **PCA** et **PRA** pour une activité métier,
   **PCI** et **PRI** pour un système informatique. Le plan démarre avec une trame à remplacer
   (réglable dans **Configuration > Plugins > GRC Manager**, onglet **Incidents et continuité**).
2. Choisissez le responsable, l'approbateur (en général un membre de la direction), la cellule de
   crise et l'astreinte (groupes GLPI), puis les **processus couverts** : leurs DMIA/PDMA (RTO/RPO)
   viennent de leur BIA, avec le dernier test de reprise de chacun.
3. Complétez le contenu : déclenchement, rôles et contacts, étapes, repli, dépendances,
   communication.
4. Onglet **Versions et approbation** : **Figer et envoyer pour approbation**. L'approbateur
   approuve ou refuse depuis le même onglet ; une fois approuvé, le plan n'est plus modifiable et
   sa prochaine revue est datée. Pour le changer, préparez une nouvelle version.
5. Onglet **Tests du plan** : enregistrez chaque exercice (date, type, résultat, écarts). Un écart
   devient une non-conformité avec **Créer une non-conformité**.
6. **Imprimer le plan (PDF)** pour en garder une copie hors de GLPI.

La page d'accueil de la rubrique et la fiche des mesures A.5.29 et A.5.30 montrent les plans non
approuvés, les revues dépassées, les plans non testés dans l'année et les processus critiques sans
plan.

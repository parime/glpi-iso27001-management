### Vérifier les licences logicielles (mesure A.5.32)

Le plugin lit les logiciels et les licences déjà enregistrés dans GLPI (Parc > Logiciels, onglet
Licences) ; il ne modifie rien. Il faut le droit « Registres sensibles » du plugin, et les droits
natifs de lecture des logiciels et des licences.

1. Ouvrez Sécurité technique > **Licences logicielles**. L'encadré « À quoi sert cette page ? »
   rappelle ce qui est attendu.
2. En bas de la page, vérifiez la liste des **éditeurs commerciaux surveillés** (Microsoft, Adobe,
   Oracle, Autodesk...) : c'est le champ « Fabricant » du logiciel dans GLPI. La seconde liste
   écarte les outils gratuits de ces éditeurs (« Redistributable », « Acrobat Reader »...). Seule
   une personne qui peut modifier la configuration du plugin change ces listes.
3. Les compteurs donnent le nombre de constats, regroupés ensuite par entité :
   - **Logiciel commercial sans licence** : un logiciel d'un éditeur surveillé est installé, et
     aucune licence ne compte pour cette entité. Une licence compte pour une entité si elle lui
     appartient, ou si elle est récursive et appartient à une entité parente.
   - **Plus d'installations que de postes licenciés** : par exemple 12 ordinateurs équipés pour une
     licence de 10 postes. Une licence illimitée (nombre 0 ou moins) couvre tout ; une licence
     expirée ne compte plus. Une licence récursive est partagée par les sous-entités : la mention
     « Partagée avec les sous-entités » compte leurs installations ensemble. Une licence fille
     (champ « Comme enfant de » de GLPI) partage les postes de sa mère : la famille compte pour le
     plus grand du nombre de la mère et de la somme des filles, avec le badge **Licences filles
     comptées une fois**.
   - **Licence expirée encore utilisée** : des ordinateurs y sont encore affectés, ou le logiciel
     reste installé sans autre licence valable.
   - **Licence arrivant à échéance** (rappel, pas un écart) : une licence utilisée expire dans les
     60 prochains jours et aucun renouvellement n'est saisi. Réglez ce délai en bas de la page
     (« Alerter avant l'échéance d'une licence », 0 pour désactiver).

   Le badge **Dépassement autorisé** signale un surdéploiement sur une licence qui l'autorise : il
   reste à régulariser auprès de l'éditeur. Au-dessus des constats, la **couverture des licences**
   donne la part des logiciels commerciaux surveillés installés qui ont une licence valable.
4. Pour chaque constat, ouvrez le logiciel ou la licence (liens) et corrigez dans GLPI : saisissez
   la licence achetée, ajoutez des postes, désinstallez le logiciel, ou renouvelez la licence et
   déplacez les ordinateurs affectés.
5. Cliquez sur **Export (CSV)** pour remettre la liste à l'auditeur.
6. Sur la fiche de la mesure A.5.32 de la SoA, le cadre « Conformité des licences logicielles »
   montre les compteurs, les licences arrivant à échéance et la couverture des licences ; les
   contrôles de cohérence du SMSI (onglet du tableau de bord) listent chaque écart.

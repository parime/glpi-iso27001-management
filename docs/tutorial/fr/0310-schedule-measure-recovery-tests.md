### Programmer et mesurer les tests de reprise (mesure A.5.30)

L'auditeur demande la preuve que la reprise des TIC est testée régulièrement, et que les temps
mesurés tiennent les objectifs. Il faut le droit **Continuité d'activité** du profil.

1. Ouvrez un processus métier fictif, par exemple « Facturation (fictif) », avec un **RTO** de 4 h
   et un **RPO** de 1 h, puis l'onglet **Stratégie de reprise**.
2. Dans **Programme de tests**, réglez la **fréquence de test** (par exemple 6 mois). 0 garde la
   fréquence de la revue périodique des tests de continuité (annuelle par défaut). La prochaine
   échéance et la **couverture par année** (badge vert : objectifs tenus, rouge : objectifs non
   tenus, gris : non testé) s'affichent à côté.
3. Saisissez un test : temps de reprise mesuré, **perte de données** en minutes (le temps entre la
   dernière sauvegarde utilisable et l'incident ; laissez vide si elle n'a pas été mesurée, 0 si rien
   n'a été perdu), **type d'exercice** (sur table, fonctionnel, test technique de restauration,
   grandeur nature) et, si besoin, l'**élément testé** parmi les dépendances du processus. Le test
   est comparé au RTO et au RPO du processus, et à ceux de l'élément testé (onglet Classification).
4. Sur la page **Exercices de crise**, choisissez le type au lancement ; il reste modifiable dans la
   salle de crise jusqu'à la validation du bilan. Un test saisi depuis la salle de crise reprend le
   type de l'exercice.
5. La liste des processus métier a un bouton **Programme des tests de reprise** : tous les
   processus, leur couverture sur quatre ans, la fréquence et la prochaine échéance, avec le nombre
   de processus en retard ou non testés cette année. C'est la vue à montrer à l'auditeur.
6. Un processus non testé à temps (ou jamais testé, une période après sa création) est signalé à
   son responsable et au groupe RSSI par la tâche automatique **businessprocessreminder**. Rien
   n'est modifié automatiquement.
7. Sur la fiche d'un élément (serveur, application...), l'onglet **Processus métier** liste les
   tests de reprise qui l'ont couvert. La liste des types se règle dans **Configuration > GRC
   Manager**, encadré **Types d'exercice de reprise**.

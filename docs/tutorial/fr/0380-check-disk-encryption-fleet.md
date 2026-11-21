### Vérifier le chiffrement des disques du parc (mesures A.8.24 et A.8.1)

L'agent d'inventaire de GLPI remonte, pour chaque volume d'un ordinateur, l'état du chiffrement,
l'outil (BitLocker, FileVault, LUKS, VeraCrypt...) et l'algorithme. Le plugin lit ces données ; il
ne modifie rien dans GLPI et ne lit ni ne stocke jamais de clé de récupération. Il faut le droit du
plugin et le droit natif de lecture des ordinateurs.

1. Ouvrez Sécurité technique > **Chiffrement des disques**. L'encadré « À quoi sert cette page ? »
   rappelle ce qui est attendu.
2. Dans **Quels ordinateurs doivent être chiffrés ?**, choisissez les ordinateurs concernés : tous
   (par défaut), les portables et tablettes seulement (type d'ordinateur remonté par l'agent :
   Laptop, Notebook, Tablet...), ou les types choisis. Vous pouvez y ajouter tout ordinateur classé
   en confidentialité moyenne ou élevée (classification C/I/D, onglet de la fiche de l'ordinateur).
   Il faut le droit de modifier le plugin.
3. Les compteurs donnent le taux d'ordinateurs concernés entièrement chiffrés, le taux des
   portables, les algorithmes faibles (DES, RC4...) et anciens (BitLocker avec diffuseur, mode CBC),
   puis le nombre d'ordinateurs entièrement chiffrés, partiellement chiffrés, non chiffrés ou d'état
   inconnu (aucun volume inventorié : pas d'agent, ou un agent trop ancien).
4. Les tableaux par entité, type et lieu montrent où porter l'effort.
5. La liste commence par les **écarts** : un ordinateur concerné dont un volume n'est pas chiffré,
   ou chiffré avec un algorithme faible. Pour chacun : volumes en clair, outil, algorithme et date du
   dernier inventaire. Chiffrez le poste, puis attendez le prochain inventaire.
6. Colonne **Clé de récupération sauvegardée (déclarée)** : cochez la case et saisissez la date
   quand la clé est sauvegardée (annuaire, coffre de l'équipe). Seule cette déclaration est gardée.
7. Sur la fiche des mesures A.8.24 et A.8.1, le cadre « Chiffrement des disques » montre le taux,
   la date du calcul et le nombre d'écarts. Dans l'onglet des tests de la mesure, le contrôle
   technique « Postes avec un disque non chiffré » (A.8.24) ou « Portables avec un disque non
   chiffré » (A.8.1) s'enregistre comme test d'efficacité. Le tableau de bord propose la carte
   « Ordinateurs à chiffrer qui le sont (%) » et les indicateurs de risque la source « Portables
   avec un disque non chiffré (%) ».

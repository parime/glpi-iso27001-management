### Mesurer la conformité de configuration avec OpenSCAP (mesure A.8.9)

OpenSCAP vérifie une machine contre un guide de durcissement (profils CIS, ANSSI, DISA STIG… du
projet SCAP Security Guide) et produit un fichier de résultats XCCDF ou ARF. Le plugin lit ce
fichier : il ne lance aucune analyse et ne modifie rien sur les machines. Il faut le droit du plugin
et le droit natif de lecture des ordinateurs ; importer demande le droit de modifier le plugin.

1. Sur la machine, lancez l'analyse avec un fichier de résultats, par exemple
   `oscap xccdf eval --profile <profil> --results-arf resultats-arf.xml <ssg-datastream.xml>`.
   L'option `--results` (XCCDF seul) convient aussi. Le rapport HTML ne s'importe pas.
2. Ouvrez Sécurité technique > **Conformité de configuration**. L'encadré « À quoi sert cette
   page ? » rappelle ce qui est attendu. Dans **Importer des résultats OpenSCAP**, choisissez le
   fichier et cliquez sur **Importer**. Un fichier peut contenir plusieurs machines ; un fichier déjà
   importé n'est pas compté deux fois. Un fichier d'exemple fictif est téléchargeable.
3. Chaque résultat est rapproché de l'ordinateur GLPI du même nom (nom d'hôte, nom complet ou nom
   court), parmi ceux que vous voyez. Les résultats sans ordinateur, ou avec plusieurs homonymes,
   sont listés dans **Résultats d'analyse non rapprochés d'un ordinateur** : choisissez l'ordinateur
   et cliquez sur **Rattacher**.
4. Les compteurs donnent la conformité moyenne, le nombre de machines analysées, celles sous
   l'objectif de 80 % et celles avec une règle de gravité haute en échec. Le taux d'une analyse est
   la part des règles conformes parmi les règles vérifiées (les règles non applicables ne comptent
   pas). La liste montre la dernière analyse de chaque ordinateur, les moins conformes d'abord, avec
   l'évolution depuis l'analyse précédente du même profil.
5. **Détails** ouvre une analyse : règles en échec par gravité et historique de la machine. Le
   bouton **Proposer un ticket** crée, sur votre clic seulement, un ticket de remédiation lié à
   l'ordinateur avec les règles en échec. Les règles en échec les plus fréquentes du parc sont en bas
   de page.
6. Sur la fiche de la mesure A.8.9, le cadre « Conformité de configuration » montre ces chiffres et
   la date de la dernière analyse. Les indicateurs de risque proposent la source « Ordinateurs
   analysés avec une règle de configuration de gravité haute en échec (%) ».

Sécurité de l'import : le fichier est lu en flux (les gros rapports ARF passent), toute déclaration
de type de document ou d'entité est refusée et aucun accès réseau n'est fait pendant la lecture. Le
fichier lui-même n'est pas conservé, seulement les compteurs et les règles en échec.

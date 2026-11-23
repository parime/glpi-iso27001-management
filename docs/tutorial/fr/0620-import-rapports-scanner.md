### Importer les rapports d'un scanner de vulnérabilités

Les vulnérabilités du parc viennent d'abord des logiciels inventoriés. Un scanner réseau gratuit
comme Greenbone Community Edition (OpenVAS) en trouve d'autres : services exposés, protocoles
obsolètes, fichiers sensibles. Pour en faire une preuve de la mesure A.8.8 :

1. Dans Greenbone, ouvrez le rapport d'une analyse terminée, choisissez **Télécharger** et le
   format **XML**.
2. Dans GLPI, ouvrez **Parc > Sécurité du parc**, carte **Vulnérabilités**, puis **Rapports des
   scanners de vulnérabilités**. Le lien **Télécharger un fichier d'exemple** donne un rapport
   fictif pour essayer.
3. Choisissez le fichier et cliquez sur **Importer**. Chaque constat est rattaché à l'ordinateur
   GLPI de même nom ou de même adresse IP. Les hôtes non reconnus sont listés dans **Hôtes analysés
   non rattachés à un ordinateur** : choisissez l'ordinateur et cliquez sur **Rattacher**, les
   imports suivants reprendront ce choix.
4. Traitez d'abord les constats critiques et élevés : le bouton **Proposer un ticket** crée un
   ticket lié à l'ordinateur, avec le constat et sa solution. Rien n'est créé sans ce clic.
5. Après l'analyse suivante, importez le nouveau rapport : les constats encore présents sont mis à
   jour sans doublon, ceux qui ont disparu passent **Corrigé**. L'**Historique des imports** montre
   l'évolution d'une analyse à l'autre.

Les constats ouverts apparaissent aussi sur la fiche de la mesure A.8.8 et dans l'onglet
**Sécurité 360°** de l'ordinateur ; le KRI « Constats critiques ou élevés ouverts (scanner) » suit
leur nombre dans le temps.

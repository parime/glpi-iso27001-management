### Vérifier que les actifs importants envoient leurs journaux (mesures A.8.15, A.8.16 et A.8.17)

Le plugin compare la liste des hôtes connus de vos plateformes de journaux aux ordinateurs et
matériels réseau de GLPI. Il lit seulement : il ne lance rien sur les plateformes et ne modifie rien
dans GLPI. Il faut le droit du plugin et le droit natif de lecture des ordinateurs.

1. Ouvrez Sécurité technique > **Couverture de la journalisation**. L'encadré « À quoi sert cette
   page ? » rappelle ce qui est attendu.
2. Dans **Quels actifs doivent envoyer leurs journaux ?**, choisissez les actifs concernés (par
   défaut les actifs critiques, les serveurs et les matériels réseau), le nombre de jours sans
   événement au-delà duquel les journaux sont dits interrompus (7 par défaut) et la conservation
   exigée par votre politique (180 jours par défaut). Un actif est critique quand un axe de sa
   classification C/I/D est élevé ou qu'un flux réseau le publie sur Internet. Ces réglages se font
   dans Configuration > Plugins > GRC Manager, onglet **Parc** (bouton **Modifier dans la
   configuration** de la page).
3. Dans **Sources de journaux**, importez la liste des hôtes de chaque plateforme : par exemple, dans
   Splunk, la recherche `| metadata type=hosts index=*` exportée en CSV (colonnes `host`,
   `lastTime`) ; dans Graylog, Elastic ou OpenSearch, un tableau des sources avec leur dernier
   horodatage. Quand le plugin Wazuh est actif, ses agents actifs sont lus directement. Un nouvel
   import de la même plateforme remplace le précédent. Seuls le nom, l'adresse et la date du dernier
   événement sont gardés.
4. Dans **Conservation et synchronisation des horloges**, déclarez pour chaque plateforme la durée
   de conservation, l'obligation du registre qui impose une durée (et laquelle), et le serveur de
   temps. La conservation exigée est la plus longue entre la politique et l'obligation ; un badge
   signale une conservation trop courte ou non déclarée.
5. La liste **Actifs qui doivent envoyer leurs journaux** commence par ceux qui n'envoient rien,
   puis ceux dont les journaux sont interrompus, les actifs critiques d'abord. Les hôtes qui envoient
   des journaux mais que GLPI ne connaît pas sont listés à part : vérifiez leur nom ou ajoutez-les à
   l'inventaire.
6. Sur la fiche des mesures A.8.15, A.8.16 et A.8.17, le cadre « Couverture de la journalisation »
   montre le taux, les actifs critiques sans journalisation, les plateformes à la conservation
   insuffisante et la date du calcul. Les indicateurs de risque proposent la source « Actifs
   critiques sans journalisation (%) ».

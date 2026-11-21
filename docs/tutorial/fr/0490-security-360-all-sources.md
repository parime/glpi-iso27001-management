### Compléter la vue Sécurité 360° avec les autres modules

La vue Sécurité 360° (voir étape {{step:0460-security-360-view-of-an-asset}}) s'enrichit à mesure que les modules du plugin
sont utilisés : chaque source n'apparaît qu'une fois ses données importées.

1. **Classification** : classez l'actif (onglet **Classification C/I/D**). Un actif non classé apparaît
   « à surveiller », car sa criticité règle les délais de sauvegarde, de journaux et de correctifs.
2. **Correctifs**, **fin de support** et **garantie** : lus dans l'inventaire de GLPI et le cache
   de fin de support déjà rempli ; une fin de vie constructeur atteinte est critique.
3. **EDR** : avec le connecteur SentinelOne, l'onglet montre si un agent actif protège l'ordinateur
   et si des alertes attendent une vérification.
4. **Supervision**, **sauvegardes** et **journaux** : après une lecture Zabbix ou un import des
   outils de sauvegarde et de collecte, l'actif affiche son état et le lien vers la page du module.

Le score global tient compte de toutes les sources évaluées ; aucune n'est modifiée depuis l'onglet.

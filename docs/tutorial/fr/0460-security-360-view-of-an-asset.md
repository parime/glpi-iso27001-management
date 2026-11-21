### Voir la sécurité d'un actif d'un seul coup d'œil (vue Sécurité 360°)

Devant un auditeur qui choisit un serveur au hasard, l'onglet **Sécurité 360°** de la fiche de
l'ordinateur (ou du matériel réseau) rassemble ce que le plugin sait de cet actif.

1. Ouvrez la fiche d'un ordinateur, par exemple `SRV-DEMO-01`, puis l'onglet **Sécurité 360°**.
2. Le bandeau donne le niveau global (bon, à améliorer, faible) et un score sur 100 : moyenne
   pondérée des sources évaluées (conforme 100, à surveiller 50, critique 0). Une seule source
   critique empêche le niveau « bon ».
3. Chaque ligne résume une source avec son badge : vulnérabilités connues, chiffrement des disques,
   conformité de configuration (OpenSCAP), contrôleur de gestion, incidents de sécurité, risques
   liés. Le lien **Voir le détail** ouvre l'onglet ou la page du module pour corriger l'écart.
4. Une source sans donnée pour cet actif (module non utilisé, rien d'importé, pas le droit) n'est
   pas affichée : importez ou lisez d'abord les données des modules concernés.

Rien n'est modifié depuis cet onglet : il sert de point d'entrée, les corrections se font dans les
modules.

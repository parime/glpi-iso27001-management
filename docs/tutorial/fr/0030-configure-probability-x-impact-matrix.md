### Configurer la matrice probabilité x impact

Depuis **Configuration > Plugins**, l'icône en forme de clé sur la ligne du plugin ouvre l'écran
de configuration de la matrice de risque : chaque combinaison probabilité/impact peut être
associée au niveau de risque de son choix (faible/moyen/élevé/critique), avec un aperçu coloré
mis à jour en direct. Les valeurs par défaut reproduisent le comportement du registre avant que
cet écran n'existe : aucun risque existant ne change de niveau tant que la grille n'est pas
modifiée.

![Matrice de risque probabilité x impact](screenshots/tutorial/09-config-matrix.png)

**Taille de la matrice (4 x 4 ou 5 x 5).** La matrice est en 4 x 4 par défaut. Sous la grille,
« Taille de la matrice » permet de passer en 5 x 5 (très rare, rare, possible, probable, quasi
certaine ; négligeable, faible, moyen, élevé, critique), ou de revenir en 4 x 4. Le changement se
fait en deux étapes, sans rien modifier avant la confirmation :

1. **Correspondance des valeurs** : pour chaque probabilité et chaque impact utilisés aujourd'hui,
   le nombre de risques concernés et la nouvelle valeur proposée (celle qui change le moins de
   niveaux de risque), modifiable ;
2. **Vérifier l'effet et appliquer** : nombre de risques mis à jour, niveaux qui montent ou
   baissent, grille de la nouvelle taille ; une case à cocher confirme la vérification.

Les risques et les risques fournisseurs sont alors convertis ; l'historique de chaque risque garde
l'ancienne et la nouvelle valeur, avec un message qui explique la conversion. Le niveau de risque
reste sur quatre valeurs (faible, moyen, élevé, critique) : l'appétence, les indicateurs et les
contrôles de cohérence ne changent pas. Ce changement demande le droit de modifier la
configuration du plugin et les registres sensibles. Les échelles des études EBIOS RM et des AIPD
ne sont pas concernées.

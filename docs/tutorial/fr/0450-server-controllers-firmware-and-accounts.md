### Suivre le firmware des cartes de gestion et revoir leurs comptes (mesures A.8.8 et A.8.2)

Une fois les contrôleurs lus ({{step:0440-review-server-management-controllers}}), leur firmware
et le BIOS du serveur sont suivis comme les autres firmwares du parc, et leurs comptes locaux entrent
dans la revue des accès.

1. Sur la page **Contrôleurs de gestion des serveurs**, la colonne **Firmware et BIOS** affiche pour
   chacun un badge : **Non surveillé** tant que son nom n'est relié à aucun produit, sinon le nombre
   de vulnérabilités connues (en rouge si l'une est prioritaire) et, si la vérification des fins de
   support est activée, son état (supporté, fin proche, plus supporté).
2. Pour surveiller un firmware, ouvrez **Vulnérabilités du parc** : son nom (par exemple
   `HPE iLO 5` ou `HPE ProLiant DL380 Gen10 BIOS`) apparaît dans les logiciels à relier, comme un
   firmware de GLPI. Reliez-le une fois à son produit ; la veille planifiée cherche ensuite les
   vulnérabilités de chaque version lue.
3. Pour la fin de support, reliez le même nom à un produit d'endoflife.date dans la configuration de
   la vérification des fins de support, si un produit existe pour ce matériel.
4. Les écarts **Firmware avec une vulnérabilité prioritaire**, **Firmware avec des vulnérabilités
   connues**, **Firmware plus supporté** et **Fin de support proche** s'ajoutent aux autres écarts
   du contrôleur et comptent dans l'indicateur des écarts graves.
5. Lancez une campagne de revue des accès : les comptes lus sur les contrôleurs des mêmes entités
   sont copiés dans l'onglet **Comptes des contrôleurs de serveurs** de la campagne, avec une
   suggestion. Un compte livré par le constructeur encore actif ou un compte désactivé est proposé
   au retrait, un compte administrateur ou verrouillé est à examiner.
6. Pour chaque compte, cliquez sur **Accès validés** ou **À retirer**, avec un commentaire ; garder
   un compte proposé au retrait demande une justification. Supprimez ensuite le compte sur le
   contrôleur lui-même : le plugin ne change rien sur les serveurs, la campagne garde la trace de la
   décision. Pour une campagne lancée avant la lecture des contrôleurs, le bouton **Ajouter les
   comptes des contrôleurs** de l'onglet les copie une fois.

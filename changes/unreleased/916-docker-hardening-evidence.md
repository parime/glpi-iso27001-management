### Added

- **Configuration à risque de l'inventaire Docker sur la mesure A.8.9** (issue #916). Dans le cadre
  « Conformité de configuration » de la mesure, une ligne **Inventaire Docker : configuration à
  risque** apparaît quand le plugin Inventaire Docker 0.12.0 ou suivant est actif et que
  l'utilisateur peut lire ses services : taux de services **sans écart critique ni haut** mis en
  avant, taux sans aucun écart, nombre de services analysés et non analysés, services dont l'écart
  le plus grave est critique ou haut, exceptions **expirées** et exceptions qui finissent dans les
  30 jours (avec la date de la plus proche), et lien vers la sous-page « Configuration à risque ».
  Les exceptions ne sont comptées que sur les services des entités actives. Rien ne s'affiche sans
  le plugin ou avec une version plus ancienne. Lecture seule.

### Changed

- **Parc > Sécurité du parc** (#1039, navigation simplifiée #1035) : les écrans de sécurité technique
  quittent le menu GRC pour le menu Parc, là où l'on cherche la sécurité d'un actif. Une page
  d'accueil range en cartes les 21 écrans, en six groupes : Vulnérabilités, Fin de vie et correctifs,
  Durcissement, Supervision, Sauvegardes et réseau, Sites et développement (sécurité physique,
  développement sécurisé et sécurité du code, venus de la rubrique des mesures). La barre d'onglets
  montre l'accueil et un onglet par groupe ; une seconde barre montre les pages du groupe ouvert. Le
  fil d'Ariane suit : « Parc > Sécurité du parc > … ». Les adresses des pages ne changent pas ; le
  nouveau menu apparaît à la connexion suivante.
- **Fin de vie constructeur des modèles de matériel** (#1039) : ce n'est plus un réglage de la
  configuration, mais une donnée du parc. Elle se saisit dans un nouvel onglet « Fin de vie
  constructeur » des fiches de modèles de GLPI (ordinateurs, écrans, matériels réseau, imprimantes,
  téléphones, périphériques…), ou pour tous les modèles en service dans Parc > Sécurité du parc >
  Fin de vie des matériels. La configuration garde le seuil d'alerte, commun avec la fin de support ;
  l'ancien lien et l'ancien formulaire de la configuration mènent à la nouvelle page.
- **Actions « … maintenant » hors de la configuration** (#1039) : « Mettre à jour maintenant » et les
  correspondances avec les produits d'endoflife.date sont sur « Logiciels à surveiller » ; « Envoyer
  le rapport maintenant » est sur « Vulnérabilités du parc ». La configuration ne garde que les
  réglages (activation, seuil, fréquence et destinataires) ; les anciens envois restent acceptés
  pendant une version et mènent au nouvel écran. Les droits ne changent pas.

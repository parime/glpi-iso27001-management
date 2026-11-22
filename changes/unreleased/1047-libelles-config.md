### Changed

- **Rubrique « Non-conformités et amélioration »** (#1047, navigation simplifiée #1035) : la rubrique
  « Amélioration continue » du menu GRC change de nom, pour qu'on sache sans connaître la norme
  qu'on y trouve les non-conformités (avec le stock des écarts, les objectifs et les revues de
  direction). Les adresses des écrans et les droits ne changent pas.
- **Création et import des référentiels personnalisés dans la configuration** (#1047) : définir un
  référentiel est un réglage. Le modèle de fichier, l'analyse d'un fichier, l'import confirmé, la
  nouvelle version et la liste des référentiels importés sont désormais dans Configuration >
  Plugins > GRC Manager, onglet Référentiels, juste après la famille « Référentiels
  personnalisés ». L'évaluation reste dans GRC & Conformité > Réglementations : la page
  d'évaluation et l'auto-évaluation des exigences d'un référentiel importé (rangée avec elle),
  avec un lien vers la configuration pour les profils qui peuvent créer ou modifier un
  référentiel. L'ancienne adresse `front/customframework.php` mène au nouveau bloc ; l'ancien envoi
  de son formulaire reste accepté pendant une version. Les droits ne changent pas.

### Fixed

- **Fil d'Ariane de la configuration** (#1047) : il affichait « Configuration > Plugins » deux fois
  (celui de l'en-tête de GLPI, puis le sien) ; il se lit maintenant « Configuration > Plugins >
  GRC Manager > <onglet> ».

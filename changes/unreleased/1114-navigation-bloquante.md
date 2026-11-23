### Changed

- **Contrôle de navigation et parcours de toutes les pages bloquants en CI** (issue #1114). La
  refonte de la navigation (#1035) étant terminée, le contrôle d'ergonomie (réglages, menus,
  en-têtes) et le parcours de toutes les pages (fil d'Ariane, onglets, accessibilité, pages
  refusées) ne sont plus de simples avertissements : une régression arrête la CI. Les écarts qui
  viennent de composants du cœur de GLPI sont listés, avec leur raison, dans
  `.glpi-security-exceptions.d/1114-parcours-pages`.
- **Configuration en sept onglets** (issue #1114). Les réglages des incidents de sécurité et des
  plans de continuité rejoignent l'onglet « Risques et incidents » ; l'ancienne adresse
  `config.php?tab=incidents` ouvre ce nouvel onglet.

### Fixed

- **Avertissements lisibles** (issue #1114). Les messages d'avertissement écrits en jaune (contrôles
  d'une violation de données, recette d'une mise en production, matrice des responsabilités,
  acceptation d'un risque, appétence, import de risques, atelier 5 EBIOS, développement sécurisé,
  applications approuvées, résumé de contexte) utilisent la variante foncée du jaune : le contraste
  avec le fond atteint le niveau AA.
- **Barres d'onglets sur une seule ligne et lisibles** (issue #1114). Les barres des rubriques et
  de la configuration ne passent plus à la ligne (onglets plus serrés, défilement horizontal si
  besoin) ; leurs libellés gardent la couleur du texte quelle que soit la palette de GLPI.
- **Chiffres clés du tableau de bord lisibles** (issue #1114) : couleurs des cartes choisies pour
  un contraste d'au moins 4,5:1 avec le texte que GLPI y écrit.
- **Liens reconnaissables sans la couleur** (issue #1114) : les liens placés dans un texte des
  écrans du plugin sont soulignés.
- **Pages sans écran propre** (issue #1114). Les points d'envoi des formulaires des onglets, le
  changement de taille de la matrice des risques, le dossier de revue de direction, les comptes des
  contrôleurs de serveurs et les réponses fournisseur hors ligne, ouverts directement, répondent
  « requête invalide » au lieu de renvoyer vers l'accueil ou un autre écran.
- **Écrans atteignables par le menu** (issue #1114) : la liste des événements de conformité
  s'ouvre depuis le calendrier, les nomenclatures SBOM depuis le développement sécurisé.

### Fixed

- **Accessibilité, premier lot commun** (#663, niveau AA du RGAA 4.1 et des WCAG 2.2) :
  - les badges de couleur pâle (`bg-*-lt`), présents dans la plupart des listes et des fiches,
    atteignent un contraste d'au moins 7:1 au lieu d'environ 3:1, en thème clair comme sombre ;
  - le titre de l'encadré « À quoi sert cette page ? » reste lisible en thème sombre, avec un
    focus visible au clavier ;
  - les barres de progression (référentiels, SoA, évaluation de conformité, playbooks) ont un nom
    lu par les lecteurs d'écran ; les badges qui n'affichent qu'une icône (programme d'audit,
    sécurité physique) sont annoncés comme des images avec leur libellé ;
  - les colonnes de boutons des tableaux ont un en-tête lu par les lecteurs d'écran (« Actions »,
    « Sélection ») ;
  - le champ d'import du CSV de la SoA a un libellé et son aide lui est reliée ;
  - les onglets de la synthèse par attribut de la SoA sont reliés à leur panneau et se parcourent
    aux flèches, avec un focus visible.

### Added

- Test d'accessibilité de bout en bout (#663) : axe-core sur quinze pages clés, en thème clair et
  sombre ; la CI échoue si une violation grave d'une règle déjà corrigée revient.

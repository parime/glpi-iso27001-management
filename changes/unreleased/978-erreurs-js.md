### Fixed

- **Erreurs JavaScript surveillées dans un vrai navigateur** (issue #978). Un nouveau contrôle de la
  CI (`tests/security/js-errors.py`, Playwright) ouvre en super-admin l'accueil de GLPI et chaque page
  du plugin, après le jeu de données piégé du test XSS, et échoue si une page lève une erreur
  JavaScript non interceptée (par exemple « Maximum call stack size exceeded ») ou ouvre une boîte de
  dialogue (charge XSS exécutée). Une erreur qui ne revient pas à une seconde visite de la page est
  signalée en avertissement seulement. L'erreur relevée une fois avant publication n'a pas été
  reproduite sur la version de développement : ni boucle, ni requête relancée sans fin sur l'accueil
  et les pages du plugin.

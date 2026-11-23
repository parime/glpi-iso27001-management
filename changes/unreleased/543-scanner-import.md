### Added

- **Rapports des scanners de vulnérabilités** (issue #543, mesure A.8.8) : nouvelle page
  « Rapports des scanners de vulnérabilités » dans Parc > Sécurité du parc, groupe Vulnérabilités :
  - import du rapport XML de Greenbone Community Edition / OpenVAS (format « XML » de l'interface ou
    réponse get_reports du protocole GMP), lu en flux, sans réseau, DTD ni entité ; le fichier
    n'est pas conservé, et un rapport d'exemple fictif est téléchargeable ;
  - chaque constat (test, gravité CVSS, port, CVE, solution, qualité de détection) est rattaché à
    l'ordinateur GLPI visible du même nom, nom court ou adresse IP ; les hôtes non rapprochés sont
    listés et se rattachent à la main, choix repris aux imports suivants ;
  - un constat est unique par outil, hôte, port et test : rejouer un rapport ne crée aucun doublon,
    et un constat absent d'une analyse plus récente du même hôte passe « corrigé » ;
  - historique par import (constats par gravité, évolution, corrigés depuis l'analyse précédente),
    filtres par statut, gravité et ordinateur ;
  - ticket de remédiation lié à l'ordinateur, proposé et créé seulement sur un clic ;
  - preuve sur la fiche de la mesure A.8.8, source « Constats du scanner réseau » de l'onglet
    Sécurité 360° de l'ordinateur, KRI « Constats critiques ou élevés ouverts (scanner) ».
  Cadre commun des formats (Services\Scanner) prêt pour d'autres outils ; les formats du commerce
  (Nessus, Qualys, Rapid7) restent à l'issue #841.

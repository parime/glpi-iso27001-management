### Added

- **Nomenclature logicielle (SBOM) et déclarations VEX des produits fabriqués** (issue #869, mesures
  A.5.21, A.8.8 et A.8.28, Cyber Resilience Act annexe I partie II). Un onglet **SBOM et VEX** sur les
  applications importe, pour chaque version publiée, une nomenclature CycloneDX ou SPDX 2 en JSON,
  avec une lecture bornée : 10 Mo, profondeur du JSON, 10 000 composants et 5 000 vulnérabilités au
  plus, champs nettoyés, fichier refusé plutôt que lu en partie. Les composants (nom, version,
  fournisseur, licences, purl, CPE) sont rapprochés des vulnérabilités citées par le fichier et de
  celles que la veille des vulnérabilités du parc connaît déjà pour le même produit et la même version
  (alias d'un produit surveillé ou CPE du composant), sans aucun appel extérieur, avec la note CVSS et
  le marquage KEV. Chaque vulnérabilité reçoit un statut VEX (affecté, en cours d'analyse, corrigé,
  non affecté avec une justification ou une explication), saisi dans l'onglet ou importé d'un
  document CycloneDX VEX ou OpenVEX ; la déclaration la plus précise s'applique. Les fiches des mesures
  A.5.21, A.8.8 et A.8.28 montrent les produits avec un SBOM, les applications du registre du
  développement sécurisé sans SBOM et les vulnérabilités sans déclaration ou encore affectées.

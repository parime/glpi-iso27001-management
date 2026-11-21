### Tenir la nomenclature logicielle (SBOM) et les déclarations VEX de vos produits (mesures A.5.21, A.8.8 et A.8.28)

Si vous fabriquez ou développez un logiciel (application, produit avec des éléments numériques au
sens du Cyber Resilience Act), vos clients et le CRA attendent sa nomenclature logicielle et vos
déclarations sur ses vulnérabilités. Le produit est une application de GLPI, comme dans le registre
du développement sécurisé ({{step:0320-frame-secure-development-releases}}).

1. Faites produire la nomenclature par votre chaîne de construction, au format CycloneDX ou SPDX 2 en
   JSON (par exemple avec Syft, Trivy, cdxgen ou le greffon CycloneDX de Maven ou de npm).
2. Ouvrez l'application dans **Parc > Applications**, onglet **SBOM et VEX**. Dans **Importer un
   SBOM**, saisissez la version publiée (par exemple `2.4.0`), choisissez le fichier et cliquez sur
   **Importer**. Le fichier est refusé s'il dépasse 10 Mo, s'il est trop imbriqué ou s'il liste plus
   de 10 000 composants : découpez-le alors par produit.
3. La carte de la version liste les **vulnérabilités connues des composants** : celles que cite le
   fichier (CycloneDX, souvent ajoutées par l'outil d'analyse) et celles que la veille des
   vulnérabilités du parc connaît déjà pour le même produit et la même version (nom relié à un
   produit surveillé, ou CPE du composant). Aucune requête n'est envoyée hors de GLPI. Dépliez
   **Composants** pour voir le détail : nom, version, fournisseur, licences, purl ou CPE.
4. Pour chaque vulnérabilité, choisissez un statut VEX : **Affecté**, **En cours d'analyse**,
   **Corrigé** ou **Non affecté**. « Non affecté » demande une justification (composant absent, code
   vulnérable absent ou jamais exécuté, hors de portée d'un attaquant, protections déjà en place) ou
   une explication. Vous pouvez aussi importer le document VEX de votre outil (CycloneDX VEX ou
   OpenVEX) dans **Importer un document VEX**, ou ajouter une déclaration pour le produit entier dans
   **Déclarations VEX**.
5. Une nouvelle déclaration remplace la précédente pour la même vulnérabilité, le même composant et
   la même version ; la plus précise s'applique (composant nommé plutôt que produit entier).
6. Sur la fiche des mesures A.5.21, A.8.8 et A.8.28, l'encadré **Nomenclatures logicielles et VEX**
   donne le nombre de produits avec un SBOM, les applications du registre sans SBOM et, pour la
   dernière nomenclature de chaque produit, les vulnérabilités sans déclaration et celles qui
   affectent encore le produit.

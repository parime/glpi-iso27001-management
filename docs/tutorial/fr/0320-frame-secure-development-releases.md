### Encadrer le développement sécurisé et les mises en production (mesures A.8.25 à A.8.31)

Le plugin s'appuie sur les **applications** de GLPI (Parc > Applications) et sur les
**changements** (Assistance > Changements) : il n'en crée pas d'autres. Il faut le droit général du
plugin ; pour valider une mise en production, le droit **Décider des mises en production** (onglet
GRC Manager du profil, attribué au Super-Admin à l'installation).

1. Ouvrez une application fictive développée en interne, par exemple « Portail RH », puis l'onglet
   **Développement sécurisé**. Renseignez le responsable (vide : le responsable technique de
   l'application), le dépôt de code (adresse en https://, ssh:// ou git@), le **référentiel suivi**
   (OWASP ASVS, OWASP Top 10, OWASP SAMM, recommandations de l'ANSSI ou autre) et son niveau, puis
   cochez **Revue de code obligatoire**, **Analyse statique (SAST)**, **Analyse dynamique (DAST)**
   et **Analyse des dépendances**. Indiquez les outils et leur fréquence.
2. Répondez à **Environnements séparés** (oui ou non). Si oui, cochez ce qui est réellement séparé
   entre développement, test et production : **accès**, **données** (pas de données de production
   hors production, ou anonymisées) et **configuration** ; une case manquante signale une séparation
   partielle. Si la réponse est non, la justification est obligatoire (risque accepté, mesure
   compensatoire).
   Décrivez ensuite les **exigences de sécurité** de l'application (A.8.26) et les **principes
   d'architecture sécurisée** appliqués (A.8.27), ou mettez un lien vers le cahier des charges et le
   document d'architecture. Si le développement est confié à un prestataire (A.8.30), cochez-le,
   choisissez le fournisseur de GLPI, cochez **Clauses de sécurité au contrat** et décrivez le suivi
   du prestataire (revue des livraisons, recette, droit d'audit). Enregistrez : l'application entre
   dans le registre.
3. Créez le changement de la nouvelle version et liez-y l'application (onglet **Éléments** du
   changement). Un onglet **Validation sécurité de mise en production** apparaît.
4. Avant la mise en production, cochez la **liste de contrôle** : tests passés (dont tests de
   sécurité), revue de code faite, vulnérabilités trouvées traitées, et notez les preuves (rapport
   SAST, lien de la revue). Enregistrez.
5. La DSI ou le RSSI choisit son rôle et clique sur **Valider la mise en production** (possible
   seulement quand toute la liste est cochée) ou **Refuser la mise en production** (motif
   obligatoire). La décision est enregistrée avec son auteur et sa date, et inscrite dans
   l'historique du changement ; la liste est alors figée. **Rouvrir la décision** la rend de nouveau
   modifiable, et l'historique garde la décision précédente.
6. Ouvrez Conformité > **Développement sécurisé**. La page donne le registre des applications, leurs
   pratiques, les **applications sans séparation des environnements** et les **mises en production
   sans validation sécurité** des 12 derniers mois (changement résolu ou clos lié à une application
   du registre, sans validation, refusé, ou validé après la mise en production). Seules vos entités
   apparaissent ; les mises en production demandent le droit de voir tous les changements.
7. Sur la fiche des mesures A.8.25 à A.8.31 de la SoA, le cadre **Développement sécurisé** montre
   les indicateurs utiles à chaque mesure : exigences décrites (A.8.26), principes d'architecture
   (A.8.27), applications externalisées sans prestataire ou sans clauses (A.8.30), séparation
   partielle (A.8.31). Ces preuves servent à lever les constats d'audit sur le cycle de
   développement, le codage sécurisé, les tests de sécurité et la séparation des environnements.

### Où est passé… ? Retrouver un écran après la nouvelle navigation

La navigation du plugin a été réorganisée pour qu'on trouve chaque écran là où on le cherche
naturellement : les réglages dans la page de configuration du plugin, l'exploitation dans le menu
de GLPI qui lui correspond (Assistance, Parc, Gestion) ou dans une rubrique au nom parlant du menu
**GRC & Conformité**. Les adresses des écrans et les droits ne changent pas : vos favoris et vos
liens fonctionnent toujours, et le fil d'Ariane en haut de chaque page indique le nouvel
emplacement.

**Déconnectez-vous puis reconnectez-vous** une fois : GLPI garde le menu en mémoire pendant la
session.

#### Écrans d'exploitation

| Écran | Ancien emplacement | Nouvel emplacement |
|---|---|---|
| Évaluation par référentiel | GRC > Tableau de bord | GRC > Réglementations |
| Score de maturité, maturité par mesure, calendrier de conformité, contrôles de cohérence, préparation à la certification | GRC > Tableau de bord | GRC > Tableau de bord (inchangé) |
| Points de défaillance uniques (analyses d'impact), effet domino, études EBIOS RM, indicateurs de risque (KRI) | GRC > Risques | GRC > Analyses de risques |
| Risques fournisseurs | GRC > Risques | Gestion > Sécurité des fournisseurs (et onglet **Sécurité** de la fiche du fournisseur) |
| Modèles de questionnaire fournisseur | lien de la page des risques fournisseurs | Gestion > Sécurité des fournisseurs |
| Registre d'informations DORA | GRC > Conformité | Gestion > Sécurité des fournisseurs, si DORA est activé |
| Cartographie des risques, catalogue de risques types | hors menu (liens de la liste des risques) | GRC > Risques, onglets |
| Import et export des risques | Outils | GRC > Risques, onglet **Import et export** |
| Applications approuvées | GRC > Risques | GRC > Politiques et procédures |
| Cadrage de la SoA, mesures bloquées, tests d'efficacité | GRC > Conformité | GRC > Mesures de sécurité (SoA) |
| Référentiels | GRC > Conformité | GRC > Réglementations (activation : Configuration, onglet Référentiels) |
| Mesures ReCyF, exigences NIS2 Italie (ACN) | hors menu (lien de la configuration) | GRC > Réglementations, si le référentiel est activé |
| Exigences SMSI, enjeux et parties intéressées, matrice des responsabilités, contacts officiels, obligations | GRC > Conformité | GRC > Organisation du SMSI |
| Décisions déléguées | Outils | GRC > Organisation du SMSI |
| Registre des traitements, demandes d'exercice des droits, registre des violations | GRC > Conformité | GRC > Vie privée (RGPD) |
| Politiques, modèles de politiques, procédures d'exploitation, couverture des mesures par les politiques | GRC > Conformité | GRC > Politiques et procédures |
| Preuves, dossier de preuves, couverture des preuves | GRC > Conformité | GRC > Audits et preuves |
| Audits internes, programme d'audit, constats des audits précédents, plan d'audit de l'organisme | GRC > Audits et amélioration | GRC > Audits et preuves |
| Non-conformités, stock des écarts, objectifs, revues de direction | GRC > Audits et amélioration | GRC > Non-conformités et amélioration |
| Revues des accès, revues périodiques, hygiène des comptes, couverture MFA, droits sur les partages | GRC > Opérations | GRC > Accès et comptes |
| Conformité sécurité du personnel, formations, simulations d'hameçonnage, compétences | GRC > Opérations | GRC > Personnel et sensibilisation |
| Expositions à des fuites de données | GRC > Sécurité technique | GRC > Personnel et sensibilisation |
| Audit de sécurité de l'Active Directory | GRC > Sécurité technique | GRC > Accès et comptes |
| Vulnérabilités du parc, machines les plus exposées, produits, logiciels à surveiller, licences, correctifs, contrôles techniques, conformité de configuration, chiffrement, journalisation, Zabbix, Uptime Kuma, contrôleurs de serveurs, sauvegardes, messagerie et domaines, matrice des flux | GRC > Sécurité technique | Parc > Sécurité du parc |
| Sécurité physique, développement sécurisé, sécurité du code | GRC > Conformité | Parc > Sécurité du parc, groupe **Sites et développement** |
| Fin de vie constructeur des modèles | Configuration du plugin | onglet **Fin de vie constructeur** de la fiche du modèle, ou Parc > Sécurité du parc > Fin de vie des matériels |
| Incidents de sécurité | Assistance, après Changements récurrents | Assistance, juste après Changements |
| Procédures de remédiation, import des alertes EDR, journal d'audit de sécurité | liens de la liste des incidents | Assistance > Incidents de sécurité, onglets |
| Processus métier (BIA), scénarios de crise, exercices de crise, programme des tests de reprise | GRC > Opérations | Assistance > Crise et continuité |
| Modèles de messages de crise | lien de la liste des incidents | Assistance > Crise et continuité |

#### Réglages et boutons d'action

| Réglage ou bouton | Ancien emplacement | Nouvel emplacement |
|---|---|---|
| Page de configuration du plugin | Outils > GRC Manager (une seule longue page) | Configuration > Plugins > GRC Manager, en huit onglets |
| Changement de taille de la matrice des risques | Outils | Configuration > Plugins > GRC Manager, onglet Risques |
| Création et import de référentiels personnalisés | GRC > Conformité > Référentiels personnalisés | Configuration, onglet **Référentiels** |
| Connexion à Zabbix, Uptime Kuma, Veeam, serveur DNS de la messagerie | page de chaque écran | Configuration, onglet **Connecteurs** |
| Connexion à Microsoft Entra ID (Graph), GitHub et GitLab | page de chaque écran | Configuration, onglet **Connecteurs** |
| Groupes d'accès distant de la couverture MFA | Couverture MFA | Configuration, onglet **Personnes et politiques** |
| Ordinateurs à chiffrer, délais des correctifs, éditeurs de logiciels suivis, journalisation attendue, actifs à sauvegarder | page de chaque écran | Configuration, onglet **Parc** |
| Programme d'audit, préparation à la certification, taille du dossier de preuves | page de chaque écran | Configuration, onglet **Mesures, audits et preuves** |
| Entité qui tient le registre DORA | Registre DORA | Configuration, onglet **Référentiels** (si DORA est activé) |
| Réponse des fournisseurs par lien sécurisé | Configuration (page unique) | Configuration, onglet **Général** |
| Décisions délégables pendant une absence | Configuration (page unique) | Configuration, onglet **Personnes et politiques** |
| **Mettre à jour maintenant** (endoflife.date) et correspondances de produits | Configuration du plugin | Parc > Sécurité du parc > Logiciels à surveiller |
| **Envoyer le rapport maintenant** (vulnérabilités) | Configuration du plugin | Parc > Sécurité du parc > Vulnérabilités du parc |
| **Lire maintenant** (SentinelOne) | Configuration du plugin | Assistance > Incidents de sécurité > Import des alertes EDR |

Un ancien lien vers un bloc de la configuration (`config.php#…`) ouvre le bon onglet, ou l'écran
qui accueille désormais l'action. Un ancien formulaire de réglage envoyé depuis une page
d'exploitation reste accepté pendant une version : il enregistre, puis vous amène sur l'onglet de
la configuration avec un message.

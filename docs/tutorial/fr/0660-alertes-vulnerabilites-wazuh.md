### Transformer les alertes Wazuh en incidents et suivre les vulnérabilités critiques (mesures A.8.8, A.5.24 à A.5.26)

Quand le plugin GLPI Wazuh est installé et actif, GRC lit ses alertes et ses vulnérabilités, sans
jamais rien y modifier. Sans ce plugin, rien de ce qui suit n'apparaît.

1. Ouvrez Assistance > Incidents de sécurité, onglet **Import des alertes EDR** (droit de créer un
   incident). Le cadre **Alertes Wazuh à revoir** liste les alertes des 30 derniers jours sur les
   ordinateurs que vous voyez. Les alertes répétées (même règle sur le même ordinateur) forment un
   seul groupe, avec leur nombre, le niveau de la règle, l'agent, la dernière alerte et un lien vers
   les alertes de l'ordinateur dans le plugin Wazuh.
2. Le filtre **Niveau minimal de la règle** montre par défaut les niveaux 7 et plus ; choisissez
   **Niveau 12 et plus** pour ne garder que les plus graves, ou **Tous les niveaux**.
3. Cochez les groupes qui méritent un incident, puis **Créer les incidents des groupes choisis**.
   Chaque groupe devient un incident de sécurité (origine SIEM) avec l'ordinateur, la gravité tirée
   du niveau de la règle (12 et plus : critique, 7 à 11 : majeure), les horodatages, les techniques
   MITRE ATT&CK de la règle et le lien vers les alertes. Une alerte n'est jamais importée deux fois.
4. Quand la même alerte revient alors que l'incident est encore ouvert, le groupe porte le badge
   **Déjà dans un incident : nouvelles répétitions** ; l'importer ajoute une tâche à cet incident au
   lieu d'en ouvrir un nouveau. Une fois l'incident résolu ou clos, une nouvelle répétition ouvre un
   nouvel incident.
5. Dans Parc > Sécurité du parc, le cadre **Détectées par Wazuh seulement** nomme les ordinateurs
   touchés par chaque vulnérabilité et indique en tête les **ordinateurs avec des vulnérabilités
   critiques non traitées** : CVSS de 9 ou plus, toujours signalées par Wazuh et sans dérogation
   validée en cours. Une dérogation acceptée retire la CVE du décompte.
6. Le même indicateur existe en carte du tableau de bord (« Ordinateurs avec des vulnérabilités
   critiques non traitées (Wazuh) ») et en source d'indicateur de risque (KRI), pour fixer un seuil
   d'alerte.

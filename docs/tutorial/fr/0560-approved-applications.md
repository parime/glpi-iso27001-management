### Dire quelles applications et quels services en ligne sont autorisés (mesure A.5.23)

Le registre des risques fournisseurs évalue un éditeur dans son ensemble ; la mesure A.5.23 demande
en plus de savoir quels services en ligne sont utilisés, pour quoi faire et avec quelles données.
Le plugin enregistre cette décision sur les applications de GLPI, celles déjà utilisées par le
développement sécurisé : aucune application n'est saisie deux fois.

1. Ouvrez une application de GLPI (**Parc > Applications**, ou créez-la), par exemple « Outil de
   partage de fichiers en ligne », puis son onglet **Usage autorisé**.
2. Choisissez le **statut** : *En évaluation* tant que l'équipe sécurité l'étudie, *Approuvée*,
   *Approuvée avec restrictions* ou *Refusée*. Renseignez le fournisseur et le logiciel de GLPI
   correspondants, le responsable, les services utilisateurs et l'hébergement (en ligne ou sur site).
3. Décrivez les **cas d'usage autorisés** (« partage de fichiers avec les clients et partenaires »),
   les **restrictions** (« aucune donnée personnelle de client, liens limités à 30 jours ») et les
   **données les plus sensibles autorisées** (« Faible : données publiques ou internes seulement »).
   Un badge rouge prévient si la classification C/I/D de l'application dépasse ce niveau. Ajoutez
   les dérogations accordées, issues du registre des dérogations.
4. Gardez la **revue tous les 12 mois** (0 pour aucune revue). Quand elle est échue, la tâche
   automatique « approvedappreview » ouvre un ticket de réévaluation pour le responsable ; après la
   revue, cochez **Revue faite aujourd'hui** et enregistrez.

La page **Risques > Applications approuvées** donne le registre complet avec ses badges, les revues
en retard et les fiches à compléter, puis le **rapprochement avec l'inventaire logiciel** : logiciels
installés qui correspondent à une application refusée ou encore en évaluation, et logiciels absents
du registre (filtrez par catégorie de logiciels pour réduire la liste). Rien n'est désinstallé ni
modifié : ouvrez un ticket s'il faut agir.

Chaque utilisateur, même sans droit sur le plugin, retrouve la liste dans **Mes préférences**, onglet
**Applications autorisées** : ce qu'il peut utiliser, pour quoi faire, avec quelles données, et les
applications à ne pas utiliser. La fiche de la mesure A.5.23 reprend ces chiffres comme preuve.

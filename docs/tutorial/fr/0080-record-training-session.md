### Suivre une formation et la réalisation par participant

Depuis **GRC & Conformité > Opérations > Formations**, la liste affiche les sessions déjà enregistrées.

![Liste des formations](screenshots/tutorial/34-training-list.png)

Une formation se définit par un titre, un format (présentiel/e-learning/autre), une date de
réalisation, un public cible, un caractère obligatoire ou non et, le cas échéant, une périodicité
de renouvellement en mois (clauses 7.2 « compétence » et 7.3 « sensibilisation »). Les
participants, de vrais utilisateurs GLPI, sont sélectionnés dans le même formulaire.

![Formulaire de création d'une formation avec participants sélectionnés](screenshots/tutorial/35-training-form-filled.png)

Une formation donnée plusieurs fois a plusieurs **sessions** : depuis l'onglet **Sessions** de la
formation, « Ajouter une session » ouvre un formulaire prérempli (titre, format, note maximale,
seuil) où l'on renseigne la date, le formateur, le lieu ou le lien et les participants. Chaque
session garde ses propres documents (feuille d'émargement, support, quiz) ; l'onglet liste les
sessions avec leur taux de réussite, et le taux de réussite de la formation, toutes sessions.

L'onglet **Copies de quiz** d'une session reçoit la copie corrigée de chaque participant. Une copie
est une donnée personnelle : seuls le participant, le formateur de la session et le groupe RSSI la
lisent, et chaque téléchargement est historisé. Le participant retrouve ses formations, ses notes et
ses copies dans l'onglet **Mes formations** de ses préférences, sans droit sur le plugin.

Une fois enregistrée, un tableau de suivi individuel apparaît sous le formulaire : un statut de
réalisation (en attente/terminée/dispensé) et une date par participant.

![Formation enregistrée avec le tableau de suivi des participants](screenshots/tutorial/36-training-saved-participants.png)

Faire passer un participant au statut « Terminée » avec sa date de réalisation, puis enregistrer :
son suivi individuel est à jour, et la formation compte désormais dans le taux de réalisation
affiché au tableau de bord (voir étape {{step:0100-browse-default-isms-dashboard}}).

![Statut de réalisation d'un participant mis à jour](screenshots/tutorial/37-training-completion-recorded.png)

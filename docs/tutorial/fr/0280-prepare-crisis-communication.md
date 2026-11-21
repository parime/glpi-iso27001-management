### Préparer la communication de crise (mesure A.5.26, article 7.4)

1. Dans l'en-tête de la liste des incidents de sécurité, cliquez sur **Modèles de messages de
   crise**. Cinq modèles sont fournis : incident majeur (premier message interne et fin de crise),
   violation de données (information des personnes concernées), indisponibilité (message aux
   utilisateurs et aux clients) et rançongiciel (consignes au personnel). Adaptez-les à votre
   organisation : objet, texte, situation, étape et public.
2. Les **variables** s'écrivent entre accolades, par exemple `{reference}`, `{title}`,
   `{detection_date}`, `{affected_systems}` ou `{next_update}` : la fiche du modèle en donne la
   liste. Elles sont remplacées par les valeurs de l'incident quand le message est préparé ; une
   variable sans valeur devient « [à compléter] ».
3. Dans l'onglet **Liste de diffusion** du modèle, ajoutez les destinataires internes (utilisateurs
   et groupes GLPI) et externes. Les destinataires externes sont les **contacts officiels** de
   l'étape {{step:0220-keep-register-official-contacts}} (assureur, autorité, prestataire...) : enregistrez d'abord un destinataire absent dans
   le registre des contacts officiels. Un contact sans coordonnées, un utilisateur sans adresse
   électronique ou un groupe sans membre est signalé « Aucune adresse enregistrée ».
4. Pendant une crise, ouvrez l'incident (par exemple « Chiffrement du serveur de fichiers
   (fictif) »), onglet **Communication de crise**. Le modèle conseillé pour la situation de
   l'incident est proposé en premier ; l'objet et le texte sont déjà remplis. Relisez, remplacez
   chaque « [à compléter] », décochez les destinataires à ne pas prévenir, ajoutez au besoin d'autres
   destinataires (un par ligne).
5. Cliquez sur **Copier le message** et **Copier les adresses électroniques**, ou sur **Exporter
   (fichier .eml)** pour ouvrir un brouillon dans votre messagerie. **Le plugin n'envoie aucun
   courriel** : vous envoyez le message vous-même.
6. Une fois le message envoyé, cliquez sur **Tracer comme envoyé** (la date se corrige si
   l'envoi a eu lieu plus tôt). La communication s'ajoute à la liste « Communications faites » avec
   la date, l'auteur, les destinataires, l'objet et le texte ; elle est aussi écrite dans le journal
   de crise de l'incident (suivi privé, sans notification) et reprise dans la chronologie du rapport
   post-incident. Le même onglet existe sur les violations de données du registre RGPD.
7. Il faut pouvoir modifier l'incident (ou la violation) pour tracer une communication ; le lire
   suffit pour voir l'onglet. Les contacts officiels ne sont montrés qu'avec le droit du contexte de
   l'organisation, et seulement ceux de l'entité de l'incident ou d'une entité parente.
8. Sur la fiche de la mesure **A.5.26** et sur l'exigence **7.4** du SMSI, le cadre « Communication
   de crise » indique le nombre de modèles actifs, les modèles sans destinataire, les situations sans
   modèle et les communications tracées depuis 12 mois. Pendant un exercice de crise, utilisez les
   mêmes modèles depuis l'incident d'exercice.
9. **Suppléants** : en ajoutant un destinataire à la liste de diffusion, choisissez dans « Rôle dans
   la liste » le destinataire principal dont il est le suppléant (par exemple l'adjoint de la
   directrice fictive). Le suppléant s'affiche sous son principal ; un principal sans suppléant
   porte le badge « Sans suppléant » (un groupe n'en a pas besoin). Dans l'onglet de l'incident, le
   suppléant n'est coché d'office que si son principal est masqué ou sans adresse. Retirer un
   principal fait de ses suppléants des destinataires principaux.
10. **Groupes** : les adresses électroniques des membres actifs d'un groupe sont reprises par
   « Copier les adresses électroniques » et par l'export `.eml` ; les membres sans adresse sont
   comptés.
11. **Revue annuelle** : une fois par an, relisez la liste de diffusion de chaque modèle puis
   cliquez sur **Marquer la liste comme revue aujourd'hui** (droit de modifier les modèles). Le
   cadre de la mesure A.5.26 compte les listes non revues depuis 12 mois et les destinataires
   principaux sans suppléant.
12. **Exercices** : les communications tracées sur l'incident d'un exercice (ou d'une cellule de
   crise) apparaissent dans la salle de crise, avec un lien « Préparer un message », et dans le
   rapport PDF de l'exercice.

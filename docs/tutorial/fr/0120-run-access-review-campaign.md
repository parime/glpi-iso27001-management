### Lancer une campagne de revue des accès

Depuis **GRC & Conformité > Opérations > Revues des accès**, une campagne liste les comptes à revoir et les
confie à leurs responsables (supérieur hiérarchique, responsable de groupe ou pilote). Chaque
responsable traite ses lignes depuis **Mes revues d'accès** : conserver, modifier ou retirer. Un
droit à retirer devient un ticket ; le plugin ne retire jamais un droit lui-même. À la clôture, un
rapport PDF est joint à la campagne et à la mesure de l'Annexe A concernée.

![Campagnes de revue des accès](screenshots/tutorial/43-access-review-list.png)

**Revue pré-remplie par des règles.** Au lancement, chaque compte arrive avec une suggestion et sa
raison, calculées à partir de ce que GLPI sait déjà, sans IA ni service extérieur :

- **Retirer** (badge rouge) : compte supprimé de l'annuaire, date de fin du compte dépassée, ou
  départ enregistré dans assetsign dont la date est passée ;
- **À examiner** (badge orange) : départ ou fin du compte dans les prochains jours, aucune connexion
  depuis N jours ou compte jamais utilisé, groupes changés sans revue des profils, profil à
  privilèges (la raison précise quand aucune MFA n'est connue, d'après la page **Couverture MFA**) ;
- **Garder** (badge vert) : aucun signal d'alerte.

1. Sur le formulaire de la campagne, réglez l'encadré **Règles de suggestion** : durée
   d'inactivité, horizon du départ proche, profils à privilèges, MFA, changements de groupes.
2. Lancez la campagne. L'onglet **Comptes à revoir** compte les suggestions et ajoute la colonne
   **Suggestion et raison** ; le bouton proposé par les règles porte une ampoule.
3. Le relecteur décide toujours lui-même. Garder un compte que les règles proposent de retirer
   demande un commentaire (par exemple un contrat prolongé) ; une décision opposée à la suggestion
   est signalée dans l'onglet et dans le rapport PDF.
4. Pendant la campagne, **Mettre à jour les suggestions** recalcule les comptes encore à revoir ;
   les comptes déjà décidés gardent la suggestion que leur relecteur a vue.

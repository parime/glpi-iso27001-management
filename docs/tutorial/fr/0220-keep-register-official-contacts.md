### Tenir le registre des contacts officiels (mesures A.5.5 et A.5.6)

1. Ouvrez Conformité > **Contacts officiels** (onglet à côté de « Contexte de l'organisation »).
   L'encadré « À quoi sert cette page ? » rappelle ce qui est attendu.
2. Cliquez sur **Ajouter un contact officiel**. La fiche est une partie intéressée du contexte, déjà
   cochée « Contact officiel » : il n'y a pas de second registre à tenir. Une partie existante (un
   assureur, un hébergeur enregistré comme fournisseur) devient un contact officiel en cochant la
   case sur sa fiche.
3. Renseignez par exemple « Autorité de protection des données (fictive) » : catégorie « Autorité de
   contrôle », coordonnées (téléphone, formulaire en ligne), **quand le contacter** (« Violation de
   données présentant un risque pour les personnes, RGPD article 33 »), **délai** (« 72 heures »),
   l'interlocuteur interne habilité (le DPO) et, en secours, un groupe (la cellule de crise). Cochez
   « À contacter pour une violation de données personnelles ».
4. Ajoutez de même le CSIRT national et la police (cochez « À contacter pour un incident de
   sécurité »), votre assureur et les groupes d'intérêt que vous suivez (catégorie « Groupe
   d'intérêt », preuve de la mesure A.5.6).
5. Après avoir vérifié les coordonnées, cliquez sur **Enregistrer la vérification** : la date et
   votre nom sont conservés dans l'historique. Un contact non vérifié depuis 12 mois est signalé dans
   le registre, et son propriétaire reçoit une relance (au plus une fois par mois).
6. Cliquez sur **Imprimer le registre (PDF)** et gardez la copie avec le plan de gestion de crise :
   elle reste utilisable si GLPI est indisponible.
7. Depuis un incident de sécurité ou une violation de données, l'onglet **Contacts officiels** affiche
   les contacts à prévenir, avec le délai et la personne habilitée. Seuls les contacts de l'entité de
   l'incident ou d'une entité parente (fiche visible des sous-entités) sont proposés.
8. Sur la fiche des mesures A.5.5 et A.5.6 de la SoA, le cadre « Registre des contacts officiels »
   montre le nombre de contacts, vérifiés ou à vérifier, les prises de contact tracées depuis
   12 mois, avec le lien vers le registre et son PDF.
9. Renseignez aussi le **délai en heures** du contact (72 pour l'autorité de protection des données,
   24 pour l'alerte précoce du CSIRT) : l'onglet **Contacts officiels** d'un incident ou d'une
   violation affiche alors l'échéance de chaque contact, comptée depuis la détection de l'incident
   ou la prise de connaissance de la violation.
10. Quand vous avez appelé ou écrit au contact, cliquez sur **Tracer une prise de contact** sur sa
    ligne : date et heure, référence du dossier (par exemple « DOSSIER-FICTIF-001 »), commentaire.
    Si vous décidez qu'il n'avait pas à être prévenu, choisissez « Non nécessaire (décision) » et
    donnez le motif. Le badge passe à « Prévenu dans le délai », « Prévenu en retard » ou « Non
    nécessaire (décision tracée) ». Rien n'est envoyé depuis le plugin.
11. La fiche du contact a un onglet **Prises de contact tracées** : l'historique de vos relations
    avec cette autorité ou ce groupe d'intérêt, à montrer à l'auditeur.
12. Si un contact ne concerne que certains incidents (le CERT sectoriel pour les logiciels
    malveillants, par exemple), choisissez ces types dans **Seulement pour ces types d'incident** :
    il n'est alors proposé que sur les incidents de ce type. Laissé vide, il est proposé sur tous
    les incidents. Un incident de type « Violation de données » propose aussi les contacts à
    prévenir pour une violation de données personnelles.
13. Dans l'onglet **Communication de crise** d'un incident, un modèle dont la liste de diffusion
    contient un contact officiel propose la case **Tracer aussi une prise de contact avec les
    contacts officiels cochés** : en enregistrant le message envoyé, la prise de contact est tracée
    en même temps (badge « Depuis une communication de crise » dans l'onglet Contacts officiels).
14. Après chaque revue du registre, cliquez sur **Archiver sur les mesures A.5.5 et A.5.6** : le
    PDF daté est joint aux deux mesures de la SoA (onglet Documents), et les derniers registres
    archivés sont listés en tête du registre.
15. Les contacts officiels figurent aussi à la fin du **plan de continuité (PDF)** des processus
    métier, et dans la salle de crise d'un exercice ou d'une crise réelle (cadre « Contacts officiels
    à prévenir », repris dans le rapport de l'exercice).

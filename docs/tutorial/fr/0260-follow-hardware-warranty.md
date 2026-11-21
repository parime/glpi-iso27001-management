### Suivre la fin de garantie du matériel (mesures A.7.13 et A.8.9)

Le plugin lit les informations financières déjà saisies dans GLPI (onglet « Gestion » d'un
ordinateur, d'un moniteur, d'un matériel réseau...) : la **date de début de garantie** et la
**durée de garantie** en mois. Il ne modifie rien. Il faut le droit « Registres sensibles » du
plugin, le droit natif de lecture des informations financières (« Gestion ») et celui de chaque
type de matériel.

1. Sur la fiche d'un ordinateur fictif, par exemple « PC-COMPTA-01 », ouvrez l'onglet **Gestion**
   et saisissez une date de début de garantie (01/01/2023) et une durée (36 mois). GLPI affiche
   la date de fin de garantie (01/01/2026).
2. Ouvrez Sécurité technique > **Vulnérabilités du parc**, puis descendez jusqu'à la carte **Fin
   de garantie du matériel**, sous la carte « Fin de support ». Quatre compteurs donnent le nombre
   de matériels dont la garantie est **expirée**, **proche de sa fin**, **en cours**, ou
   **inconnue** (pas de date ou pas de durée : jamais compté comme sous garantie). Une durée « À
   vie » compte comme sous garantie.
3. Le tableau croise le **type de matériel** et l'**entité** ; seules vos entités apparaissent.
   La liste dessous donne les matériels à traiter, avec un lien vers chaque fiche ; le bouton
   **Tout le matériel** les montre tous.
4. Le seuil de « fin proche » est celui de la fin de support (90 jours par défaut), réglé dans la
   configuration du plugin, cadre « Fin de support (endoflife.date) ». Il s'applique même si
   endoflife.date n'est pas activé.
5. Pour chaque garantie expirée, vérifiez qu'un contrat de maintenance couvre le matériel,
   renouvelez la garantie ou prévoyez le remplacement. Cliquez sur **Export (CSV)** pour remettre
   la liste à l'auditeur (un matériel par ligne, avec fabricant, modèle et dates).
6. Sur la fiche des mesures A.7.13 et A.8.9 de la SoA, le cadre « Fin de garantie du matériel »
   montre les compteurs ; les contrôles de cohérence du SMSI listent chaque matériel dont la
   garantie est expirée (règle « Matériels dont la garantie est expirée »).
7. **Fin de vie constructeur** (facultatif) : quand un constructeur annonce la date après laquelle
   un modèle n'a plus de réparations, de pièces ni de firmware, ouvrez la configuration du plugin,
   cadre **Fin de vie constructeur des modèles de matériel** (lien « Saisir les dates de fin de
   vie » de la carte). Les modèles en service dans vos entités y sont listés : saisissez par
   exemple 30/06/2025 pour le modèle fictif « Modèle-Essai 100 », puis **Sauvegarder**. Rien n'est
   écrit dans les modèles de GLPI ; une date vidée est effacée, et chaque saisie est tracée dans
   le journal d'audit.
8. La carte affiche alors les compteurs « Fin de vie constructeur atteinte », « proche » (même
   seuil), « pris en charge » et « pas de date », et la colonne **Fin de vie constructeur** ; le
   filtre **À traiter** inclut les matériels dont la fin de vie est atteinte ou proche, même sous
   garantie. L'export ajoute la date et l'état, la preuve des mesures les compte, et le contrôle de
   cohérence liste chaque matériel concerné (règle « Matériel dont le modèle a atteint la fin de
   vie constructeur »).

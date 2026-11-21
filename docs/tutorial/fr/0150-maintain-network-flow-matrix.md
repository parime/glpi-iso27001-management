### Tenir la matrice des flux réseau

**GRC & Conformité > Sécurité technique > Matrice des flux** recense les flux autorisés : source et destination (un
actif GLPI ou un réseau, une zone, une adresse), protocole, ports, justification, propriétaire et
date de fin. Les contrôles automatiques signalent les flux sans justification ou sans propriétaire,
expirés, et les services d'administration publiés sur Internet.

![Matrice des flux avec ses contrôles](screenshots/tutorial/46-network-flow-matrix.png)

Une matrice existante s'importe en CSV, avec une simulation qui n'écrit rien ; réimporter le même
fichier ne crée pas de doublon.

![Import CSV de la matrice des flux](screenshots/tutorial/47-network-flow-import.png)

Avec le plugin Inventaire Docker, le bouton **Proposer le flux** d'une exposition (port publié,
route Traefik, ingress) ouvre un nouveau flux déjà rempli : type, nom, source, application de
destination, protocole et ports. Un encadré « Flux proposé » le signale ; vérifiez les valeurs,
ajoutez la justification et le propriétaire, puis **Ajouter** : rien n'est enregistré avant.

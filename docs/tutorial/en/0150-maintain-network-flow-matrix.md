### Maintain the network flow matrix

**GRC & Conformité > Sécurité technique > Matrice des flux** records the allowed flows: source and destination (a GLPI
asset or a network, zone or address), protocol, ports, justification, owner and end date.
Automatic checks flag flows without a justification or owner, expired flows, and administration
services published on the Internet.

![Network flow matrix with its checks](screenshots/tutorial/46-network-flow-matrix.png)

An existing matrix can be imported from CSV, with a dry run that writes nothing; importing the
same file again creates no duplicate.

![CSV import of the network flow matrix](screenshots/tutorial/47-network-flow-import.png)

With the Docker inventory plugin, the **Propose the flow** button of an exposure (published
port, Traefik route, ingress) opens a new flow already filled in: type, name, source, destination
application, protocol and ports. A "Proposed flow" box says so; check the values, add the
justification and the owner, then **Add**: nothing is saved before.

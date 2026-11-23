### Added

- **Rôles du SMSI : RSSI et DPO** (issue #1054, mesure A.5.2). Dans **Configuration > Plugins >
  GRC Manager**, onglet **Personnes et politiques**, le bloc **Rôles du SMSI** donne à chacun des
  deux rôles un **groupe GLPI** (titulaires et suppléants) et une **adresse e-mail générique**
  (`rssi@…`, `dpo@…`), stable quand la personne change ; le format de l'adresse est vérifié. Le
  groupe RSSI est celui qui lisait déjà les documents des audits internes : il ne se choisit plus
  que dans ce bloc. Les deux rôles, leurs membres actifs et leurs adresses sont affichés en tête de
  la **matrice des responsabilités** (rubrique **Organisation du SMSI**) et dans son PDF.
- **Notifications aux rôles du SMSI** (issue #1054). La notification « Nouvelles expositions à des
  fuites de données » propose les destinataires **Groupe DPO**, **Adresse générique du DPO** et
  **Adresse générique du RSSI** ; le groupe DPO est ajouté par défaut sur une nouvelle
  installation.

### Changed

- **Contact du DPO sur les registres** (issue #1054). Le registre des traitements (article 30) et
  le registre des violations de données affichent le groupe DPO, ses membres et l'adresse
  générique, à la place du champ texte libre « Délégué à la protection des données », supprimé. La
  mise à jour reprend sa valeur : une adresse trouvée devient l'adresse générique, le reste est
  gardé comme note **à reprendre**, affichée sur les registres tant qu'aucun groupe ni adresse
  n'est choisi.

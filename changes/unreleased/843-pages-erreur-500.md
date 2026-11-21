### Fixed

- **Pages en erreur 500 sous GLPI 12.** Le journal d'audit ne tombe plus quand il contient un
  événement d'un type qui n'est pas un objet GLPI (import de chemins d'attaque Active Directory) :
  le nom court du type est affiché. Les pages qui refusaient l'accès avec une méthode retirée de
  GLPI 12 (comptes des contrôleurs dans la revue des accès, programme d'audit, conformité des
  configurations, chiffrement des disques, couverture des journaux, gestion des serveurs, blocages
  d'une mesure, échantillonnage de l'audit de certification) renvoient de nouveau un refus 403.
  Un test vérifie qu'aucun appel à ces méthodes retirées ne revient.

<?php

/**
 * Issue #980 : un ticket de l'entité A, pour les scénarios d'édition (tests/security/edit-scenarios.py)
 * et le parcours des écrans de GLPI modifiés par le plugin. Les autres objets édités (incident,
 * risque, mesure, non-conformité, évaluation fournisseur, gabarit d'incident) viennent du jeu de
 * données. Fragment inclus par tests/security/setup.php.
 */

$ticketCriteria = ['name' => 'Ticket securite A', 'entities_id' => (int) $a];
$ticketRow = $DB->request(['FROM' => 'glpi_tickets', 'WHERE' => $ticketCriteria, 'LIMIT' => 1])->current();
$ids['ticket_A'] = $ticketRow !== null ? (int) $ticketRow['id'] : $add(new Ticket(), $ticketCriteria + [
    'content' => 'Ticket des scenarios d edition',
]);

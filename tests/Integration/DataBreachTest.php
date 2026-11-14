<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\DataBreach;
use GlpiPlugin\Grcmanager\ProcessingActivity;
use GlpiPlugin\Grcmanager\Services\Incident\RegulatoryDeadlines;
use GlpiPlugin\Grcmanager\Services\Privacy\DataBreachRules;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #620 : registre des violations de données personnelles (RGPD, article 33.5). Données fictives.
 */
final class DataBreachTest extends GrcmanagerIntegrationTestCase
{
    private function incident(array $input = []): PluginGrcmanagerSecurityIncident
    {
        $incident = new PluginGrcmanagerSecurityIncident();
        $id = (int) $incident->add($input + [
            'name'                 => 'PHPUnit fichier de paie envoyé au mauvais destinataire',
            'content'              => 'Incident fictif',
            'entities_id'          => 0,
            'date'                 => '2026-03-10 09:30:00',
            'personal_data_breach' => 1,
            'cia_impact'           => 'confidentiality',
        ]);
        $this->assertGreaterThan(0, $id);
        $incident->getFromDB($id);

        return $incident;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function breach(array $input = []): DataBreach
    {
        $breach = new DataBreach();
        $id = (int) $breach->add($input + ['entities_id' => 0, 'date_awareness' => '2026-03-10 09:30']);
        $this->assertGreaterThan(0, $id, 'Violation fictive enregistrée.');
        $breach->getFromDB($id);

        return $breach;
    }

    public function testBreachIsProposedFromTheIncidentWithoutChangingIt(): void
    {
        $activity = new ProcessingActivity();
        $activityId = (int) $activity->add(['name' => 'PHPUnit paie', 'entities_id' => 0]);
        $incident = $this->incident(['gdpr_notification_at' => '2026-03-12 11:00:00']);
        $incidentId = (int) $incident->getID();
        $this->assertTrue(ProcessingActivity::setIncidentLink($activityId, $incidentId, true));
        $before = $incident->fields;

        $prefill = DataBreach::prefillFromIncident($incidentId);
        $this->assertSame($incidentId, $prefill[DataBreach::INCIDENT_FK]);
        $this->assertSame('2026-03-10 09:30:00', $prefill['date_awareness']);
        $this->assertSame('["confidentiality"]', $prefill['nature']);
        $this->assertSame([$activityId], $prefill['_activity_ids']);
        $this->assertSame('notified', $prefill['authority_decision']);
        $this->assertSame(0, DataBreach::breachIdForIncident($incidentId), 'Une proposition, rien d\'inscrit.');

        $activities = $prefill['_activity_ids'];
        unset($prefill['_activity_ids']);
        $breach = $this->breach($prefill + [
            DataBreach::LISTS_MARKER     => 1,
            DataBreach::ACTIVITIES_FIELD => $activities,
            'subjects_count'             => '40',
        ]);
        $this->assertSame((int) $breach->getID(), DataBreach::breachIdForIncident($incidentId));
        $this->assertSame([$activityId], DataBreach::getActivityIds((int) $breach->getID()));
        $this->assertSame(40, (int) $breach->fields['subjects_count']);
        $this->assertSame(DataBreachRules::STATE_NOTIFIED, DataBreach::stateOf($breach->fields)['state']);
        $this->assertSame([], DataBreach::prefillFromIncident($incidentId), 'Déjà inscrit : plus de proposition.');

        // Un second enregistrement pour le même incident est refusé.
        $this->assertFalse((new DataBreach())->add([
            'entities_id' => 0, 'date_awareness' => '2026-03-10 09:30', DataBreach::INCIDENT_FK => $incidentId,
        ]));

        $incident->getFromDB($incidentId);
        foreach (['status', 'priority', 'personal_data_breach', 'gdpr_notification_at', 'name'] as $field) {
            $this->assertSame($before[$field], $incident->fields[$field], "Incident inchangé : $field");
        }
    }

    public function testDecisionNotToNotifyRequiresAReasonAndStopsTheGdprReminder(): void
    {
        $incident = $this->incident();
        $incidentId = (int) $incident->getID();
        $this->assertFalse((new DataBreach())->add([
            'entities_id' => 0, 'date_awareness' => '2026-03-10 09:30', DataBreach::INCIDENT_FK => $incidentId,
            'authority_decision' => 'not_notified',
        ]), 'Non notifiée sans justification : refusée.');
        $this->assertSame(0, DataBreach::breachIdForIncident($incidentId));

        $breach = $this->breach([
            DataBreach::INCIDENT_FK => $incidentId,
            'authority_decision'    => 'not_notified',
            'authority_reason'      => 'Destinataire interne, suppression confirmée : risque improbable.',
            'risk_level'            => 'unlikely',
        ]);
        $id = (int) $breach->getID();
        $this->assertSame(DataBreachRules::STATE_NOT_NOTIFIED, DataBreach::stateOf($breach->fields)['state']);
        $this->assertFalse($breach->update(['id' => $id, 'authority_reason' => ' ']), 'Justification effacée : refusée.');

        $late = (int) strtotime('2026-04-01 00:00:00');
        $fields = DataBreach::withAuthorityDecision($incident->fields);
        $this->assertSame([], RegulatoryDeadlines::banners($fields, $late), 'Plus de bandeau RGPD.');
        $this->assertNotEmpty(RegulatoryDeadlines::banners($incident->fields, $late), 'Sans le registre : en retard.');

        // Notifiée en retard : la date et les motifs du retard sont exigés.
        $this->assertFalse($breach->update(['id' => $id, 'authority_decision' => 'notified']));
        $this->assertFalse($breach->update([
            'id' => $id, 'authority_decision' => 'notified', 'date_notified' => '2026-03-16 10:00',
        ]));
        $this->assertTrue($breach->update([
            'id' => $id, 'authority_decision' => 'notified', 'date_notified' => '2026-03-16 10:00',
            'delay_reason' => 'Ampleur connue le 15 mars seulement.',
        ]));
        $breach->getFromDB($id);
        $this->assertSame(DataBreachRules::STATE_NOTIFIED_LATE, DataBreach::stateOf($breach->fields)['state']);
    }

    public function testRegisterExportAndRights(): void
    {
        $activity = new ProcessingActivity();
        $activityId = (int) $activity->add(['name' => 'PHPUnit dossiers RH', 'entities_id' => 0]);
        $breach = $this->breach([
            'name'                       => 'PHPUnit envoi de bulletins fictifs',
            'authority_decision'         => 'not_notified',
            'authority_reason'           => 'Risque improbable, justification conservée.',
            'nature'                     => ['confidentiality', 'inconnue'],
            'data_subjects'              => ['employees'],
            DataBreach::LISTS_MARKER     => 1,
            DataBreach::ACTIVITIES_FIELD => [$activityId, 999999],
            'subjects_count'             => '40',
        ]);
        $id = (int) $breach->getID();
        $this->assertSame('["confidentiality"]', $breach->fields['nature'], 'Valeur inconnue écartée.');
        $this->assertSame([$activityId], DataBreach::getActivityIds($id), 'Fiche inexistante non liée.');

        $rows = array_values(array_filter(DataBreach::registerRows(), static fn (array $r): bool => $r['id'] === (string) $id));
        $this->assertCount(1, $rows);
        $this->assertSame(array_keys(DataBreach::registerColumns()), array_keys($rows[0]));
        $this->assertSame('PHPUnit dossiers RH', $rows[0]['activities']);
        $this->assertSame('40', $rows[0]['subjects_count']);
        $this->assertSame('Risque improbable, justification conservée.', $rows[0]['authority_reason']);

        // Une fiche à la corbeille sort de l'export ; elle reste lisible pour la restaurer.
        $breach->delete(['id' => $id]);
        $ids = array_column(DataBreach::registerRows(), 'id');
        $this->assertNotContains((string) $id, $ids);

        // Registre sensible : sans le droit « Protection des données personnelles », ni lecture ni création.
        $saved = $_SESSION['glpiactiveprofile'][DataBreach::RIGHTNAME];
        try {
            $_SESSION['glpiactiveprofile'][DataBreach::RIGHTNAME] = 0;
            $this->assertFalse(DataBreach::canView());
            $this->assertFalse(DataBreach::canCreate());
            $this->assertFalse((new DataBreach())->can($id, READ));
            $this->assertFalse(DataBreach::getMenuContent());
            $incident = $this->incident();
            $this->assertSame('', (new DataBreach())->getTabNameForItem($incident));
        } finally {
            $_SESSION['glpiactiveprofile'][DataBreach::RIGHTNAME] = $saved;
        }

        // Purge : la fiche et ses liens disparaissent, le traitement reste.
        $this->assertTrue($breach->delete(['id' => $id], true));
        $this->assertSame([], DataBreach::getActivityIds($id));
        $this->assertTrue($activity->getFromDB($activityId));
    }

    public function testPurgingTheIncidentOrTheActivityKeepsTheBreach(): void
    {
        $incident = $this->incident();
        $activity = new ProcessingActivity();
        $activityId = (int) $activity->add(['name' => 'PHPUnit clients', 'entities_id' => 0]);
        $breach = $this->breach([
            DataBreach::INCIDENT_FK      => (int) $incident->getID(),
            DataBreach::ACTIVITIES_FIELD => [$activityId],
        ]);
        $id = (int) $breach->getID();

        $activity->delete(['id' => $activityId], true);
        $this->assertSame([], DataBreach::getActivityIds($id));
        $incident->delete(['id' => (int) $incident->getID()], true);
        $this->assertTrue($breach->getFromDB($id), 'L\'article 33.5 impose de conserver la violation.');
        $this->assertSame(0, (int) $breach->fields[DataBreach::INCIDENT_FK]);
    }

    public function testFormTabAndPlaybookStepRender(): void
    {
        $incident = $this->incident();
        $tab = (new DataBreach())->getTabNameForItem($incident);
        $this->assertNotSame('', $tab);
        ob_start();
        DataBreach::displayTabContentForItem($incident);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString(DataBreach::INCIDENT_FK . '=' . (int) $incident->getID(), $html);

        // Entité active de la session (posée par GLPI à la connexion) : celle où la fiche serait créée.
        $_SESSION['glpiactive_entity'] ??= 0;
        ob_start();
        try {
            (new DataBreach())->showForm(0, [DataBreach::INCIDENT_FK => (int) $incident->getID()]);
        } finally {
            $form = (string) ob_get_clean();
        }
        $this->assertStringContainsString('PHPUnit fichier de paie', $form, 'Proposition préremplie.');
        $this->assertStringContainsString('name="authority_reason"', $form);

        $this->assertTrue(DataBreach::isRegisterStep([
            'phase' => 'post_incident', 'name' => 'Autre libellé', 'description' => 'Nach DSGVO (Artikel 33 Abs. 5).',
        ]));
        $this->assertTrue(DataBreach::isRegisterStep([
            'phase' => 'post_incident', 'name' => 'Record the breach in the breach register', 'description' => '',
        ]));
        $this->assertFalse(DataBreach::isRegisterStep([
            'phase' => 'containment', 'name' => 'Record the breach in the breach register', 'description' => '',
        ]));
        $this->assertFalse(DataBreach::isRegisterStep([
            'phase' => 'post_incident', 'name' => 'Retour d\'expérience', 'description' => 'Article 133, alinéa 52.',
        ]));
    }
}

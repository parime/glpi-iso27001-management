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

use GlpiPlugin\Grcmanager\ProcessingActivity;
use GlpiPlugin\Grcmanager\Services\Review\PeriodicReviewCatalog;

/**
 * Issue #375 : registre des activités de traitement (RGPD, article 30). Données fictives.
 */
final class ProcessingActivityTest extends GrcmanagerIntegrationTestCase
{

    public function testDpoContactIsPlainTextAndBounded(): void
    {
        $before = ProcessingActivity::dpoContact();
        try {
            ProcessingActivity::saveDpoContact('  <script>x</script>DPO SITE_A, dpo@example.org  ');
            $this->assertSame('xDPO SITE_A, dpo@example.org', ProcessingActivity::dpoContact());
            ProcessingActivity::saveDpoContact(str_repeat('a', 300));
            $this->assertSame(255, mb_strlen(ProcessingActivity::dpoContact()));
        } finally {
            ProcessingActivity::saveDpoContact($before);
        }
    }
    public function testRightIsInstalledForSuperAdminOnly(): void
    {
        global $DB;

        $rows = iterator_to_array($DB->request([
            'FROM'  => 'glpi_profilerights',
            'WHERE' => ['name' => ProcessingActivity::RIGHTNAME, 'rights' => ['>', 0]],
        ]), false);
        $profiles = array_map(static fn (array $row): int => (int) $row['profiles_id'], $rows);
        $this->assertSame([4], $profiles, 'Seul le Super-Admin reçoit le droit du registre RGPD à l\'installation');

        $fields = array_column(\GlpiPlugin\Grcmanager\Profile::getRightsMatrix(), 'field');
        $this->assertContains(ProcessingActivity::RIGHTNAME, $fields);
    }

    public function testSearchListRuns(): void
    {
        $this->createActivity(['name' => 'Paie']);
        // Fiches rattachées à une entité : la recherche lit les entités de la session sous la forme
        // d'une vraie session GLPI (chaîne entre apostrophes, entités parentes).
        $session = [$_SESSION['glpiactiveentities_string'] ?? null, $_SESSION['glpiparententities'] ?? null];
        $_SESSION['glpiactiveentities_string'] = "'" . implode("','", $_SESSION['glpiactiveentities']) . "'";
        $_SESSION['glpiparententities'] = [];
        try {
            $data = \Glpi\Search\SearchEngine::getData(
                ProcessingActivity::class,
                ['criteria' => []],
                \GlpiPlugin\Grcmanager\Services\DefaultSearchColumns::COLUMNS[ProcessingActivity::class]
            );
        } finally {
            [$_SESSION['glpiactiveentities_string'], $_SESSION['glpiparententities']] = $session;
        }
        $this->assertArrayHasKey('data', $data);
        $this->assertGreaterThan(0, $data['data']['totalcount']);
    }

    public function testNameIsRequiredAndValuesAreNormalised(): void
    {
        $this->assertFalse((new ProcessingActivity())->add(['name' => '  ']));

        // Saisie sans entité : l'entité active, jamais la racine par défaut de la table.
        $entityId = $this->createTestEntity(0, 'Entite RGPD active ' . random_int(1000, 9999));
        $active = $_SESSION['glpiactive_entity'] ?? null;
        $_SESSION['glpiactive_entity'] = $entityId;
        try {
            $activity = new ProcessingActivity();
            $activity->getFromDB((int) $activity->add(['name' => 'Sans entite saisie']));
            $this->assertSame($entityId, (int) $activity->fields['entities_id']);
        } finally {
            $_SESSION['glpiactive_entity'] = $active;
        }

        $activity = new ProcessingActivity();
        $id = $this->createActivity([
            'name'                 => 'Gestion de la paie',
            'role'                 => 'inconnu',
            'legal_basis'          => 'legal_obligation',
            'data_subjects'        => ['employees', 'pirate'],
            'sensitive_data'       => ['health'],
            'transfers_outside_eu' => 'on',
            'transfer_safeguards'  => 'nimporte',
            'last_review_date'     => '',
        ]);
        $activity->getFromDB($id);
        $this->assertSame('controller', $activity->fields['role']);
        $this->assertSame('draft', $activity->fields['status']);
        $this->assertSame('legal_obligation', $activity->fields['legal_basis']);
        $this->assertSame('["employees"]', $activity->fields['data_subjects']);
        $this->assertSame('["health"]', $activity->fields['sensitive_data']);
        $this->assertSame('[]', $activity->fields['data_categories']);
        $this->assertSame(1, (int) $activity->fields['transfers_outside_eu']);
        $this->assertSame('', $activity->fields['transfer_safeguards']);
        $this->assertNull($activity->fields['last_review_date']);
        $this->assertContains('transfer_without_safeguard', $activity->getChecks());
        $this->assertContains('sensitive_without_dpia', $activity->getChecks());

        // Formulaire renvoyé sans aucune case cochée : les listes sont vidées.
        $this->assertTrue($activity->update(['id' => $id, ProcessingActivity::LISTS_MARKER => 1]));
        $activity->getFromDB($id);
        $this->assertSame('[]', $activity->fields['data_subjects']);
        $this->assertSame('[]', $activity->fields['sensitive_data']);
    }

    public function testLinksKeepOnlyReadableItemsAndShowOnTheItem(): void
    {
        global $DB;

        $applianceId = (int) (new \Appliance())->add(['name' => 'Logiciel de paie fictif', 'entities_id' => 0]);
        $supplierId = (int) (new \Supplier())->add(['name' => 'Prestataire paie fictif', 'entities_id' => 0]);
        $controlId = (int) $DB->request([
            'SELECT' => 'id', 'FROM' => \GlpiPlugin\Grcmanager\Control::getTable(), 'LIMIT' => 1,
        ])->current()['id'];
        $id = $this->createActivity([
            'name'                               => 'Paie',
            ProcessingActivity::LISTS_MARKER      => 1,
            '_linked_items_Appliance_defined'    => 1,
            'linked_items_Appliance'             => [$applianceId, 999999999],
            '_linked_items_Supplier_defined'     => 1,
            'linked_items_Supplier'              => [$supplierId],
            ProcessingActivity::CONTROLS_FIELD   => [$controlId],
        ]);
        $this->assertSame([
            ['itemtype' => 'Appliance', 'items_id' => $applianceId],
            ['itemtype' => \GlpiPlugin\Grcmanager\Control::class, 'items_id' => $controlId],
            ['itemtype' => 'Supplier', 'items_id' => $supplierId],
        ], ProcessingActivity::getLinks($id));
        $this->assertTrue(ProcessingActivity::hasLinkedControls($id));

        $appliance = new \Appliance();
        $appliance->getFromDB($applianceId);
        $this->assertCount(1, ProcessingActivity::getForItem($appliance));

        // Un lien vers un élément que la personne ne voit pas reste en place à l'enregistrement.
        $hiddenEntity = $this->createTestEntity(0, 'Entite RGPD cachee ' . random_int(1000, 9999));
        $hiddenId = (int) (new \Appliance())->add(['name' => 'Application cachee', 'entities_id' => $hiddenEntity]);
        $DB->insert(ProcessingActivity::ITEMS_TABLE, [
            'plugin_grcmanager_processingactivities_id' => $id,
            'itemtype'                                  => 'Appliance',
            'items_id'                                  => $hiddenId,
        ]);
        $entities = $_SESSION['glpiactiveentities'];
        $_SESSION['glpiactiveentities'] = array_values(array_diff($entities, [$hiddenEntity]));
        $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);
        try {
            $this->assertSame(1, ProcessingActivity::getVisibleLinks($id)['hidden']);
            (new ProcessingActivity())->update([
                'id'                              => $id,
                '_linked_items_Appliance_defined' => 1,
                'linked_items_Appliance'          => [],
            ]);
            $links = ProcessingActivity::getLinks($id);
            $this->assertContains(['itemtype' => 'Appliance', 'items_id' => $hiddenId], $links);
            $this->assertNotContains(['itemtype' => 'Appliance', 'items_id' => $applianceId], $links);
            // Une liste non soumise (fournisseurs) ne change pas.
            $this->assertContains(['itemtype' => 'Supplier', 'items_id' => $supplierId], $links);
        } finally {
            $_SESSION['glpiactiveentities'] = $entities;
            $_SESSION['glpiactiveentities_string'] = implode("','", $entities);
        }

        (new ProcessingActivity())->delete(['id' => $id], true);
        $this->assertSame([], ProcessingActivity::getLinks($id));
    }

    public function testExportIsLimitedToActiveEntities(): void
    {
        $otherEntity = $this->createTestEntity(0, 'Entite RGPD B ' . random_int(1000, 9999));
        $this->createActivity(['name' => 'Traitement visible', 'purpose' => '=CMD()']);
        $this->createActivity(['name' => 'Traitement de B', 'entities_id' => $otherEntity]);
        $names = array_column(ProcessingActivity::registerRows(), 'name');
        $this->assertContains('Traitement visible', $names);
        $this->assertContains('Traitement de B', $names);

        $entities = $_SESSION['glpiactiveentities'];
        $_SESSION['glpiactiveentities'] = array_values(array_diff($entities, [$otherEntity]));
        $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);
        try {
            $names = array_column(ProcessingActivity::registerRows(), 'name');
            $this->assertContains('Traitement visible', $names);
            $this->assertNotContains('Traitement de B', $names);
            $activity = new ProcessingActivity();
            $id = (int) current($activity->find(['name' => 'Traitement de B']))['id'];
            $this->assertFalse($activity->can($id, READ));
        } finally {
            $_SESSION['glpiactiveentities'] = $entities;
            $_SESSION['glpiactiveentities_string'] = implode("','", $entities);
        }
        $columns = array_keys(ProcessingActivity::registerColumns());
        $this->assertSame($columns, array_keys(ProcessingActivity::registerRows()[0]));
    }

    public function testTabIsEmptyWithoutTheRight(): void
    {
        $applianceId = (int) (new \Appliance())->add(['name' => 'Application RGPD', 'entities_id' => 0]);
        $this->createActivity([
            'name'                            => 'Traitement cite',
            '_linked_items_Appliance_defined' => 1,
            'linked_items_Appliance'          => [$applianceId],
        ]);
        $appliance = new \Appliance();
        $appliance->getFromDB($applianceId);

        $right = $_SESSION['glpiactiveprofile'][ProcessingActivity::RIGHTNAME] ?? 0;
        $_SESSION['glpiactiveprofile'][ProcessingActivity::RIGHTNAME] = 0;
        try {
            $this->assertSame('', (new ProcessingActivity())->getTabNameForItem($appliance));
            ob_start();
            $shown = ProcessingActivity::displayTabContentForItem($appliance);
            $html = (string) ob_get_clean();
            $this->assertFalse($shown);
            $this->assertStringNotContainsString('Traitement cite', $html);
        } finally {
            $_SESSION['glpiactiveprofile'][ProcessingActivity::RIGHTNAME] = $right;
        }
        ob_start();
        ProcessingActivity::displayTabContentForItem($appliance);
        $this->assertStringContainsString('Traitement cite', (string) ob_get_clean());
    }

    public function testAnnualReviewIsInThePeriodicReviewCatalog(): void
    {
        $this->assertTrue(PeriodicReviewCatalog::isKey('processing_register'));
        $this->assertSame(12, PeriodicReviewCatalog::defaultMonths('processing_register'));
        $this->assertNotNull(\GlpiPlugin\Grcmanager\PeriodicReview::getByKey('processing_register'));
        $this->assertSame(
            '/plugins/grcmanager/front/processingactivity.php',
            \GlpiPlugin\Grcmanager\PeriodicReview::getScreenPath('processing_register')
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function createActivity(array $input): int
    {
        $id = (int) (new ProcessingActivity())->add($input + ['entities_id' => 0]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }
}

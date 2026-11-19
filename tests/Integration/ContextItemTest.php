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

use GlpiPlugin\Grcmanager\ComplianceObligation;
use GlpiPlugin\Grcmanager\ContextItem;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\IsmsRequirement;
use GlpiPlugin\Grcmanager\ManagementReview;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\Services\Clause\IsmsClauseCatalog;
use GlpiPlugin\Grcmanager\Services\Context\ContextItemRules as Rules;
use Supplier;

/**
 * Issue #608 : contexte de l'organisation (enjeux et parties intéressées). Données fictives.
 */
final class ContextItemTest extends GrcmanagerIntegrationTestCase
{
    /**
     * @param array<string, mixed> $input
     */
    private function item(array $input): ContextItem
    {
        $item = new ContextItem();
        $id = (int) $item->add($input + ['entities_id' => 0]);
        $this->assertGreaterThan(0, $id, 'Fiche fictive enregistrée.');
        $item->getFromDB($id);

        return $item;
    }

    private function requirement(string $code): IsmsRequirement
    {
        $requirement = new IsmsRequirement();
        $this->assertTrue($requirement->getFromDBByCrit(['code' => $code]));

        return $requirement;
    }

    private function setRequirementStatus(string $code, string $status): void
    {
        global $DB;

        $DB->update(IsmsRequirement::getTable(), ['status' => $status], ['code' => $code]);
    }

    public function testIssueAndPartyMoveTheirRequirementToInProgress(): void
    {
        $this->setRequirementStatus('4.1', IsmsClauseCatalog::STATUS_NOT_STARTED);
        $this->setRequirementStatus('4.2', IsmsClauseCatalog::STATUS_COMPLIANT);

        $issue = $this->item([
            'kind' => Rules::KIND_ISSUE, 'name' => 'PHPUnit nouvelle loi cyber', 'nature' => 'external',
            'category' => 'regulatory', 'effect' => 'Nouvelles obligations de notification.',
        ]);
        $this->assertSame('external', $issue->fields['nature']);
        $this->assertSame(IsmsClauseCatalog::STATUS_IN_PROGRESS, $this->requirement('4.1')->fields['status']);

        $this->item([
            'kind' => Rules::KIND_PARTY, 'name' => 'PHPUnit clients grands comptes', 'party_type' => 'customer',
        ]);
        $this->assertSame(
            IsmsClauseCatalog::STATUS_COMPLIANT,
            $this->requirement('4.2')->fields['status'],
            'Un statut déjà avancé ne recule jamais.'
        );
    }

    public function testInputIsValidatedAndNormalized(): void
    {
        $this->assertFalse((new ContextItem())->add(['kind' => Rules::KIND_ISSUE, 'name' => ' ', 'entities_id' => 0]));
        $this->assertFalse((new ContextItem())->add([
            'kind' => Rules::KIND_PARTY, 'name' => 'PHPUnit actionnaire', 'relevance' => Rules::RELEVANCE_NOT_RETAINED,
            'entities_id' => 0,
        ]), 'Une partie écartée doit être justifiée.');

        $item = $this->item([
            'kind' => 'bogus', 'name' => 'PHPUnit enjeu', 'category' => '<b>x</b>', 'relevance' => 'yes',
        ]);
        $this->assertSame(Rules::KIND_ISSUE, $item->fields['kind']);
        $this->assertSame('other', $item->fields['category']);
        $this->assertSame(Rules::RELEVANCE_TO_ASSESS, $item->fields['relevance']);

        // Partie choisie parmi les fournisseurs de GLPI : son nom par défaut.
        $supplierId = (int) (new Supplier())->add(['name' => 'PHPUnit Hébergeur Exemple', 'entities_id' => 0]);
        $party = $this->item(['kind' => Rules::KIND_PARTY, 'name' => '', 'suppliers_id' => $supplierId]);
        $this->assertSame('PHPUnit Hébergeur Exemple', $party->fields['name']);
        $this->assertSame($supplierId, (int) $party->fields['suppliers_id']);

        // Fournisseur inexistant : jamais enregistré.
        $this->assertTrue($party->update(['id' => $party->getID(), 'suppliers_id' => 999999]));
        $party->getFromDB($party->getID());
        $this->assertSame(0, (int) $party->fields['suppliers_id']);
    }

    public function testLinksToRisksObligationsAndControls(): void
    {
        $riskId = (int) (new Risk())->add(['title' => 'PHPUnit risque de notification tardive', 'entities_id' => 0]);
        $obligationId = (int) (new ComplianceObligation())->add([
            'title' => 'PHPUnit contrat client : notification sous 24 h', 'type' => 'contractual',
        ]);
        $controls = ContextItem::linkChoices(Control::class, 0);
        $this->assertNotEmpty($controls, 'Mesures de la SoA proposées.');
        $controlId = (int) array_key_first($controls);

        $party = $this->item([
            'kind' => Rules::KIND_PARTY, 'name' => 'PHPUnit clients grands comptes', 'party_type' => 'customer',
            'expectations' => 'Notification d\'incident sous 24 h.', 'relevance' => Rules::RELEVANCE_RETAINED,
            ContextItem::LINKS_MARKER => 1,
            '_linked_risks'       => [$riskId, 999999],
            '_linked_obligations' => [$obligationId],
            '_linked_controls'    => [$controlId],
        ]);
        $id = (int) $party->getID();
        $this->assertSame([$riskId], ContextItem::getLinkedIds($id, Risk::class), 'Élément inexistant non lié.');
        $this->assertSame([$obligationId], ContextItem::getLinkedIds($id, ComplianceObligation::class));
        $this->assertSame([$controlId], ContextItem::getLinkedIds($id, Control::class));

        // Sans le marqueur, une mise à jour ne touche pas aux liens.
        $party->update(['id' => $id, 'comment' => 'Revu.']);
        $this->assertSame([$riskId], ContextItem::getLinkedIds($id, Risk::class));

        // Sans le droit des registres sensibles : les risques ne sont ni proposés ni modifiables.
        $saved = $_SESSION['glpiactiveprofile'][Risk::RIGHTNAME];
        try {
            $_SESSION['glpiactiveprofile'][Risk::RIGHTNAME] = 0;
            $this->assertSame([], ContextItem::linkChoices(Risk::class));
            $party->update(['id' => $id, ContextItem::LINKS_MARKER => 1, '_linked_obligations' => [$obligationId]]);
            $this->assertSame([$riskId], ContextItem::getLinkedIds($id, Risk::class), 'Lien invisible conservé.');
        } finally {
            $_SESSION['glpiactiveprofile'][Risk::RIGHTNAME] = $saved;
        }

        // Liste vide envoyée avec le marqueur : liens retirés.
        $party->update(['id' => $id, ContextItem::LINKS_MARKER => 1, '_linked_obligations' => [$obligationId]]);
        $this->assertSame([], ContextItem::getLinkedIds($id, Risk::class));

        // Obligation supprimée définitivement : plus citée.
        (new ComplianceObligation())->delete(['id' => $obligationId], true);
        $this->assertSame([], ContextItem::getLinkedIds($id, ComplianceObligation::class));

        // Fiche supprimée définitivement : ses liens disparaissent.
        $this->assertTrue($party->delete(['id' => $id], true));
        $this->assertSame([], ContextItem::getLinkedIds($id, Control::class));
    }

    public function testClimateQuestionGatesRequirement41(): void
    {
        global $DB;

        $DB->delete(ContextItem::getTable(), ['category' => Rules::CATEGORY_CLIMATE]);
        $this->setRequirementStatus('4.1', IsmsClauseCatalog::STATUS_IN_PROGRESS);
        $requirement = $this->requirement('4.1');
        $this->assertFalse(ContextItem::isClimateAnswered());
        $this->assertFalse(
            $requirement->update(['id' => $requirement->getID(), 'status' => IsmsClauseCatalog::STATUS_COMPLIANT]),
            '4.1 ne passe pas à Conforme sans réponse sur le climat.'
        );
        $this->assertFalse(ContextItem::climateStatus()['answered']);
        $this->assertStringContainsString('category=climate_change', ContextItem::climateStatus()['url']);

        $this->assertFalse((new ContextItem())->add([
            'kind' => Rules::KIND_ISSUE, 'category' => Rules::CATEGORY_CLIMATE, 'name' => 'Changement climatique',
            'relevance' => Rules::RELEVANCE_RETAINED, 'entities_id' => 0,
        ]), 'Réponse sans justification refusée.');
        $climate = $this->item([
            'kind' => Rules::KIND_ISSUE, 'category' => Rules::CATEGORY_CLIMATE, 'name' => 'Changement climatique',
            'relevance' => Rules::RELEVANCE_NOT_RETAINED,
            'justification' => 'Activité de bureau, hébergement chez un prestataire hors zone inondable.',
        ]);
        $this->assertTrue(ContextItem::isClimateAnswered());
        $this->assertTrue(ContextItem::climateStatus()['answered']);
        $requirement->getFromDB($requirement->getID());
        $this->assertTrue(
            $requirement->update(['id' => $requirement->getID(), 'status' => IsmsClauseCatalog::STATUS_COMPLIANT])
        );

        // À la corbeille, la réponse ne compte plus.
        $climate->delete(['id' => $climate->getID()]);
        $this->assertFalse(ContextItem::isClimateAnswered());
    }

    public function testEntitiesAndRights(): void
    {
        $childId = $this->createTestEntity(0, 'PHPUnit filiale contexte');
        $child = $this->item([
            'kind' => Rules::KIND_ISSUE, 'name' => 'PHPUnit enjeu de la filiale', 'entities_id' => $childId,
        ]);
        $root = $this->item(['kind' => Rules::KIND_ISSUE, 'name' => 'PHPUnit enjeu du siège']);

        $saved = [
            $_SESSION['glpiactiveentities'], $_SESSION['glpiactiveentities_string'], $_SESSION['glpiactive_entity'],
        ];
        try {
            // Session limitée au siège (sans les sous-entités) : rien de la filiale.
            $_SESSION['glpiactiveentities'] = [0];
            $_SESSION['glpiactiveentities_string'] = "'0'";
            $this->assertTrue((new ContextItem())->can($root->getID(), READ));
            $this->assertFalse((new ContextItem())->can($child->getID(), READ));
            $this->assertFalse((new ContextItem())->can($child->getID(), UPDATE));
            $issues = ContextItem::summary()['issues'];

            $_SESSION['glpiactiveentities'] = [0, $childId];
            $_SESSION['glpiactiveentities_string'] = "'0','" . $childId . "'";
            $this->assertSame($issues + 1, ContextItem::summary()['issues'], 'La filiale compte dans sa session.');

            // Jamais déplacée vers une entité hors de la session.
            $_SESSION['glpiactiveentities'] = [$childId];
            $_SESSION['glpiactiveentities_string'] = "'" . $childId . "'";
            $_SESSION['glpiactive_entity'] = $childId;
            $child->update(['id' => $child->getID(), 'entities_id' => 0]);
            $child->getFromDB($child->getID());
            $this->assertSame($childId, (int) $child->fields['entities_id']);
        } finally {
            $_SESSION['glpiactiveentities'] = $saved[0];
            $_SESSION['glpiactiveentities_string'] = $saved[1];
            $_SESSION['glpiactive_entity'] = $saved[2];
        }

        $savedRight = $_SESSION['glpiactiveprofile'][ContextItem::RIGHTNAME];
        try {
            $_SESSION['glpiactiveprofile'][ContextItem::RIGHTNAME] = 0;
            $this->assertFalse(ContextItem::canView());
            $this->assertFalse(ContextItem::canCreate());
            $this->assertFalse((new ContextItem())->can($root->getID(), READ));
            $this->assertFalse(ContextItem::getMenuContent());
            $this->assertNull(ContextItem::clauseReadiness('4.1'));
        } finally {
            $_SESSION['glpiactiveprofile'][ContextItem::RIGHTNAME] = $savedRight;
        }
    }

    public function testSummaryForManagementReview(): void
    {
        $before = ContextItem::summary('2000-01-01');
        $this->item([
            'kind' => Rules::KIND_PARTY, 'name' => 'PHPUnit autorité de protection des données',
            'party_type' => 'authority', 'review_date' => '2020-01-15',
        ]);
        $after = ContextItem::summary('2000-01-01');
        $this->assertSame($before['parties'] + 1, $after['parties']);
        $this->assertSame($before['changed'] + 1, $after['changed']);
        $this->assertSame($before['overdue'] + 1, $after['overdue'], 'Date de revue passée.');
        $this->assertSame(0, ContextItem::summary('2999-01-01')['changed']);

        $first = new ManagementReview();
        $firstId = (int) $first->add(['title' => 'PHPUnit revue 2025', 'review_date' => '2025-03-01']);
        $this->assertGreaterThan(0, $firstId);
        $second = new ManagementReview();
        $second->add(['title' => 'PHPUnit revue 2026', 'review_date' => '2026-03-01']);
        $previous = (string) $second->previousReviewDate();
        $this->assertGreaterThanOrEqual('2025-03-01', $previous);
        $this->assertLessThan('2026-03-01', $previous);

        $readiness = ContextItem::clauseReadiness('4.2');
        $this->assertIsArray($readiness);
        $this->assertSame('4.2', $readiness['code']);
        $this->assertNull(ContextItem::clauseReadiness('6.1.2'));
    }

    public function testFormAndListRender(): void
    {
        $party = $this->item(['kind' => Rules::KIND_PARTY, 'name' => 'PHPUnit salariés', 'party_type' => 'employee']);
        ob_start();
        $party->showForm($party->getID());
        $html = (string) ob_get_clean();
        $this->assertStringContainsString(__('Needs and expectations', 'grcmanager'), $html);
        $this->assertStringContainsString(ContextItem::LINKS_MARKER, $html);

        ob_start();
        (new ContextItem())->showForm(0, ['kind' => Rules::KIND_ISSUE, 'category' => Rules::CATEGORY_CLIMATE]);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString(__('Is climate change relevant?', 'grcmanager'), $html);

        ob_start();
        ContextItem::showOverview();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString(__('Add an interested party', 'grcmanager'), $html);
        $this->assertStringContainsString('contextitem.export.php?format=pdf', $html);
    }

    public function testRecordedReviewIsTraced(): void
    {
        $party = $this->item([
            'kind' => Rules::KIND_PARTY, 'name' => 'PHPUnit assureur cyber', 'party_type' => 'insurer',
            'review_date' => '2020-06-30',
            // Jamais saisi à la main, même à la création.
            'last_review_date' => '2001-01-01', 'users_id_lastreviewer' => 99,
        ]);
        $this->assertNull($party->fields['last_review_date']);
        $this->assertSame(0, (int) $party->fields['users_id_lastreviewer']);

        $this->assertTrue($party->update(['id' => $party->getID(), 'last_review_date' => '2001-01-01']));
        $party->getFromDB($party->getID());
        $this->assertNull($party->fields['last_review_date'], 'Date de revue non modifiable à la main.');

        $this->assertTrue($party->update(['id' => $party->getID(), ContextItem::REVIEW_MARKER => 1]));
        $party->getFromDB($party->getID());
        $today = date('Y-m-d');
        $this->assertSame($today, $party->fields['last_review_date']);
        $this->assertSame((int) \Session::getLoginUserID(), (int) $party->fields['users_id_lastreviewer']);
        $this->assertSame(Rules::nextReviewDate($today), $party->fields['review_date']);

        ob_start();
        $party->showForm($party->getID());
        $html = (string) ob_get_clean();
        $this->assertStringContainsString(ContextItem::REVIEW_MARKER, $html);
    }

    public function testTabOnLinkedItemsAndPreselection(): void
    {
        $riskId = (int) (new Risk())->add(['title' => 'PHPUnit risque fuite chez un client', 'entities_id' => 0]);
        $risk = new Risk();
        $risk->getFromDB($riskId);
        $this->assertSame([], ContextItem::linkedTo($risk));

        $party = $this->item([
            'kind' => Rules::KIND_PARTY, 'name' => 'PHPUnit clients du secteur public', 'party_type' => 'customer',
            ContextItem::LINKS_MARKER => 1, '_linked_risks' => [$riskId],
        ]);
        $linked = ContextItem::linkedTo($risk);
        $this->assertCount(1, $linked);
        $this->assertSame((int) $party->getID(), (int) $linked[0]['id']);
        $this->assertStringContainsString(
            __('Context of the organization', 'grcmanager'),
            (string) (new ContextItem())->getTabNameForItem($risk)
        );

        ob_start();
        $this->assertTrue(ContextItem::displayTabContentForItem($risk));
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('PHPUnit clients du secteur public', $html);
        $this->assertStringContainsString('link_type=risk&amp;link_id=' . $riskId, $html);

        // Formulaire de création ouvert depuis l'onglet : le risque est présélectionné.
        ob_start();
        (new ContextItem())->showForm(0, [
            'kind' => Rules::KIND_ISSUE, 'link_type' => 'risk', 'link_id' => $riskId,
        ]);
        $html = (string) ob_get_clean();
        $this->assertMatchesRegularExpression('/<option[^>]*value="' . $riskId . '"[^>]*selected/', $html);

        // À la corbeille, la fiche n'est plus citée ; sans droit, rien.
        $party->delete(['id' => $party->getID()]);
        $this->assertSame([], ContextItem::linkedTo($risk));
        $party->restore(['id' => $party->getID()]);
        $saved = $_SESSION['glpiactiveprofile'][ContextItem::RIGHTNAME];
        try {
            $_SESSION['glpiactiveprofile'][ContextItem::RIGHTNAME] = 0;
            $this->assertSame([], ContextItem::linkedTo($risk));
            $this->assertSame('', (new ContextItem())->getTabNameForItem($risk));
            $this->assertFalse(ContextItem::displayTabContentForItem($risk));
        } finally {
            $_SESSION['glpiactiveprofile'][ContextItem::RIGHTNAME] = $saved;
        }
    }

    public function testExportForTheAuditor(): void
    {
        $riskId = (int) (new Risk())->add(['title' => 'PHPUnit risque export contexte', 'entities_id' => 0]);
        $this->item([
            'kind' => Rules::KIND_PARTY, 'name' => '=PHPUnit partie formule', 'party_type' => 'partner',
            'expectations' => 'Notification sous 24 h.', ContextItem::LINKS_MARKER => 1,
            '_linked_risks' => [$riskId],
        ]);
        $this->item([
            'kind' => Rules::KIND_ISSUE, 'name' => 'PHPUnit enjeu export', 'category' => 'technological',
        ]);

        $rows = ContextItem::csvRows();
        $this->assertSame(__('Needs and expectations', 'grcmanager'), $rows[0][5]);
        $flat = implode(PHP_EOL, array_map(static fn (array $r): string => implode(';', $r), $rows));
        $this->assertStringContainsString("'=PHPUnit partie formule", $flat, 'Formule neutralisée.');
        $this->assertStringContainsString('PHPUnit risque export contexte', $flat);
        $this->assertStringContainsString('PHPUnit enjeu export', $flat);

        // Sans le droit des registres sensibles, le risque lié n'est pas nommé.
        $saved = $_SESSION['glpiactiveprofile'][Risk::RIGHTNAME];
        try {
            $_SESSION['glpiactiveprofile'][Risk::RIGHTNAME] = 0;
            $flat = implode(PHP_EOL, array_map(
                static fn (array $r): string => implode(';', $r),
                ContextItem::csvRows()
            ));
            $this->assertStringNotContainsString('PHPUnit risque export contexte', $flat);
        } finally {
            $_SESSION['glpiactiveprofile'][Risk::RIGHTNAME] = $saved;
        }

        $html = ContextItem::reportHtml();
        $this->assertStringContainsString('PHPUnit enjeu export', $html);
        $this->assertStringContainsString(__('Interested parties', 'grcmanager'), $html);
        $this->assertStringContainsString('(4.2)', $html);
    }
}

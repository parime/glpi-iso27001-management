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

use GlpiPlugin\Grcmanager\AcnRequirement;
use GlpiPlugin\Grcmanager\EntityPerimeter;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;

/**
 * Issue #703 : registre NIS2 Italia - ACN par entité GLPI. Données fictives ; assertions sur les
 * codes et les compteurs, jamais sur des libellés traduits (la CI tourne en anglais, l'instance de
 * dev en français).
 */
final class AcnRequirementTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $savedSession = [];

    /** @var list<string> */
    private array $savedFrameworks = [];

    private int $italy = 0;

    private int $site = 0;

    private int $other = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['glpiactiveentities', 'glpiactiveentities_string', 'glpiactive_entity', 'glpiparententities'] as $key) {
            $this->savedSession[$key] = $_SESSION[$key] ?? null;
        }
        $this->savedFrameworks = FrameworkConfig::enabled();
        FrameworkConfig::save(array_merge($this->savedFrameworks, [FrameworkSelection::ACN_IT]));

        $_SESSION['glpiactive_entity'] = 0;
        $this->italy = $this->createTestEntity(0, 'PHPUnit Filiale Italia');
        $this->site = $this->createTestEntity($this->italy, 'PHPUnit Sito Milano');
        $this->other = $this->createTestEntity(0, 'PHPUnit Filiale Altra');
        EntityPerimeter::reset();
        AcnRequirement::resetCategories();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            if ($value === null) {
                unset($_SESSION[$key]);
            } else {
                $_SESSION[$key] = $value;
            }
        }
        FrameworkConfig::save($this->savedFrameworks);
        EntityPerimeter::reset();
        AcnRequirement::resetCategories();

        parent::tearDown();
    }

    /**
     * @param list<int> $parents entités parentes (vue d'une sous-entité)
     */
    private function limitTo(int $entityId, array $parents = [0]): void
    {
        $_SESSION['glpiactive_entity'] = $entityId;
        $_SESSION['glpiactiveentities'] = [$entityId];
        $_SESSION['glpiactiveentities_string'] = (string) $entityId;
        $_SESSION['glpiparententities'] = $entityId === 0 ? [] : $parents;
        EntityPerimeter::reset();
    }

    private function pointId(int $entityId, string $code, int $point): int
    {
        $requirement = new AcnRequirement();
        $this->assertTrue($requirement->getFromDBByCrit(['entities_id' => $entityId, 'code' => $code, 'point' => $point]));

        return (int) $requirement->getID();
    }

    public function testClassificationCreatesTheWholeCatalogOnceAndKeepsEntries(): void
    {
        $this->assertSame(116, AcnRequirement::setCategory($this->italy, 'important'));
        $this->assertSame('important', AcnRequirement::categoryOf($this->italy));
        $this->assertCount(87, AcnRequirement::registerRows($this->italy));

        $id = $this->pointId($this->italy, 'DE.CM-01', 2);
        $this->assertTrue((new AcnRequirement())->update([
            'id' => $id, 'implementation_status' => 'implemented', 'comment' => 'SOC esterno fittizio.',
        ]));

        // Passage en essentielle : rien n'est recréé, la saisie reste, le catalogue s'élargit.
        $this->assertSame(0, AcnRequirement::setCategory($this->italy, 'essential'));
        $this->assertCount(116, AcnRequirement::registerRows($this->italy));
        $requirement = new AcnRequirement();
        $requirement->getFromDB($id);
        $this->assertSame('implemented', $requirement->fields['implementation_status']);

        // Retrait : registre masqué, points conservés.
        $this->assertSame(0, AcnRequirement::setCategory($this->italy, ''));
        $this->assertSame([], AcnRequirement::registerRows($this->italy));
        $this->assertSame(116, countElementsInTable(AcnRequirement::getTable(), ['entities_id' => $this->italy]));
    }

    public function testSubEntityFollowsItsClosestClassifiedParent(): void
    {
        AcnRequirement::setCategory($this->italy, 'essential');

        $this->assertSame($this->italy, AcnRequirement::registerEntity($this->site));
        $this->assertNull(AcnRequirement::registerEntity($this->other));

        $this->limitTo($this->site, [0, $this->italy]);
        $requirement = new AcnRequirement();
        $this->assertTrue($requirement->can($this->pointId($this->italy, 'GV.PO-01', 1), READ), 'Récursif.');
        $this->assertSame([$this->italy], array_keys(AcnRequirement::availableRegisters()));
    }

    public function testAnotherEntityCannotReadTheRegister(): void
    {
        AcnRequirement::setCategory($this->italy, 'important');
        $id = $this->pointId($this->italy, 'GV.PO-01', 1);

        $this->limitTo($this->other);
        $this->assertFalse((new AcnRequirement())->can($id, READ));
        $this->assertSame([], AcnRequirement::availableRegisters());
        $this->assertNull(AcnRequirement::selectedRegister(AcnRequirement::availableRegisters(), $this->italy));
    }

    public function testInputIsValidatedAndIdentityIsFixed(): void
    {
        AcnRequirement::setCategory($this->italy, 'important');
        $id = $this->pointId($this->italy, 'PR.DS-01', 1);
        $requirement = new AcnRequirement();

        $this->assertFalse($requirement->update(['id' => $id, 'applicability' => 'no', 'justification' => '']));
        $this->assertTrue($requirement->update([
            'id' => $id, 'applicability' => 'no', 'justification' => 'Nessun dispositivo portatile (fittizio).',
            'code' => 'GV.OC-04', 'point' => 9, 'entities_id' => $this->other, 'implementation_status' => 'bogus',
            'due_date' => '', 'approval_date' => '2026-05-04',
        ]));
        $requirement->getFromDB($id);
        $this->assertSame('PR.DS-01', $requirement->fields['code']);
        $this->assertSame(1, (int) $requirement->fields['point']);
        $this->assertSame($this->italy, (int) $requirement->fields['entities_id']);
        $this->assertSame('not_started', $requirement->fields['implementation_status']);
        $this->assertNull($requirement->fields['due_date']);

        // Point hors catalogue : jamais créé.
        $this->assertFalse((new AcnRequirement())->add(['entities_id' => $this->italy, 'code' => 'XX.YY-01', 'point' => 1]));
        $this->assertFalse(AcnRequirement::canCreate());
        $this->assertFalse(AcnRequirement::canPurge());
    }

    public function testDashboardAndExportsUseTheRegister(): void
    {
        AcnRequirement::setCategory($this->italy, 'essential');
        $id = $this->pointId($this->italy, 'RS.MA-01', 2);
        (new AcnRequirement())->update(['id' => $id, 'implementation_status' => 'verified', 'approval_date' => '2026-04-01']);

        $dashboard = AcnRequirement::dashboard($this->italy);
        $this->assertSame(2, $dashboard['annex']);
        $this->assertSame(43, $dashboard['measures']);
        $this->assertSame(116, $dashboard['summary']['total']);
        $this->assertSame(1, $dashboard['summary']['done']);
        $this->assertSame(9, $dashboard['summary']['approvals_required']);
        $this->assertSame(8, $dashboard['summary']['approvals_missing']);
        $this->assertSame(['GV', 'ID', 'PR', 'DE', 'RS', 'RC'], array_column($dashboard['functions'], 'code'));

        $csv = AcnRequirement::csvRows($this->italy);
        $this->assertCount(117, $csv, 'En-tête et 116 points.');
        $this->assertSame('GV.OC-04, punto 1', $csv[1][0]);
        $this->assertContains('RS.MA-01, punto 2', array_column($csv, 0));

        $html = AcnRequirement::reportHtml($this->italy);
        $this->assertStringContainsString('DE.CM-01, punto 7', $html);
        $this->assertStringContainsString('379907/2025', $html);
    }

    public function testPoliciesAreLinkedAndForgottenOnPurge(): void
    {
        AcnRequirement::setCategory($this->italy, 'important');
        $id = $this->pointId($this->italy, 'GV.PO-01', 3);
        $policy = new Policy();
        $policyId = (int) $policy->add(['title' => 'PHPUnit politica di sicurezza fittizia', 'entities_id' => 0]);
        $this->assertGreaterThan(0, $policyId);

        (new AcnRequirement())->update([
            'id' => $id, AcnRequirement::LINKS_MARKER => 1, '_linked_policies' => [$policyId, 999999999],
        ]);
        $this->assertSame([$policyId], AcnRequirement::getLinkedPolicyIds($id));
        $this->assertSame([$policyId], array_column(AcnRequirement::linkedPolicies($id), 'id'));

        // Une mise à jour sans le marqueur ne touche pas aux liens.
        (new AcnRequirement())->update(['id' => $id, 'comment' => 'Nota fittizia.']);
        $this->assertSame([$policyId], AcnRequirement::getLinkedPolicyIds($id));

        $policy->delete(['id' => $policyId], true);
        $this->assertSame([], AcnRequirement::getLinkedPolicyIds($id));
    }

    public function testDisabledFrameworkHidesTheRegister(): void
    {
        FrameworkConfig::save(array_values(array_diff(FrameworkConfig::enabled(), [FrameworkSelection::ACN_IT])));
        $this->assertFalse(AcnRequirement::canView());
        $this->assertFalse(AcnRequirement::canUpdate());
    }
}

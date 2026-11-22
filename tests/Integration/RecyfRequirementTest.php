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

use GlpiPlugin\Grcmanager\EntityPerimeter;
use GlpiPlugin\Grcmanager\FrameworkAssessment;
use GlpiPlugin\Grcmanager\RecyfRequirement;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Recyf\RecyfCatalogDefaults;

/**
 * Issue #599 : registre ReCyF (NIS2 France) par entité GLPI et évaluation de NIS2 sur ses mesures.
 * Données fictives ; assertions sur les numéros et les compteurs, jamais sur des libellés traduits
 * (la CI tourne en anglais, l'instance de dev en français).
 */
final class RecyfRequirementTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $savedSession = [];

    /** @var list<string> */
    private array $savedFrameworks = [];

    private int $france = 0;

    private int $site = 0;

    private int $other = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $keys = ['glpiactiveentities', 'glpiactiveentities_string', 'glpiactive_entity', 'glpiparententities'];
        foreach ($keys as $key) {
            $this->savedSession[$key] = $_SESSION[$key] ?? null;
        }
        // Issue #1053 : ReCyF est la déclinaison France de NIS2, cochée en plus de NIS2.
        $this->savedFrameworks = FrameworkConfig::storedEnabled();
        FrameworkConfig::save(array_values(array_unique(array_merge(
            $this->savedFrameworks,
            [FrameworkSelection::NIS2, FrameworkSelection::RECYF]
        )))
        );

        // Catégories de l'instance écartées (annulé avec la transaction) : registres de ce test seulement.
        global $DB;
        $DB->delete(RecyfRequirement::ENTITIES_TABLE, ['id' => ['>', 0]]);

        $_SESSION['glpiactive_entity'] = 0;
        $this->france = $this->createTestEntity(0, 'PHPUnit Filiale France');
        $this->site = $this->createTestEntity($this->france, 'PHPUnit Site Lyon');
        // Sous-entité : ses ancêtres comprennent la filiale (droits sur les fiches récursives).
        $DB->update(
            'glpi_entities',
            ['level' => 2, 'ancestors_cache' => json_encode([0 => 0, $this->france => $this->france])],
            ['id' => $this->site]
        );
        $this->other = $this->createTestEntity(0, 'PHPUnit Filiale Autre');
        EntityPerimeter::reset();
        RecyfRequirement::resetCategories();
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
        RecyfRequirement::resetCategories();

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

    private function measureId(int $entityId, string $code): int
    {
        $requirement = new RecyfRequirement();
        $this->assertTrue($requirement->getFromDBByCrit(['entities_id' => $entityId, 'code' => $code]));

        return (int) $requirement->getID();
    }

    public function testClassificationCreatesTheWholeCatalogOnceAndKeepsEntries(): void
    {
        $this->assertSame(152, RecyfRequirement::setCategory($this->france, 'important'));
        $this->assertSame('important', RecyfRequirement::categoryOf($this->france));
        $this->assertCount(76, RecyfRequirement::registerRows($this->france));

        $id = $this->measureId($this->france, '10.A.6');
        $this->assertTrue((new RecyfRequirement())->update([
            'id' => $id, 'implementation_status' => 'implemented', 'comment' => 'Revue annuelle fictive.',
        ]));

        // Passage en essentielle : rien n'est recréé, la saisie reste, le catalogue s'élargit.
        $this->assertSame(0, RecyfRequirement::setCategory($this->france, 'essential'));
        $this->assertCount(152, RecyfRequirement::registerRows($this->france));
        $requirement = new RecyfRequirement();
        $requirement->getFromDB($id);
        $this->assertSame('implemented', $requirement->fields['implementation_status']);

        // Retrait : registre masqué, fiches conservées.
        $this->assertSame(0, RecyfRequirement::setCategory($this->france, ''));
        $this->assertSame([], RecyfRequirement::registerRows($this->france));
        $this->assertSame(152, countElementsInTable(RecyfRequirement::getTable(), ['entities_id' => $this->france]));
    }

    public function testSubEntityFollowsItsParentAndAnotherEntityCannotRead(): void
    {
        RecyfRequirement::setCategory($this->france, 'essential');
        $id = $this->measureId($this->france, '2.B.1');

        $this->assertSame($this->france, RecyfRequirement::registerEntity($this->site));
        $this->assertNull(RecyfRequirement::registerEntity($this->other));

        $this->limitTo($this->site, [0, $this->france]);
        $this->assertTrue((new RecyfRequirement())->can($id, READ), 'Récursif.');
        $this->assertSame([$this->france], array_keys(RecyfRequirement::availableRegisters()));

        $this->limitTo($this->other);
        $this->assertFalse((new RecyfRequirement())->can($id, READ));
        $this->assertSame([], RecyfRequirement::availableRegisters());
        $this->assertNull(RecyfRequirement::selectedRegister(RecyfRequirement::availableRegisters(), $this->france));
    }

    public function testInputIsValidatedAndIdentityIsFixed(): void
    {
        RecyfRequirement::setCategory($this->france, 'important');
        $id = $this->measureId($this->france, '8.5');
        $requirement = new RecyfRequirement();

        $this->assertFalse($requirement->update(['id' => $id, 'applicability' => 'no', 'justification' => '']));
        $this->assertTrue($requirement->update([
            'id' => $id, 'applicability' => 'no', 'justification' => 'Aucun poste nomade (fictif).',
            'code' => '1.1', 'entities_id' => $this->other, 'implementation_status' => 'bogus', 'due_date' => '',
        ]));
        $requirement->getFromDB($id);
        $this->assertSame('8.5', $requirement->fields['code']);
        $this->assertSame($this->france, (int) $requirement->fields['entities_id']);
        $this->assertSame('not_started', $requirement->fields['implementation_status']);
        $this->assertNull($requirement->fields['due_date']);

        // Mesure hors catalogue : jamais créée.
        $this->assertFalse((new RecyfRequirement())->add(['entities_id' => $this->france, 'code' => '99.1']));
        $this->assertFalse(RecyfRequirement::canCreate());
        $this->assertFalse(RecyfRequirement::canPurge());
    }

    public function testDashboardAndExportsUseTheRegister(): void
    {
        RecyfRequirement::setCategory($this->france, 'essential');
        $id = $this->measureId($this->france, '13.2');
        (new RecyfRequirement())->update(['id' => $id, 'implementation_status' => 'verified']);

        $dashboard = RecyfRequirement::dashboard($this->france);
        $this->assertSame('essential', $dashboard['category']);
        $this->assertSame(152, $dashboard['summary']['total']);
        $this->assertSame(1, $dashboard['summary']['done']);
        $this->assertSame(range(1, 20), array_column($dashboard['objectives'], 'number'));

        $csv = RecyfRequirement::csvRows($this->france);
        $this->assertCount(153, $csv, 'En-tête et 152 mesures.');
        $this->assertSame('1.1-EI/EE', $csv[1][0]);
        $this->assertContains('20.6-EE', array_column($csv, 0));

        $html = RecyfRequirement::reportHtml($this->france);
        $this->assertStringContainsString('10.B.7-EE', $html);
        $this->assertStringContainsString(RecyfCatalogDefaults::VERSION, $html);

        $card = RecyfRequirement::referentialCard();
        $this->assertSame(76, $card['important']);
        $this->assertSame(152, $card['essential']);
        $this->assertCount(20, $card['objectives']);
    }

    public function testDisabledNis2HidesTheRegister(): void
    {
        FrameworkConfig::save(array_values(array_diff(FrameworkConfig::storedEnabled(), [FrameworkSelection::NIS2])));
        $this->assertFalse(RecyfRequirement::canView());
        $this->assertFalse(RecyfRequirement::canUpdate());
    }

    /** Issue #1053 : NIS2 actif sans la déclinaison France, le registre ReCyF reste masqué. */
    public function testNis2WithoutTheFrenchImplementationHidesTheRegister(): void
    {
        FrameworkConfig::save(array_values(array_diff(FrameworkConfig::storedEnabled(), [FrameworkSelection::RECYF])));
        $this->assertTrue(FrameworkConfig::isEnabled(FrameworkSelection::NIS2));
        $this->assertFalse(RecyfRequirement::canView());
    }

    /**
     * Issue #599 (et #789) : avec un registre ReCyF accessible, NIS2 est évalué sur ses mesures,
     * même ISO 27001 désactivé, sans aucune mesure de l'Annexe A ; sans registre accessible, il
     * retombe sur la correspondance (ou sur « pas de statut propre » sans ISO 27001).
     */
    public function testNis2IsAssessedOnTheReCyfRegisterWithoutAnnexA(): void
    {
        FrameworkConfig::save([FrameworkSelection::NIS2, FrameworkSelection::RECYF]);
        RecyfRequirement::setCategory($this->france, 'important');
        $done = $this->measureId($this->france, '4.1');
        $this->assertTrue((new RecyfRequirement())->update(['id' => $done, 'implementation_status' => 'implemented']));
        $excluded = $this->measureId($this->france, '6.4');
        $this->assertTrue((new RecyfRequirement())->update([
            'id' => $excluded, 'applicability' => 'no', 'justification' => 'Pas de salle serveur (fictif).',
        ]));

        $this->limitTo($this->site, [0, $this->france]);
        $assessments = FrameworkAssessment::assessAll([]);
        $nis2 = $assessments['NIS2'];
        $this->assertSame(FrameworkSelection::NIS2, $nis2['key']);
        $this->assertSame(FrameworkAssessment::BASIS_OWN, $nis2['basis']);
        $this->assertSame(FrameworkAssessment::CATALOG_RECYF, $nis2['catalog']);
        $this->assertSame(75, $nis2['applicable']);
        $this->assertSame(1, $nis2['done']);
        $this->assertSame(0, $nis2['compliant'], 'Mise en œuvre sans preuve : écart.');
        $codes = array_column($nis2['gaps'], 'code');
        $this->assertContains('4.1-EI/EE', $codes);
        $this->assertNotContains('6.4-EI/EE', $codes, 'Non applicable.');
        foreach ($codes as $code) {
            $this->assertStringEndsWith('EE', (string) $code, 'Mesure ReCyF, pas mesure ISO.');
        }
        $detail = FrameworkAssessment::detail($assessments, FrameworkSelection::NIS2);
        $this->assertNotNull($detail);
        $this->assertStringContainsString('recyfrequirement.php', (string) $detail['manage_url']);
        foreach ($detail['gaps'] as $gap) {
            $this->assertSame([], $gap['controls']);
            $this->assertStringContainsString('recyfrequirement.form.php?id=', (string) $gap['url']);
        }

        // Autre entité, sans registre : pas d'évaluation sur l'Annexe A, ISO 27001 étant désactivé.
        $this->limitTo($this->other);
        $nis2 = FrameworkAssessment::assessAll([])['NIS2'];
        $this->assertSame(FrameworkAssessment::BASIS_NONE, $nis2['basis']);
        $this->assertArrayNotHasKey('catalog', $nis2);
    }
}

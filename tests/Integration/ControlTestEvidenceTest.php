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

use Computer;
use Document;
use Document_Item;
use GlpiPlugin\Grcmanager\ComplianceAudit;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\RestrictedDocument;
use GlpiPlugin\Grcmanager\Services\Compliance\ComplianceAuditScore;
use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecuredDashboardCards;

/**
 * Issue #380, troisième étape : preuve envoyée depuis le formulaire du test, indicateur des tests
 * en retard et tests alimentés par les contrôles techniques automatiques (#162).
 */
final class ControlTestEvidenceTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $session = [];

    /** @var list<string> */
    private array $files = [];

    /** @var list<int> */
    private array $documents = [];

    private int $maxSize = 0;

    protected function setUp(): void
    {
        global $CFG_GLPI;

        parent::setUp();
        $this->session = $_SESSION;
        $_SESSION['glpiactive_entity'] ??= 0;
        $this->maxSize = (int) $CFG_GLPI['document_max_size'];
    }

    protected function tearDown(): void
    {
        global $CFG_GLPI;

        $_SESSION = $this->session;
        $CFG_GLPI['document_max_size'] = $this->maxSize;
        // Le fichier rangé par GLPI survivrait au retour en arrière de la base.
        foreach ($this->documents as $documentsId) {
            (new Document())->delete(['id' => $documentsId], true);
        }
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function control(string $code): Control
    {
        $control = new Control();
        $this->assertTrue($control->getFromDBByCrit(['code' => $code]));

        return $control;
    }

    private function file(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'grc');
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    private function pdf(): string
    {
        return $this->file("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    private function store(Control $control, string $path, string $name): int
    {
        $documentsId = ControlTest::storeEvidenceFile($control, $path, $name);
        if ($documentsId > 0) {
            $this->documents[] = $documentsId;
        }

        return $documentsId;
    }

    public function testUploadedEvidenceIsADocumentOfTheControlReadableOnlyByItsReaders(): void
    {
        $control = $this->control('A.8.13');
        $documentsId = $this->store($control, $this->pdf(), '../../etc/Rapport de sauvegarde.pdf');
        $this->assertGreaterThan(0, $documentsId);

        $document = new Document();
        $this->assertTrue($document->getFromDB($documentsId));
        $this->assertSame('Rapport de sauvegarde.pdf', $document->fields['filename'], 'aucun chemin conservé');
        $this->assertSame('application/pdf', $document->fields['mime']);
        $this->assertStringNotContainsString('..', (string) $document->fields['filepath']);
        $this->assertSame(1, countElementsInTable(Document_Item::getTable(), [
            'documents_id' => $documentsId,
            'itemtype'     => Control::class,
            'items_id'     => $control->getID(),
        ]));
        $this->assertSame(
            ['itemtype' => Control::class, 'items_id' => $control->getID()],
            RestrictedDocument::sourceOf($documentsId)
        );

        // Le test cite la preuve envoyée.
        $testsId = (int) (new ControlTest())->add([
            'plugin_grcmanager_controls_id' => $control->getID(),
            'result'                        => 'effective',
            'documents_id'                  => $documentsId,
        ]);
        $this->assertGreaterThan(0, $testsId);

        // Droit Documents de GLPI sans lecture des mesures : document refusé.
        $this->assertTrue($document->can($documentsId, READ));
        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = 0;
        $this->assertFalse((new Document())->can($documentsId, READ));
    }

    public function testDisguisedOrActiveContentIsRefused(): void
    {
        $control = $this->control('A.8.13');
        $before = countElementsInTable(Document::getTable());

        $this->assertSame(0, $this->store($control, $this->file("<?php echo 'x';\n"), 'rapport.pdf'));
        $this->assertSame(0, $this->store(
            $control,
            $this->file("<html><body><script>alert(1)</script></body></html>\n"),
            'notes.txt'
        ));
        $this->assertSame(0, $this->store($control, $this->pdf(), 'sans-extension'));
        $this->assertSame(0, $this->store($control, $this->pdf(), 'programme.exe'));
        $this->assertSame($before, countElementsInTable(Document::getTable()));
    }

    public function testGlpiSizeLimitAndDocumentsTabRightsApply(): void
    {
        global $CFG_GLPI;

        $control = $this->control('A.8.13');
        $CFG_GLPI['document_max_size'] = 1;
        $this->assertSame(0, $this->store($control, $this->file(str_repeat("ligne de journal\n", 70000)), 'journal.txt'));
        $CFG_GLPI['document_max_size'] = $this->maxSize;
        $this->assertGreaterThan(0, $this->store($control, $this->file("ligne de journal\n"), 'journal.txt'));

        // Onglet Documents : pas de création de document sans le droit de GLPI.
        $_SESSION['glpiactiveprofile']['document'] = READ;
        $this->assertSame(0, $this->store($control, $this->pdf(), 'preuve.pdf'));
    }

    public function testTabOffersTheUploadOnlyWithTheDocumentsRight(): void
    {
        $control = $this->control('A.8.13');
        ob_start();
        ControlTest::displayTabContentForItem($control);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('name="evidence_file"', $html);
        $this->assertStringContainsString('multipart/form-data', $html);

        $_SESSION['glpiactiveprofile']['document'] = READ;
        ob_start();
        ControlTest::displayTabContentForItem($control);
        $this->assertStringNotContainsString('name="evidence_file"', (string) ob_get_clean());
    }

    public function testOverdueTestsCardFollowsTheControlsRight(): void
    {
        global $DB;

        $before = SecuredDashboardCards::overdueControlTests()['number'];
        $control = $this->control('A.7.4');
        $DB->update(Control::getTable(), ['applicability' => 'yes'], ['id' => $control->getID()]);
        (new ControlTest())->add([
            'plugin_grcmanager_controls_id' => $control->getID(),
            'test_date'                     => date('Y-m-d', strtotime('-2 years')),
            'next_test_date'                => date('Y-m-d', strtotime('-1 year')),
            'result'                        => 'effective',
        ]);
        $card = SecuredDashboardCards::overdueControlTests();
        $this->assertSame($before + 1, $card['number']);
        $this->assertStringContainsString('controltest.php', $card['url']);

        $_SESSION['glpiactiveprofile']['plugin_grcmanager'] = 0;
        $this->assertFalse(SecuredDashboardCards::canShow('overdueControlTests'));
        $this->assertSame(0, SecuredDashboardCards::overdueControlTests()['number']);
    }

    public function testRunCheckMatchesTheTechnicalAuditPage(): void
    {
        $all = ComplianceAudit::run();
        foreach (array_keys(ComplianceAuditScore::CHECKS) as $check) {
            $this->assertEquals($all[$check], ComplianceAudit::runCheck($check), $check);
        }
        $this->assertFalse(ComplianceAudit::runCheck('inconnu')['evaluated']);
    }

    public function testTechnicalCheckFeedsAnEffectivenessTest(): void
    {
        // Un poste sans antivirus : écart du contrôle « antivirus » (A.8.7).
        $computersId = (int) (new Computer())->add(['name' => 'PC fictif sans antivirus', 'entities_id' => 0]);
        $this->assertGreaterThan(0, $computersId);
        $control = $this->control('A.8.7');
        $outcome = ComplianceAudit::runCheck('antivirus');
        $this->assertGreaterThan(0, $outcome['total']);

        $testsId = ControlTest::recordTechnicalCheck($control, 'antivirus');
        $this->assertGreaterThan(0, $testsId);
        $test = new ControlTest();
        $this->assertTrue($test->getFromDB($testsId));
        $this->assertSame('technical', $test->fields['method']);
        $this->assertSame(
            ControlTestRules::resultFromTechnicalCheck(count($outcome['gaps']), $outcome['total']),
            $test->fields['result']
        );
        $this->assertStringContainsString((string) $outcome['total'], (string) $test->fields['comment']);

        // Contrôle sans rapport avec la mesure : refusé.
        $this->assertSame(0, ControlTest::recordTechnicalCheck($control, 'encryption'));
        $this->assertSame(0, ControlTest::recordTechnicalCheck($this->control('A.5.1'), 'antivirus'));

        // L'onglet de la mesure propose le contrôle ; celui d'une mesure sans contrôle, non.
        ob_start();
        ControlTest::displayTabContentForItem($control);
        $this->assertStringContainsString('value="antivirus"', (string) ob_get_clean());
        ob_start();
        ControlTest::displayTabContentForItem($this->control('A.5.1'));
        $this->assertStringNotContainsString('name="record_check"', (string) ob_get_clean());
    }
}

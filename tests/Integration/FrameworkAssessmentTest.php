<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use Document_Item;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\Evidence;
use GlpiPlugin\Grcmanager\FrameworkAssessment;
use GlpiPlugin\Grcmanager\Services\Compliance\FrameworkAssessmentRules;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;

/**
 * Issue #379 : l'évaluation par référentiel lit les documents liés aux mesures et leur validité.
 */
final class FrameworkAssessmentTest extends GrcmanagerIntegrationTestCase
{
    /**
     * Deux mesures sans aucun document, pour ne dépendre d'aucune donnée de l'instance.
     *
     * @return array<string, int>
     */
    private function controlsWithoutDocuments(): array
    {
        global $DB;

        $ids = [];
        $rows = $DB->request(['SELECT' => ['id', 'code'], 'FROM' => Control::getTable(), 'ORDER' => 'id']);
        foreach ($rows as $row) {
            $links = ['itemtype' => Control::class, 'items_id' => $row['id']];
            if (countElementsInTable(Document_Item::getTable(), $links) === 0) {
                $ids[(string) $row['code']] = (int) $row['id'];
            }
            if (count($ids) === 2) {
                break;
            }
        }
        $this->assertCount(2, $ids, 'Il faut deux mesures sans document.');

        return $ids;
    }

    private function attach(int $controlId, ?string $validUntil): void
    {
        $documentsId = (int) (new \Document())->add(['name' => 'PHPUnit preuve de mesure', 'entities_id' => 0]);
        (new Document_Item())->add([
            'documents_id' => $documentsId, 'itemtype' => Control::class, 'items_id' => $controlId,
        ]);
        if ($validUntil !== null) {
            $this->assertNotFalse((new Evidence())->add([
                'itemtype' => Control::class, 'items_id' => $controlId,
                'documents_id' => $documentsId, 'valid_until' => $validUntil,
            ]));
        }
    }

    public function testEvidenceStatusComesFromTheLinkedDocumentsAndTheirValidity(): void
    {
        $today = date('Y-m-d');
        [$expiredCode, $provenCode] = array_keys($ids = $this->controlsWithoutDocuments());
        $this->attach($ids[$expiredCode], date('Y-m-d', strtotime('-1 day')));
        $this->attach($ids[$provenCode], null);

        $controls = FrameworkAssessment::controls($today);
        $this->assertSame(FrameworkAssessmentRules::EVIDENCE_EXPIRED, $controls[$expiredCode]['evidence']);
        $this->assertSame(FrameworkAssessmentRules::EVIDENCE_PROVEN, $controls[$provenCode]['evidence']);
        $this->assertSame($ids[$provenCode], $controls[$provenCode]['id']);

        // Un document encore valide à côté de l'expiré suffit à prouver la mesure.
        $this->attach($ids[$expiredCode], date('Y-m-d', strtotime('+1 year')));
        $this->assertSame(
            FrameworkAssessmentRules::EVIDENCE_PROVEN,
            FrameworkAssessment::controls($today)[$expiredCode]['evidence']
        );
    }

    public function testOnlyEnabledFrameworksAreAssessed(): void
    {
        FrameworkConfig::save([FrameworkSelection::ISO27001, FrameworkSelection::SOC2]);
        $result = FrameworkAssessment::assessAll(FrameworkAssessment::controls(date('Y-m-d')));
        $this->assertSame(['ISO 27001', 'SOC 2'], array_keys($result));
        $this->assertSame(FrameworkSelection::SOC2, $result['SOC 2']['key']);
        // ISO 27001 recouvre toutes les mesures, SOC 2 seulement celles de sa correspondance.
        $this->assertGreaterThanOrEqual($result['SOC 2']['applicable'], $result['ISO 27001']['applicable']);
    }
}

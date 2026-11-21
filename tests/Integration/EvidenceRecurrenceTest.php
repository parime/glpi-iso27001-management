<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\Evidence;
use GlpiPlugin\Grcmanager\EvidenceRecurrence;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\Services\Evidence\EvidenceRecurrenceRules as Rules;

/**
 * Issue #886 : fraîcheur d'une preuve sur un rythme récurrent, signalée et rappelée au responsable,
 * sans jamais modifier la preuve ni l'élément porteur.
 */
final class EvidenceRecurrenceTest extends GrcmanagerIntegrationTestCase
{
    private function recurringEvidence(string $unit, int $count, string $lastCollected): Evidence
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit recurring evidence ' . random_int(1000, 9999));
        $policy = new Policy();
        $policy->getFromDB((int) $policy->add(['title' => 'PHPUnit recurring policy', 'entities_id' => $entityId]));
        $documentsId = (int) (new \Document())->add([
            'name' => 'PHPUnit weekly log review', 'entities_id' => $entityId,
        ]);
        (new \Document_Item())->add([
            'documents_id' => $documentsId,
            'itemtype'     => Policy::class,
            'items_id'     => $policy->getID(),
        ]);
        $evidence = new Evidence();
        $evidence->getFromDB((int) $evidence->add([
            'itemtype'     => Policy::class,
            'items_id'     => $policy->getID(),
            'documents_id' => $documentsId,
            'valid_until'  => '',
            'users_id'     => $this->createTestUser('Owner', 'Recurring'),
        ]));
        $this->assertGreaterThan(0, $evidence->getID());
        EvidenceRecurrence::saveFor($evidence->getID(), [
            'period_unit'    => $unit,
            'period_count'   => $count,
            'last_collected' => $lastCollected,
        ]);

        return $evidence;
    }

    /**
     * @return array<string, mixed>
     */
    private function rhythmOf(Evidence $evidence): array
    {
        return EvidenceRecurrence::forEvidences([$evidence->getID()])[$evidence->getID()] ?? [];
    }

    public function testRhythmIsSavedWithItsComputedDueDateAndRemovedWithoutUnit(): void
    {
        $evidence = $this->recurringEvidence(Rules::UNIT_WEEK, 2, '2026-01-01');
        $row = $this->rhythmOf($evidence);
        $this->assertSame('week', $row['period_unit']);
        $this->assertSame(2, (int) $row['period_count']);
        $state = EvidenceRecurrence::describe($row, null, '2026-01-10');
        $this->assertSame('2026-01-15', $state['next_due']);
        $this->assertSame(Rules::STATUS_FRESH, $state['status']);
        $this->assertSame(Rules::STATUS_STALE, EvidenceRecurrence::describe($row, null, '2026-01-16')['status']);

        // Sans date de collecte saisie, la date du document sert de référence.
        $noDate = EvidenceRecurrence::describe(
            ['period_unit' => 'day', 'period_count' => 3],
            '2026-02-01 10:00:00',
            '2026-02-02'
        );
        $this->assertSame('2026-02-04', $noDate['next_due']);

        EvidenceRecurrence::saveFor($evidence->getID(), ['period_unit' => '']);
        $this->assertSame([], $this->rhythmOf($evidence));
    }

    public function testOwnerIsRemindedOncePerDueDateAndNothingElseChanges(): void
    {
        $evidence = $this->recurringEvidence(Rules::UNIT_MONTH, 1, '2026-01-15');
        $before = $evidence->fields;
        $ticketsBefore = countElementsInTable('glpi_tickets');

        EvidenceRecurrence::runReminders('2026-02-20');
        $this->assertSame('2026-02-15', $this->rhythmOf($evidence)['notified_due'], 'Rappel pour cette échéance.');
        $modBefore = $this->rhythmOf($evidence)['date_mod'];
        EvidenceRecurrence::runReminders('2026-02-21');
        $this->assertSame($modBefore, $this->rhythmOf($evidence)['date_mod'], 'Un seul rappel par échéance.');

        // Nouvelle collecte : l'échéance avance et le rappel se réarme de lui-même.
        EvidenceRecurrence::saveFor($evidence->getID(), [
            'period_unit' => Rules::UNIT_MONTH, 'period_count' => 1, 'last_collected' => '2026-02-25',
        ]);
        EvidenceRecurrence::runReminders('2026-03-24');
        $this->assertSame('2026-03-25', $this->rhythmOf($evidence)['notified_due']);

        $evidence->getFromDB($evidence->getID());
        $this->assertSame($before['valid_until'], $evidence->fields['valid_until'], 'Preuve jamais modifiée.');
        $this->assertSame((int) $before['tickets_id'], (int) $evidence->fields['tickets_id']);
        $this->assertSame($ticketsBefore, countElementsInTable('glpi_tickets'), 'Aucun ticket ouvert.');
    }

    public function testStaleEvidenceIsListedForTheSessionAndPurgedWithItsValidity(): void
    {
        $evidence = $this->recurringEvidence(Rules::UNIT_MONTH_END, 1, '2020-01-10');
        $stale = EvidenceRecurrence::staleForSession(date('Y-m-d'))['stale'];
        $documents = array_column($stale, 'documents_id');
        $this->assertContains((int) $evidence->fields['documents_id'], $documents);
        $this->assertGreaterThanOrEqual(1, EvidenceRecurrence::staleCard()['number'], 'Carte du tableau de bord.');

        $id = $evidence->getID();
        $evidence->delete(['id' => $id], true);
        $this->assertSame([], EvidenceRecurrence::forEvidences([$id]), 'Rythme supprimé avec la validité.');
    }
}

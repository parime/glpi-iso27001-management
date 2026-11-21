<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use CommonITILObject;
use GlpiPlugin\Grcmanager\SupplierRisk;
use Supplier;
use Ticket;

/**
 * Issue #127 : ticket de réévaluation à l'échéance de la revue (le questionnaire est testé par
 * SupplierEvaluationTest, issue #864).
 */
final class SupplierAssessmentTest extends GrcmanagerIntegrationTestCase
{
    private function createRisk(string $reviewDate): \GlpiPlugin\Grcmanager\SupplierRisk
    {
        $suppliersId = (int) (new Supplier())->add(['name' => 'PHPUnit Hébergeur', 'entities_id' => 0]);
        $risk = new \GlpiPlugin\Grcmanager\SupplierRisk();
        $id = (int) $risk->add([
            'suppliers_id' => $suppliersId,
            'title'        => 'PHPUnit hébergement des données',
            'users_id'     => (int) \Session::getLoginUserID(),
            'review_date'  => $reviewDate,
        ]);
        $this->assertGreaterThan(0, $id);
        $risk->getFromDB($id);

        return $risk;
    }

    public function testAnOverdueReviewOpensASingleTicketUntilItIsSolved(): void
    {
        $risk = $this->createRisk(date('Y-m-d', strtotime('-1 day')));
        $status = $risk->fields['status'];

        \GlpiPlugin\Grcmanager\SupplierRisk::openReviewTickets();
        $risk->getFromDB($risk->getID());
        $ticketsId = (int) $risk->fields['review_tickets_id'];
        $this->assertGreaterThan(0, $ticketsId);
        $ticket = new Ticket();
        $this->assertTrue($ticket->getFromDB($ticketsId));
        $this->assertStringContainsString('PHPUnit Hébergeur', $ticket->fields['name']);
        $this->assertSame($status, $risk->fields['status'], 'statut du risque inchangé');

        \GlpiPlugin\Grcmanager\SupplierRisk::openReviewTickets();
        $risk->getFromDB($risk->getID());
        $this->assertSame($ticketsId, (int) $risk->fields['review_tickets_id'], 'pas de second ticket');

        $ticket->update(['id' => $ticketsId, 'status' => CommonITILObject::SOLVED]);
        \GlpiPlugin\Grcmanager\SupplierRisk::openReviewTickets();
        $risk->getFromDB($risk->getID());
        $this->assertNotSame($ticketsId, (int) $risk->fields['review_tickets_id'], 'nouveau ticket une fois résolu');
    }

    public function testAFutureReviewOpensNoTicket(): void
    {
        $risk = $this->createRisk(date('Y-m-d', strtotime('+10 days')));

        \GlpiPlugin\Grcmanager\SupplierRisk::openReviewTickets();

        $risk->getFromDB($risk->getID());
        $this->assertSame(0, (int) $risk->fields['review_tickets_id']);
    }
}

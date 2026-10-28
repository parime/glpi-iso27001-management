<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use CommonITILObject;
use GlpiPlugin\Grcmanager\SupplierAssessment;
use GlpiPlugin\Grcmanager\SupplierRisk;
use Supplier;
use Ticket;

/**
 * Issue #127 : questionnaire de sécurité d'un fournisseur et ticket de réévaluation à
 * l'échéance de la revue.
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

    public function testTheQuestionnaireIsSavedWithItsScore(): void
    {
        $risk = $this->createRisk(date('Y-m-d', strtotime('+1 year')));
        $level = $risk->fields['risk_level'];

        $this->assertTrue(\GlpiPlugin\Grcmanager\SupplierAssessment::save($risk, [
            'certification' => 'yes',
            'encryption'    => 'partial',
            'mfa'           => 'no',
            'audits'        => 'na',
            'unknown'       => 'yes',
        ]));

        $risk->getFromDB($risk->getID());
        $this->assertSame(50, (int) $risk->fields['questionnaire_score']);
        $answers = json_decode((string) $risk->fields['questionnaire_answers'], true);
        $this->assertIsArray($answers);
        $this->assertArrayNotHasKey('unknown', $answers);
        $this->assertSame('partial', $answers['encryption']);
        $this->assertSame((int) \Session::getLoginUserID(), (int) $risk->fields['questionnaire_users_id']);
        $this->assertSame($level, $risk->fields['risk_level'], 'niveau de risque inchangé');
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

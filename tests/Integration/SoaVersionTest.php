<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\Services\Control\SoaApprovalLogic;
use GlpiPlugin\Grcmanager\SoaVersion;

/**
 * Issue #112 : approbation de la SoA par la direction sur une vraie instance GLPI : version
 * figée (PDF en Document GLPI, empreintes), réponses des approbateurs, obsolescence.
 */
final class SoaVersionTest extends GrcmanagerIntegrationTestCase
{
    private function approver(string $name): int
    {
        $user = new \User();

        return (int) $user->add(['name' => 'phpunit.' . $name . '.' . random_int(100000, 999999)]);
    }

    private function request(array $approverIds): SoaVersion
    {
        $id = SoaVersion::requestApproval($approverIds, 'PHPUnit');
        $this->assertGreaterThan(0, $id);
        $version = new SoaVersion();
        $version->getFromDB($id);

        return $version;
    }

    /**
     * Issue #648 : la liste des approbateurs doit envoyer tous les choix, pas seulement le dernier.
     * On rejoue l'envoi du navigateur (champ caché vide puis une valeur par option choisie) avec
     * les noms réellement rendus, puis la demande d'approbation avec ce que PHP en a lu.
     */
    public function testTheApproverListSendsEverySelectedApprover(): void
    {
        $html = SoaVersion::approverDropdown();
        $this->assertMatchesRegularExpression('/<select name="approvers\[\]"[^>]*multiple/', $html);
        $this->assertSame(1, preg_match('/<select name="([^"]+)"/', $html, $select));
        $this->assertSame(1, preg_match('/<input type="hidden" name="([^"]+)" value=""/', $html, $hidden));

        $alice = $this->approver('alice');
        $bob = $this->approver('bob');
        parse_str(http_build_query([$hidden[1] => '']) . '&'
            . rawurlencode($select[1]) . '=' . $alice . '&' . rawurlencode($select[1]) . '=' . $bob, $post);
        $this->assertSame([(string) $alice, (string) $bob], $post['approvers']);

        $version = $this->request(array_map('intval', (array) $post['approvers']));
        $this->assertSame(
            [$alice, $bob],
            array_column(SoaVersion::getApprovers($version->getID()), 'users_id')
        );
    }

    public function testRequestFreezesTheSoaInADocumentWithBothFingerprints(): void
    {
        $alice = $this->approver('alice');
        $version = $this->request([$alice, $alice, 0]);

        $this->assertSame(SoaApprovalLogic::VERSION_PENDING, $version->fields['status']);
        $this->assertSame(SoaVersion::currentFingerprint(), $version->fields['fingerprint']);
        $this->assertSame(64, strlen((string) $version->fields['pdf_sha256']));
        $document = new \Document();
        $this->assertTrue($document->getFromDB((int) $version->fields['documents_id']));
        $this->assertSame(
            $version->fields['pdf_sha256'],
            hash_file('sha256', GLPI_DOC_DIR . '/' . $document->fields['filepath']),
            'l\'empreinte prouve exactement le document figé'
        );
        $this->assertCount(1, SoaVersion::getApprovers($version->getID()), 'doublons et 0 écartés');
    }

    public function testEveryApproverMustAgreeAndOnlyThem(): void
    {
        $alice = $this->approver('alice');
        $bob = $this->approver('bob');
        $outsider = $this->approver('eve');
        $version = $this->request([$alice, $bob]);

        $this->assertFalse(SoaVersion::answer($version->getID(), $outsider, true, ''));
        $this->assertTrue(SoaVersion::answer($version->getID(), $alice, true, ''));
        $this->assertFalse(
            SoaVersion::answer($version->getID(), $alice, true, ''),
            'une seule réponse'
        );
        $version->getFromDB($version->getID());
        $this->assertSame(SoaApprovalLogic::VERSION_PENDING, $version->fields['status']);

        $this->assertTrue(SoaVersion::answer($version->getID(), $bob, true, 'Validé'));
        $version->getFromDB($version->getID());
        $this->assertSame(SoaApprovalLogic::VERSION_APPROVED, $version->fields['status']);
        $this->assertNotEmpty($version->fields['date_answered']);
        $this->assertSame([], SoaVersion::pendingForUser($bob));
    }

    public function testOneRefusalRejectsAndANewRequestObsoletesThePendingOne(): void
    {
        $alice = $this->approver('alice');
        $bob = $this->approver('bob');

        $rejected = $this->request([$alice, $bob]);
        SoaVersion::answer($rejected->getID(), $alice, false, 'Justifications manquantes');
        $rejected->getFromDB($rejected->getID());
        $this->assertSame(SoaApprovalLogic::VERSION_REJECTED, $rejected->fields['status']);

        $first = $this->request([$alice]);
        $second = $this->request([$alice]);
        $first->getFromDB($first->getID());
        $this->assertSame(SoaApprovalLogic::VERSION_OBSOLETE, $first->fields['status']);
        $this->assertSame((int) $first->fields['version'] + 1, (int) $second->fields['version']);
        $this->assertFalse(SoaVersion::answer($first->getID(), $alice, true, ''), 'version obsolète');
    }

    public function testAnyChangeToTheSoaIsDetectedAfterApproval(): void
    {
        $alice = $this->approver('alice');
        $version = $this->request([$alice]);
        SoaVersion::answer($version->getID(), $alice, true, '');
        $approved = SoaVersion::latest(SoaApprovalLogic::VERSION_APPROVED);
        $current = static fn (): string => SoaApprovalLogic::currentState(
            $approved['fingerprint'],
            SoaVersion::currentFingerprint()
        );
        $this->assertSame('up_to_date', $current());

        $control = new \GlpiPlugin\Grcmanager\Control();
        $control->getFromDBByCrit(['code' => 'A.5.1']);
        $newStatus = $control->fields['implementation_status'] === 'verified' ? 'in_progress' : 'verified';
        $control->update(['id' => $control->getID(), 'implementation_status' => $newStatus]);

        $this->assertSame('changed', $current());
    }
}

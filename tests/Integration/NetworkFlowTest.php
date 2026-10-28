<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\Services\Network\NetworkFlowRules;
use GlpiPlugin\Grcmanager\NetworkFlow;

/**
 * Issue #144 : matrice des flux, contrôles automatiques et import CSV sans doublon.
 */
final class NetworkFlowTest extends GrcmanagerIntegrationTestCase
{
    public function testInputIsNormalizedAndTheNameBuiltWhenEmpty(): void
    {
        $flow = new \GlpiPlugin\Grcmanager\NetworkFlow();
        $id = (int) $flow->add([
            'flow_type' => 'INTERNET_PUBLICATION', 'source_label' => 'Internet', 'destination_label' => 'Serveur web',
            'protocol' => 'TCP', 'ports' => '443; 80', 'justification' => 'Site public',
        ]);
        $this->assertGreaterThan(0, $id);
        $flow->getFromDB($id);
        $this->assertSame('internet_publication', $flow->fields['flow_type']);
        $this->assertSame('443,80', $flow->fields['ports']);
        $this->assertSame('Internet → Serveur web', $flow->fields['name']);
        $this->assertSame([NetworkFlowRules::CHECK_NO_OWNER], $flow->getChecks());
    }

    public function testInvalidPortsOrMissingEndpointsAreRefused(): void
    {
        $this->assertFalse((new \GlpiPlugin\Grcmanager\NetworkFlow())->add([
            'source_label' => 'A', 'destination_label' => 'B', 'ports' => 'https',
        ]));
        $this->assertFalse((new \GlpiPlugin\Grcmanager\NetworkFlow())->add(['source_label' => 'A', 'ports' => '22']));
    }

    public function testALinkedAssetMustExist(): void
    {
        $this->assertFalse((new \GlpiPlugin\Grcmanager\NetworkFlow())->add([
            'source_itemtype' => 'Computer', 'source_items_id' => 999999999, 'destination_label' => 'B',
        ]));
        $computersId = (int) (new \Computer())->add(['name' => 'PHPUnit serveur flux', 'entities_id' => 0]);
        $flow = new \GlpiPlugin\Grcmanager\NetworkFlow();
        $id = (int) $flow->add([
            'source_itemtype' => 'Computer', 'source_items_id' => $computersId, 'destination_label' => 'Sauvegarde',
            'ports' => '22',
        ]);
        $this->assertGreaterThan(0, $id);
        $flow->getFromDB($id);
        $this->assertStringContainsString('PHPUnit serveur flux', \GlpiPlugin\Grcmanager\NetworkFlow::endpointHtml(
            $flow->fields['source_itemtype'],
            (int) $flow->fields['source_items_id'],
            $flow->fields['source_label']
        ));
        $this->assertFalse((new \GlpiPlugin\Grcmanager\NetworkFlow())->add([
            'source_itemtype' => 'User', 'source_items_id' => 2, 'destination_label' => 'B',
        ]), 'Type d\'élément non proposé.');
    }

    public function testCsvImportSimulatesThenCreatesWithoutDuplicates(): void
    {
        $label = 'PHPUnit site ' . random_int(1000, 9999);
        $csv = "nom;type;source;destination;protocole;ports;justification;proprietaire;date_fin\n"
            . "Publication RDP;publication;Internet;$label;tcp;3389;;inconnu.phpunit;\n"
            . "Sauvegarde;sauvegarde;$label;Site secours;tcp;10000;Réplication;glpi;31/12/2099\n"
            . "Ligne invalide;;;$label;tcp;22;;;\n";
        $before = countElementsInTable(\GlpiPlugin\Grcmanager\NetworkFlow::getTable());

        $simulation = \GlpiPlugin\Grcmanager\NetworkFlow::importCsv($csv, true);
        $this->assertSame(2, $simulation['created']);
        $this->assertCount(2, $simulation['errors'], 'Ligne invalide et propriétaire introuvable.');
        $after = countElementsInTable(\GlpiPlugin\Grcmanager\NetworkFlow::getTable());
        $this->assertSame($before, $after, 'Simulation : rien écrit.');

        $import = \GlpiPlugin\Grcmanager\NetworkFlow::importCsv($csv, false);
        $this->assertSame(2, $import['created']);
        $this->assertSame($before + 2, countElementsInTable(\GlpiPlugin\Grcmanager\NetworkFlow::getTable()));

        $flow = new \GlpiPlugin\Grcmanager\NetworkFlow();
        $this->assertTrue($flow->getFromDBByCrit(['destination_label' => 'Site secours', 'source_label' => $label]));
        $this->assertSame(2, (int) $flow->fields['users_id'], 'Propriétaire retrouvé par son identifiant.');
        $this->assertSame('2099-12-31', $flow->fields['date_end']);
        $this->assertTrue($flow->getFromDBByCrit(['destination_label' => $label, 'ports' => '3389']));
        $this->assertContains(NetworkFlowRules::CHECK_ADMIN_EXPOSED, $flow->getChecks());

        $again = \GlpiPlugin\Grcmanager\NetworkFlow::importCsv($csv, false);
        $this->assertSame(0, $again['created']);
        $this->assertSame(2, $again['skipped']);
    }
}

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

use Contract;
use Contract_Supplier;
use ContractCost;
use GlpiPlugin\Grcmanager\BusinessImpact;
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\DoraArrangement;
use GlpiPlugin\Grcmanager\DoraFunction;
use GlpiPlugin\Grcmanager\DoraProvider;
use GlpiPlugin\Grcmanager\DoraRegister;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterBuilder;
use Supplier;

/**
 * Issue #540 : registre d'informations DORA construit depuis des contrats, fournisseurs et
 * processus fictifs : complétude, export, droits, et rien de visible tant que le référentiel DORA
 * est désactivé. Indépendant de la langue de l'instance : aucune comparaison de texte traduit.
 */
final class DoraRegisterTest extends GrcmanagerIntegrationTestCase
{
    private const BANK_LEI = '9999000EXEMPLEBANQ61';
    private const CLOUD_LEI = '9999000NUAGEDEMO0132';
    private const HOSTING_LEI = '9999000HEBERGFICT174';

    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = $_SESSION;
        FrameworkConfig::reset();
        DoraRegister::resetCache();
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->session;
        parent::tearDown();
        FrameworkConfig::reset();
        DoraRegister::resetCache();
    }

    private function enableDora(bool $enabled = true): void
    {
        FrameworkConfig::setEnabled(FrameworkSelection::DORA, $enabled);
        FrameworkConfig::reset();
        DoraRegister::resetCache();
    }

    private function supplier(string $name, string $registration = ''): Supplier
    {
        $supplier = new Supplier();
        $id = (int) $supplier->add([
            'name' => $name, 'entities_id' => 0, 'country' => 'FR', 'registration_number' => $registration,
        ]);
        $this->assertGreaterThan(0, $id);
        $supplier->getFromDB($id);

        return $supplier;
    }

    private function contract(string $name, string $num, Supplier $supplier): Contract
    {
        $contract = new Contract();
        $id = (int) $contract->add([
            'name' => $name, 'num' => $num, 'entities_id' => 0, 'begin_date' => '2026-01-01', 'duration' => 36,
            'notice' => 3,
        ]);
        $this->assertGreaterThan(0, $id);
        $this->assertGreaterThan(0, (int) (new Contract_Supplier())->add([
            'contracts_id' => $id, 'suppliers_id' => $supplier->getID(),
        ]));
        $contract->getFromDB($id);

        return $contract;
    }

    private function process(string $name, int $worstLevel): BusinessProcess
    {
        $process = new BusinessProcess();
        $id = (int) $process->add(['name' => $name, 'entities_id' => 0, 'rto_hours' => 4, 'rpo_hours' => 1]);
        $this->assertGreaterThan(0, $id);
        $process->getFromDB($id);
        BusinessImpact::saveGrid($process, [24 => ['operational' => $worstLevel]]);
        $process->getFromDB($id);

        return $process;
    }

    /**
     * Deux prestataires TIC fictifs (dont l'un sous-traitant de l'autre), deux contrats et deux
     * processus, dont un critique.
     *
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        DoraRegister::saveSettings([
            'lei' => self::BANK_LEI, 'name' => 'Banque Exemple SA', 'country' => 'fr', 'type' => 1,
            'authority' => 'Autorite fictive', 'currency' => 'EUR', 'total_assets' => '1500000000', 'hierarchy' => 4,
        ]);
        $cloud = $this->supplier('PHPUnit Nuage Demo ' . mt_rand(), self::CLOUD_LEI);
        $hosting = $this->supplier('PHPUnit Hebergement Fictif ' . mt_rand());
        $payments = $this->process('PHPUnit Paiements clients', 4);
        $payroll = $this->process('PHPUnit Paie', 1);
        $saas = $this->contract('PHPUnit Contrat SaaS', 'CTR-2026-01', $cloud);
        $support = $this->contract('PHPUnit Contrat support', 'CTR-2026-02', $hosting);
        $this->assertGreaterThan(0, (int) (new ContractCost())->add([
            'contracts_id' => $saas->getID(), 'name' => 'Abonnement', 'cost' => 120000,
            'begin_date' => '2026-01-01', 'end_date' => '2026-12-31', 'entities_id' => 0,
        ]));

        return compact('cloud', 'hosting', 'payments', 'payroll', 'saas', 'support');
    }

    public function testNothingVisibleWhileDoraIsDisabled(): void
    {
        $this->enableDora(false);
        $s = $this->scenario();

        $this->assertFalse(DoraRegister::isAvailable());
        $this->assertFalse(DoraRegister::getMenuContent());
        $this->assertSame('', (new DoraRegister())->getTabNameForItem($s['saas']));
        $this->assertSame('', (new DoraRegister())->getTabNameForItem($s['cloud']));
        $this->assertFalse(DoraRegister::displayTabContentForItem($s['saas'], DoraRegister::TAB_ARRANGEMENT));
        $menu = Menu::withSubPages(\GlpiPlugin\Grcmanager\Control::class, ['title' => 'x', 'page' => '/x']);
        $this->assertArrayNotHasKey(DoraRegister::class, $menu['options'] ?? []);

        $this->enableDora();
        $this->assertTrue(DoraRegister::isAvailable());
        $this->assertIsArray(DoraRegister::getMenuContent());
        $this->assertNotSame('', (new DoraRegister())->getTabNameForItem($s['saas']));
        $this->assertNotSame('', (new DoraRegister())->getTabNameForItem($s['payments']));
    }

    public function testRegisterIsBuiltFromContractsSuppliersAndProcesses(): void
    {
        $this->enableDora();
        $s = $this->scenario();
        $this->assertTrue(DoraArrangement::include($s['saas']));
        $this->assertTrue(DoraArrangement::include($s['support']));
        $this->assertSame(90, (int) DoraArrangement::forContract($s['saas']->getID())['notice_entity_days'], '3 mois');

        // Fournisseur sans champ DORA : nom, pays et LEI repris de GLPI.
        $data = DoraRegister::collect();
        $cloud = $data['register']['providers'][$s['cloud']->getID()];
        $this->assertSame([self::CLOUD_LEI, 'LEI', 'FR'], [$cloud['code'], $cloud['code_type'], $cloud['hq_country']]);
        $saas = array_column($data['register']['arrangements'], null, 'key')[$s['saas']->getID()];
        $this->assertSame('CTR-2026-01', $saas['reference'], 'numéro du contrat GLPI');
        $this->assertSame((int) $s['cloud']->getID(), $saas['provider'], 'fournisseur unique du contrat');
        $this->assertSame(120000.0, $saas['annual_cost'], 'coûts du contrat sur douze mois');
        $this->assertSame(['2026-01-01', '2029-01-01'], [$saas['start_date'], $saas['end_date']]);

        $columns = array_column(DoraRegister::completeness($data)['issues'], 'column');
        $this->assertContains('B_02.02.0060', $columns, 'types de services manquants');
        $this->assertContains('B_02.02.0050', $columns, 'fonctions manquantes');
        $this->assertContains('B_05.01.0010', $columns, 'code du prestataire sans LEI');

        // Saisie DORA : accords, fonctions, sous-traitance, prestataire, fonctions critiques.
        $this->assertTrue(DoraProvider::saveFor((int) $s['hosting']->getID(), [
            'code' => self::HOSTING_LEI, 'code_type' => 'lei', 'hq_country' => 'de', 'person_type' => 1,
        ]));
        $this->assertTrue(DoraFunction::saveFor((int) $s['payments']->getID(), [
            'licensed_activity' => 'Payment services', 'criticality' => 1, 'criticality_reasons' => 'Bloquant',
        ]));
        $this->assertTrue(DoraFunction::saveFor((int) $s['payroll']->getID(), [
            'licensed_activity' => DoraFunction::SUPPORT_FUNCTIONS, 'criticality' => 2,
        ]));
        $this->assertTrue(DoraArrangement::addFunction((int) $s['saas']->getID(), (int) $s['payments']->getID()));
        $this->assertFalse(DoraArrangement::addFunction((int) $s['saas']->getID(), (int) $s['payments']->getID()), 'déjà liée');
        $this->assertTrue(DoraArrangement::addFunction((int) $s['support']->getID(), (int) $s['payroll']->getID()));
        $this->assertTrue(DoraArrangement::addSubcontractor((int) $s['saas']->getID(), (int) $s['hosting']->getID(), 0));
        $this->assertFalse(DoraArrangement::addSubcontractor((int) $s['saas']->getID(), (int) $s['hosting']->getID(), 0));

        $update = static function (Contract $contract, array $input): void {
            $row = DoraArrangement::forContract((int) $contract->getID());
            (new DoraArrangement())->update(['id' => $row['id']] + DoraArrangement::sanitize(
                $input,
                DoraRegister::contractSupplierIds((int) $contract->getID())
            ));
        };
        $update($s['support'], ['arrangement_type' => 1, 'service_types' => ['S03'], 'annual_cost' => '8 000']);
        $update($s['saas'], ['arrangement_type' => 1, 'service_types' => ['S19', 'S17'], 'notice_entity_days' => 90]);

        // Fonction critique : préavis, lieux des données, substituabilité... deviennent obligatoires.
        $data = DoraRegister::collect();
        $saasIssues = array_filter(
            DoraRegister::completeness($data)['issues'],
            static fn (array $i): bool => $i['scope'] === 'arrangement' && $i['key'] === (int) $s['saas']->getID()
        );
        $this->assertContains('B_02.02.0110', array_column($saasIssues, 'column'));
        $this->assertContains('B_07.01.0050', array_column($saasIssues, 'column'));
        $this->assertNotContains('B_02.02.0100', array_column($saasIssues, 'column'), 'préavis repris du contrat');
        $supportIssues = array_filter(
            DoraRegister::completeness($data)['issues'],
            static fn (array $i): bool => $i['scope'] === 'arrangement' && $i['key'] === (int) $s['support']->getID()
        );
        $this->assertSame([], array_values($supportIssues), 'sans fonction critique, rien de plus n\'est exigé');

        $update($s['saas'], [
            'arrangement_type' => 1, 'service_types' => ['S19', 'S17'], 'notice_entity_days' => 90,
            'notice_provider_days' => '180', 'governing_law_country' => 'fr', 'provision_country' => 'FR',
            'data_storage' => 1, 'storage_countries' => 'fr, de', 'processing_countries' => 'FR',
            'data_sensitivity' => 3, 'reliance' => 4, 'substitutability' => 2, 'non_substitutable_reason' => 2,
            'exit_plan' => 1, 'reintegration' => 3, 'discontinuing_impact' => 3, 'alternatives' => 2,
        ]);
        $data = DoraRegister::collect();
        $this->assertSame([], DoraRegister::completeness($data)['issues']);
        $this->assertSame(100, DoraRegister::completeness($data)['rate']);

        // Fonction : identifiant, RTO, RPO et impact de la BIA, date de la BIA par défaut.
        $function = $data['register']['functions'][$s['payments']->getID()];
        $this->assertSame('F' . $s['payments']->getID(), $function['identifier']);
        $this->assertSame([4, 1, 3], [$function['rto'], $function['rpo'], $function['impact']]);
        $this->assertNotNull($function['assessment_date']);
        $this->assertSame(1, $data['register']['functions'][$s['payroll']->getID()]['impact'], 'BIA négligeable : impact faible');

        // Export : un CSV par modèle, codes de colonnes et valeurs officielles.
        $files = DoraRegister::exportFiles();
        $this->assertStringStartsWith("c0010,c0020,c0030,c0040,c0050\r\n", $files['b_02.01.csv']);
        $this->assertStringContainsString('CTR-2026-01,standalone arrangement,,EUR,120000', $files['b_02.01.csv']);
        $this->assertStringContainsString(self::HOSTING_LEI . ',LEI,2,' . self::CLOUD_LEI . ',LEI', $files['b_05.02.csv']);
        $this->assertStringContainsString(self::BANK_LEI, $files['b_01.01.csv']);
        $rows = RegisterBuilder::build($data['register']);
        $this->assertCount(2, $rows['B_07.01'], 'contrat critique, deux types de services');
        $this->assertCount(4, array_filter($rows['B_02.02'], static fn (array $r): bool => $r['c0010'] === 'CTR-2026-01'));

        $zip = DoraRegister::buildZip();
        if ($zip !== null) {
            $archive = new \ZipArchive();
            $this->assertTrue($archive->open($zip));
            $this->assertNotFalse($archive->locateName('reports/b_06.01.csv'));
            $archive->close();
            unlink($zip);
        }

        // Retrait du registre, puis contrat supprimé définitivement : plus aucune trace.
        $this->assertTrue(DoraArrangement::exclude((int) $s['support']->getID()));
        $this->assertSame([], DoraArrangement::getFunctionIds((int) $s['support']->getID()));
        $s['saas']->delete(['id' => $s['saas']->getID()], true);
        DoraRegister::forgetItem($s['saas']);
        $this->assertSame([], DoraArrangement::forContract((int) $s['saas']->getID()));
        $this->assertSame([], DoraArrangement::getSubcontractors((int) $s['saas']->getID()));
    }

    public function testRanksFollowTheSubcontractingChain(): void
    {
        $this->assertSame([7 => 2, 8 => 3, 9 => 4, 10 => 2], DoraArrangement::ranks([
            ['suppliers_id' => 7, 'recipient' => 0],
            ['suppliers_id' => 8, 'recipient' => 7],
            ['suppliers_id' => 9, 'recipient' => 8],
            ['suppliers_id' => 10, 'recipient' => 0],
        ]));
    }

    public function testRightsAndReadableItemsOnly(): void
    {
        $this->enableDora();
        $s = $this->scenario();
        DoraArrangement::include($s['saas']);

        // Lecture seule : registre visible, pas modifiable.
        $_SESSION['glpiactiveprofile'][DoraArrangement::RIGHTNAME] = READ;
        DoraRegister::resetCache();
        $this->assertTrue(DoraRegister::isAvailable());
        $this->assertFalse(DoraRegister::canEdit());

        // Sans droit sur le plugin : rien.
        $_SESSION['glpiactiveprofile'][DoraArrangement::RIGHTNAME] = 0;
        $this->assertFalse(DoraRegister::isAvailable());
        $this->assertSame('', (new DoraRegister())->getTabNameForItem($s['saas']));
        $this->assertFalse(DoraRegister::displayTabContentForItem($s['saas'], DoraRegister::TAB_ARRANGEMENT));

        // Sans lecture des contrats : aucun accord n'est repris.
        $_SESSION['glpiactiveprofile'][DoraArrangement::RIGHTNAME] = ALLSTANDARDRIGHT;
        $_SESSION['glpiactiveprofile']['contract'] = 0;
        DoraRegister::resetCache();
        $this->assertSame([], DoraRegister::collect()['register']['arrangements']);

        // Contrat d'une autre entité : hors du registre de la personne.
        $_SESSION['glpiactiveprofile']['contract'] = ALLSTANDARDRIGHT;
        $_SESSION['glpiactiveentities'] = [999999];
        $_SESSION['glpiactiveentities_string'] = '999999';
        DoraRegister::resetCache();
        $this->assertSame([], DoraRegister::collect()['register']['arrangements']);
    }

    public function testTabsRender(): void
    {
        $this->enableDora();
        $s = $this->scenario();

        ob_start();
        $this->assertTrue(DoraRegister::displayTabContentForItem($s['saas'], DoraRegister::TAB_ARRANGEMENT));
        $this->assertStringContainsString('grc-dora-include', (string) ob_get_clean());

        DoraArrangement::include($s['saas']);
        DoraArrangement::addFunction((int) $s['saas']->getID(), (int) $s['payments']->getID());
        foreach (
            [
                [$s['saas'], DoraRegister::TAB_ARRANGEMENT, 'grc-dora-arrangement-form'],
                [$s['cloud'], DoraRegister::TAB_PROVIDER, 'grc-dora-provider-form'],
                [$s['payments'], DoraRegister::TAB_FUNCTION, 'grc-dora-function-form'],
            ] as [$item, $tab, $marker]
        ) {
            ob_start();
            $this->assertTrue(DoraRegister::displayTabContentForItem($item, $tab));
            $this->assertStringContainsString($marker, (string) ob_get_clean());
        }
        $this->assertFalse(DoraRegister::displayTabContentForItem($s['saas'], DoraRegister::TAB_PROVIDER), 'mauvais onglet');

        ob_start();
        DoraRegister::showRegisterPage();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('grc-dora-arrangements', $html);
        $this->assertStringContainsString('PHPUnit Contrat SaaS', $html);
    }
}

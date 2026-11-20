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

use Config;
use Document_Item;
use GlpiPlugin\Grcmanager\Control;
use GlpiPlugin\Grcmanager\Menu;
use GlpiPlugin\Grcmanager\OperatingProcedure;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\PolicyVersion;
use GlpiPlugin\Grcmanager\Services\Policy\OperatingProcedureRules as Rules;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalConfig;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalRules;
use User;

/**
 * Issue #775 : procédures d'exploitation documentées (A.5.37), portées par la bibliothèque des
 * politiques : catégorie et domaine, domaines attendus réglables, couverture, preuve sur A.5.37.
 * Données fictives ; aucune comparaison de texte traduit.
 */
final class OperatingProcedureTest extends GrcmanagerIntegrationTestCase
{
    /**
     * @param array<string, mixed> $input
     */
    private function procedure(array $input = []): Policy
    {
        $policy = new Policy();
        $id = (int) $policy->add($input + [
            'title'            => 'PHPUnit procédure de sauvegarde fictive',
            'category'         => Rules::CATEGORY_PROCEDURE,
            'procedure_domain' => 'backup',
            'version'          => '1.0',
            'status'           => 'draft',
            'users_id'         => 2,
        ]);
        $this->assertGreaterThan(0, $id);
        $policy->getFromDB($id);

        return $policy;
    }

    /** Domaines propres au test, pour ne dépendre d'aucune autre donnée de l'instance. */
    private function useDomains(array $domains): void
    {
        OperatingProcedure::saveDomains($domains);
    }

    /**
     * @return array<string, array{domain: string, status: string, count: int}>
     */
    private function coverageByDomain(): array
    {
        $coverage = OperatingProcedure::coverage(OperatingProcedure::procedures(), date('Y-m-d'));
        $byDomain = [];
        foreach ($coverage['domains'] as $row) {
            $byDomain[$row['domain']] = $row;
        }

        return $byDomain;
    }

    public function testCategoryAndDomainAreStoredAndNormalized(): void
    {
        $procedure = $this->procedure(['procedure_domain' => '<b>Sauvegarde</b> des bases']);
        $this->assertSame(Rules::CATEGORY_PROCEDURE, $procedure->fields['category']);
        $this->assertSame('Sauvegarde des bases', $procedure->fields['procedure_domain']);

        // Une politique n'a jamais de domaine ; une catégorie inconnue devient « politique ».
        $policy = $this->procedure(['category' => 'bogus', 'procedure_domain' => 'backup']);
        $this->assertSame(Rules::CATEGORY_POLICY, $policy->fields['category']);
        $this->assertSame('', $policy->fields['procedure_domain']);
        $legacy = new Policy();
        $legacyId = (int) $legacy->add(['title' => 'PHPUnit politique sans catégorie']);
        $legacy->getFromDB($legacyId);
        $this->assertSame(Rules::CATEGORY_POLICY, $legacy->fields['category']);

        // Modifier un autre champ ne touche ni à la catégorie ni au domaine.
        $this->assertTrue($procedure->update(['id' => $procedure->getID(), 'next_review_date' => '2027-06-01']));
        $procedure->getFromDB($procedure->getID());
        $this->assertSame(Rules::CATEGORY_PROCEDURE, $procedure->fields['category']);
        $this->assertSame('Sauvegarde des bases', $procedure->fields['procedure_domain']);

        // Repasser en politique retire le domaine.
        $this->assertTrue($procedure->update(['id' => $procedure->getID(), 'category' => Rules::CATEGORY_POLICY]));
        $procedure->getFromDB($procedure->getID());
        $this->assertSame('', $procedure->fields['procedure_domain']);
        $this->assertNotContains($procedure->getID(), array_map('intval', array_column(OperatingProcedure::procedures(), 'id')));
    }

    public function testDomainsAreConfigurable(): void
    {
        Config::deleteConfigurationValues(PolicyApprovalConfig::CONTEXT, [OperatingProcedure::CONFIG_KEY]);
        $this->assertSame(Rules::DEFAULT_DOMAINS, OperatingProcedure::domains(), 'liste par défaut');
        $this->assertCount(count(Rules::DEFAULT_DOMAINS), OperatingProcedure::defaultDomainLabels());

        $this->useDomains(['backup', 'PHPUnit téléphonie', '', 'backup']);
        $this->assertSame(['backup', 'PHPUnit téléphonie'], OperatingProcedure::domains());
        $form = OperatingProcedure::configForm();
        $this->assertSame('PHPUnit téléphonie', $form['others']);
        $checked = array_column(array_filter($form['defaults'], static fn (array $d): bool => $d['checked']), 'key');
        $this->assertSame(['backup'], array_values($checked));

        // Le domaine d'une procédure qui n'est plus attendu reste proposé sur sa fiche.
        $choices = OperatingProcedure::domainChoices('maintenance');
        $this->assertArrayHasKey('maintenance', $choices);
        $this->assertArrayHasKey('PHPUnit téléphonie', $choices);
        $this->assertArrayNotHasKey('restore', OperatingProcedure::domainChoices());
        $this->assertSame('PHPUnit téléphonie', OperatingProcedure::domainLabel('PHPUnit téléphonie'));

        // Liste vidée : aucun domaine attendu, pas de retour silencieux à la liste par défaut.
        $this->useDomains([]);
        $this->assertSame([], OperatingProcedure::domains());
    }

    public function testCoverageShowsDomainsWithAndWithoutAnUpToDateProcedure(): void
    {
        $this->useDomains(['PHPUnit A', 'PHPUnit B', 'PHPUnit C', 'PHPUnit D']);
        $this->procedure([
            'procedure_domain' => 'PHPUnit A', 'status' => 'approved', 'approval_date' => '2026-01-10',
            'next_review_date' => date('Y-m-d', strtotime('+6 months')),
        ]);
        $this->procedure(['procedure_domain' => 'PHPUnit A', 'status' => 'draft']);
        $this->procedure([
            'procedure_domain' => 'PHPUnit B', 'status' => 'approved', 'approval_date' => '2025-01-10',
            'next_review_date' => date('Y-m-d', strtotime('-1 day')),
        ]);
        $this->procedure(['procedure_domain' => 'PHPUnit C', 'status' => 'draft']);
        $this->procedure(['procedure_domain' => 'PHPUnit D', 'status' => 'archived']);

        $byDomain = $this->coverageByDomain();
        $this->assertSame(Rules::STATUS_UP_TO_DATE, $byDomain['PHPUnit A']['status']);
        $this->assertSame(2, $byDomain['PHPUnit A']['count']);
        $this->assertSame(Rules::STATUS_REVIEW_DUE, $byDomain['PHPUnit B']['status']);
        $this->assertSame(Rules::STATUS_NOT_APPROVED, $byDomain['PHPUnit C']['status']);
        $this->assertSame(Rules::STATUS_MISSING, $byDomain['PHPUnit D']['status'], 'une procédure archivée ne couvre rien');

        $evidence = OperatingProcedure::evidence(OperatingProcedure::CONTROL_CODE);
        $this->assertNotNull($evidence);
        $this->assertSame(4, $evidence['total']);
        $this->assertSame(1, $evidence['up_to_date']);
        $this->assertSame(1, $evidence['missing']);
        $this->assertSame(25, $evidence['rate']);
        $this->assertNull(OperatingProcedure::evidence('A.5.1'), 'preuve de la seule mesure A.5.37');

        // Le lien d'ajout ouvre une procédure du bon domaine.
        $this->assertStringContainsString('category=operating_procedure', OperatingProcedure::addURL('PHPUnit D'));
        $this->assertStringContainsString('procedure_domain=PHPUnit+D', OperatingProcedure::addURL('PHPUnit D'));
    }

    public function testRegisterPageAndPdfRender(): void
    {
        $this->useDomains(['backup']);
        $this->procedure(['title' => 'PHPUnit procédure <script>alert(1)</script>']);

        ob_start();
        OperatingProcedure::showRegister();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('PHPUnit procédure &lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);

        $report = OperatingProcedure::reportHtml();
        $this->assertStringContainsString('A.5.37', $report);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $report);
    }

    public function testRightsOfThePolicyLibraryApply(): void
    {
        $this->procedure();
        $this->assertNotSame([], OperatingProcedure::procedures());
        $this->assertContains(OperatingProcedure::class, Menu::sections()[Control::class]);

        $rights = $_SESSION['glpiactiveprofile'][Policy::RIGHTNAME] ?? 0;
        $_SESSION['glpiactiveprofile'][Policy::RIGHTNAME] = 0;
        try {
            $this->assertFalse(OperatingProcedure::canView());
            $this->assertFalse(OperatingProcedure::getMenuContent());
            $this->assertSame([], OperatingProcedure::procedures());
            $this->assertNull(OperatingProcedure::evidence(OperatingProcedure::CONTROL_CODE));
        } finally {
            $_SESSION['glpiactiveprofile'][Policy::RIGHTNAME] = $rights;
        }
    }

    public function testArchiveAttachesTheRegisterToControlA537(): void
    {
        $this->procedure();
        $controlIds = OperatingProcedure::controlIds();
        $this->assertNotSame([], $controlIds, 'mesure A.5.37 de la SoA suivie');

        $documentsId = OperatingProcedure::archive();
        $this->assertGreaterThan(0, $documentsId);
        foreach ($controlIds as $controlId) {
            $this->assertSame(1, countElementsInTable(Document_Item::getTable(), [
                'documents_id' => $documentsId, 'itemtype' => Control::class, 'items_id' => $controlId,
            ]));
        }
    }

    public function testApprovalRecordOfAProcedureIsEvidenceOnA537(): void
    {
        Config::setConfigurationValues(PolicyApprovalConfig::CONTEXT, [
            PolicyApprovalConfig::ENABLED_KEY   => '1',
            PolicyApprovalConfig::APPROVERS_KEY => '[]',
            PolicyApprovalConfig::QUORUM_KEY    => (string) PolicyApprovalRules::QUORUM_ALL,
        ]);
        $approver = $this->createTestUser('Alice', 'Direction');
        $procedure = $this->procedure();
        PolicyVersion::setDesignated($procedure, [['itemtype' => User::class, 'items_id' => $approver]], PolicyApprovalRules::QUORUM_DEFAULT);
        $procedure->getFromDB($procedure->getID());
        $versionId = PolicyVersion::submit($procedure, 'PHPUnit');
        $this->assertGreaterThan(0, $versionId);
        $this->assertTrue(PolicyVersion::answer($versionId, $approver, true, ''));

        $version = new PolicyVersion();
        $version->getFromDB($versionId);
        $recordId = (int) $version->fields['record_documents_id'];
        $this->assertGreaterThan(0, $recordId);
        $codes = [];
        foreach (
            getAllDataFromTable(Document_Item::getTable(), [
                'documents_id' => $recordId, 'itemtype' => Control::class,
            ]) as $link
        ) {
            $control = new Control();
            $control->getFromDB((int) $link['items_id']);
            $codes[] = $control->fields['code'];
        }
        $this->assertSame([OperatingProcedure::CONTROL_CODE], array_values(array_unique($codes)));

        // Une politique garde sa preuve sur A.5.1.
        $policy = new Policy();
        $policy->getFromDB($procedure->getID());
        $policy->fields['category'] = Rules::CATEGORY_POLICY;
        $this->assertSame(PolicyVersion::CONTROL_CODE, OperatingProcedure::controlCodeFor($policy));
    }

    public function testSearchOptionsShowCategoryAndDomain(): void
    {
        $this->assertNotSame('', Policy::getSpecificValueToDisplay('category', 'operating_procedure'));
        $this->assertSame(
            OperatingProcedure::domainLabel('backup'),
            Policy::getSpecificValueToDisplay('procedure_domain', 'backup')
        );
        $this->assertSame('&lt;b&gt;', Policy::getSpecificValueToDisplay('procedure_domain', '<b>'));
    }
}

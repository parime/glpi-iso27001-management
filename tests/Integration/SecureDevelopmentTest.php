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

use Appliance;
use Change;
use Change_Item;
use GlpiPlugin\Grcmanager\ApplicationSecurity;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\ReleaseValidation;
use GlpiPlugin\Grcmanager\SecureDevelopment;
use GlpiPlugin\Grcmanager\Services\SecureDevelopment\SecureDevelopmentRules as Rules;

/**
 * Issue #777 : développement sécurisé (A.8.25 à A.8.31). Fiche d'une application du cœur,
 * validation de mise en production d'un changement du cœur, indicateurs, preuves, droits et
 * entités. Données fictives.
 */
final class SecureDevelopmentTest extends GrcmanagerIntegrationTestCase
{
    private function appliance(string $name, int $entityId = 0): Appliance
    {
        $appliance = new Appliance();
        $id = (int) $appliance->add(['name' => $name, 'entities_id' => $entityId]);
        $this->assertGreaterThan(0, $id, 'Application fictive enregistrée.');
        $appliance->getFromDB($id);

        return $appliance;
    }

    private function change(string $name, ?Appliance $appliance, int $entityId = 0, array $extra = []): Change
    {
        $change = new Change();
        $id = (int) $change->add($extra + [
            'name'        => $name,
            'content'     => 'Changement fictif',
            'entities_id' => $entityId,
        ]);
        $this->assertGreaterThan(0, $id, 'Changement fictif enregistré.');
        if ($appliance !== null) {
            $this->assertGreaterThan(0, (int) (new Change_Item())->add([
                'changes_id' => $id,
                'itemtype'   => Appliance::class,
                'items_id'   => (int) $appliance->getID(),
            ]));
        }
        $change->getFromDB($id);

        return $change;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function release(Change $change, array $values): void
    {
        global $DB;

        // Mise en production : résolution datée, sans passer par le circuit de solution du cœur.
        $DB->update(Change::getTable(), $values, ['id' => (int) $change->getID()]);
        $change->getFromDB((int) $change->getID());
    }

    private function tickAll(Change $change): void
    {
        $this->assertNull(ReleaseValidation::saveChecklist((int) $change->getID(), [
            'tests_passed' => '1', 'review_done' => '1', 'vulnerabilities_treated' => '1',
            'checklist_comment' => 'Rapport SAST fictif n° 12',
        ]));
    }

    public function testSheetIsSanitizedJustifiedAndForgottenWithTheApplication(): void
    {
        $appliance = $this->appliance('PHPUnit portail RH fictif');
        $id = (int) $appliance->getID();

        $error = ApplicationSecurity::saveFor($id, [
            'environments_separated' => 'no', 'separation_justification' => ' ',
        ]);
        $this->assertNotNull($error, 'Non séparé sans justification : refusé.');
        $this->assertNull(ApplicationSecurity::getForAppliance($id));

        $this->assertNull(ApplicationSecurity::saveFor($id, [
            'appliances_id'          => 999999,
            'repository_url'         => 'javascript:alert(1)',
            'framework'              => 'owasp_asvs',
            'framework_detail'       => 'ASVS 4.0.3 niveau 2',
            'code_review_required'   => '1',
            'sast'                   => 'on',
            'dast'                   => '5',
            'environments_separated' => 'maybe',
            'users_id_owner'         => 99999999,
        ]));
        $profile = ApplicationSecurity::getForAppliance($id);
        $this->assertNotNull($profile);
        $this->assertSame($id, (int) $profile['appliances_id'], 'Application du contrôleur, jamais du formulaire.');
        $this->assertSame('', $profile['repository_url']);
        $this->assertSame(Rules::FRAMEWORK_ASVS, $profile['framework']);
        $this->assertSame(1, (int) $profile['code_review_required']);
        $this->assertSame(1, (int) $profile['sast']);
        $this->assertSame(0, (int) $profile['dast']);
        $this->assertSame(Rules::SEPARATION_UNKNOWN, $profile['environments_separated']);
        $this->assertSame(0, (int) $profile['users_id_owner'], 'Responsable inexistant ignoré.');

        $this->assertNull(ApplicationSecurity::saveFor($id, [
            'repository_url'           => 'https://git.example.org/rh/portail',
            'environments_separated'   => 'no',
            'separation_justification' => 'Application fictive sans données réelles.',
        ]));
        $this->assertSame(
            'https://git.example.org/rh/portail',
            ApplicationSecurity::getForAppliance($id)['repository_url'] ?? ''
        );

        $appliance->delete(['id' => $id], true);
        $this->assertNull(ApplicationSecurity::getForAppliance($id), 'Fiche oubliée avec l\'application (ITEM_PURGE).');
    }

    public function testTabsCheckTheirOwnRightsBecauseCommonTabsSkipsTheTabName(): void
    {
        $appliance = $this->appliance('PHPUnit application onglet fictive');
        $change = $this->change('PHPUnit changement onglet fictif', $appliance);
        $unrelated = $this->change('PHPUnit changement sans application', null);

        $this->assertNotSame('', (new ApplicationSecurity())->getTabNameForItem($appliance));
        $this->assertNotSame('', (new ReleaseValidation())->getTabNameForItem($change));
        $this->assertSame('', (new ReleaseValidation())->getTabNameForItem($unrelated), 'Aucune application liée.');

        ob_start();
        $this->assertTrue(ApplicationSecurity::displayTabContentForItem($appliance));
        $this->assertTrue(ReleaseValidation::displayTabContentForItem($change));
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('_glpi_csrf_token', $html);
        $this->assertStringContainsString("name='framework'", $html);
        $this->assertStringContainsString('name="tests_passed"', $html);
        $this->assertStringContainsString('value="approved"', $html, 'Super-Admin : droit de décider.');

        $saved = $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME];
        $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME] = 0;
        try {
            $this->assertSame('', (new ApplicationSecurity())->getTabNameForItem($appliance));
            $this->assertSame('', (new ReleaseValidation())->getTabNameForItem($change));
            ob_start();
            $shownApp = ApplicationSecurity::displayTabContentForItem($appliance);
            $shownChange = ReleaseValidation::displayTabContentForItem($change);
            $this->assertSame('', (string) ob_get_clean());
            $this->assertFalse($shownApp);
            $this->assertFalse($shownChange);
            $this->assertNull(SecureDevelopment::evidence('A.8.25'));
        } finally {
            $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME] = $saved;
        }

        // Lecture seule, sans le droit de décider : ni formulaire de la fiche ni boutons de décision.
        $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME] = READ;
        try {
            ob_start();
            ApplicationSecurity::displayTabContentForItem($appliance);
            ReleaseValidation::displayTabContentForItem($change);
            $html = (string) ob_get_clean();
            $this->assertStringNotContainsString("name='framework'", $html);
            $this->assertStringNotContainsString('name="tests_passed"', $html);
            $this->assertStringNotContainsString('value="approved"', $html);
            $this->assertFalse(ReleaseValidation::canDecide());
        } finally {
            $_SESSION['glpiactiveprofile'][Installer::RIGHT_NAME] = $saved;
        }
    }

    public function testDecisionNeedsTheChecklistIsTracedAndFreezesTheChecklist(): void
    {
        global $DB;

        $appliance = $this->appliance('PHPUnit extranet fictif');
        $change = $this->change('PHPUnit version 2.4 fictive', $appliance);
        $changeId = (int) $change->getID();

        $this->assertNotNull(
            ReleaseValidation::decide($changeId, Rules::DECISION_APPROVED, Rules::ROLE_CISO, ''),
            'Validation refusée sans liste de contrôle complète.'
        );
        $this->assertNotNull(
            ReleaseValidation::decide($changeId, Rules::DECISION_REJECTED, Rules::ROLE_CISO, ''),
            'Refus sans motif.'
        );

        $this->tickAll($change);
        $this->assertNotNull(
            ReleaseValidation::decide($changeId, Rules::DECISION_APPROVED, 'admin', ''),
            'Rôle inconnu.'
        );
        $this->assertNull(
            ReleaseValidation::decide(
                $changeId,
                Rules::DECISION_APPROVED,
                Rules::ROLE_CISO,
                'Tests OK',
                '2026-11-18 10:00:00'
            )
        );

        $validation = ReleaseValidation::getForChange($changeId);
        $this->assertNotNull($validation);
        $this->assertSame(Rules::DECISION_APPROVED, $validation['decision']);
        $this->assertSame(Rules::ROLE_CISO, $validation['validator_role']);
        $this->assertSame((int) $_SESSION['glpiID'], (int) $validation['users_id_decision'], 'Auteur : la session.');
        $this->assertSame('2026-11-18 10:00:00', $validation['date_decision']);
        $logs = $DB->request([
            'FROM'  => 'glpi_logs',
            'WHERE' => [
                'itemtype' => Change::class, 'items_id' => $changeId, 'itemtype_link' => ReleaseValidation::class,
            ],
        ]);
        $this->assertCount(1, $logs, 'Décision inscrite dans l\'historique du changement.');

        $this->assertNotNull(ReleaseValidation::saveChecklist($changeId, ['tests_passed' => '0']), 'Liste figée.');
        $this->assertNotNull(
            ReleaseValidation::decide($changeId, Rules::DECISION_REJECTED, Rules::ROLE_CIO, 'x'),
            'Déjà décidé.'
        );

        $this->assertNull(ReleaseValidation::reopen($changeId));
        $reopened = ReleaseValidation::getForChange($changeId);
        $this->assertSame(Rules::DECISION_PENDING, $reopened['decision'] ?? null);
        $this->assertSame(0, (int) ($reopened['users_id_decision'] ?? -1));
        $this->assertNotNull($reopened);
        $this->assertArrayHasKey('date_decision', $reopened);
        $this->assertNull($reopened['date_decision'], 'Date de décision effacée.');
        $this->assertNull(ReleaseValidation::saveChecklist($changeId, ['tests_passed' => '1']));

        $change->delete(['id' => $changeId], true);
        $this->assertNull(ReleaseValidation::getForChange($changeId), 'Oubliée avec le changement (ITEM_PURGE).');
    }

    public function testIndicatorsAndEvidenceRespectEntities(): void
    {
        $other = $this->createTestEntity(0, 'PHPUnit entité développement B');
        $separated = $this->appliance('PHPUnit appli séparée fictive');
        $shared = $this->appliance('PHPUnit appli partagée fictive');
        $foreign = $this->appliance('PHPUnit appli entité B fictive', $other);
        $notInScope = $this->appliance('PHPUnit appli du marché fictive');

        $this->assertNull(ApplicationSecurity::saveFor((int) $separated->getID(), [
            'framework' => 'owasp_top10', 'code_review_required' => 1, 'sast' => 1, 'dast' => 1, 'dependency_scan' => 1,
            'environments_separated' => 'yes',
        ]));
        $this->assertNull(ApplicationSecurity::saveFor((int) $shared->getID(), [
            'environments_separated' => 'no', 'separation_justification' => 'Risque accepté fictif',
        ]));
        $this->assertNull(ApplicationSecurity::saveFor((int) $foreign->getID(), ['environments_separated' => 'no',
            'separation_justification' => 'Entité B']));

        $now = '2026-11-20 12:00:00';
        $validated = $this->change('PHPUnit MEP validée', $separated);
        $this->tickAll($validated);
        $this->assertNull(ReleaseValidation::decide(
            (int) $validated->getID(),
            Rules::DECISION_APPROVED,
            Rules::ROLE_CIO,
            '',
            '2026-11-09 08:00:00'
        ));
        $this->release($validated, ['status' => Change::SOLVED, 'solvedate' => '2026-11-10 10:00:00']);

        $late = $this->change('PHPUnit MEP validée après coup', $separated);
        $this->tickAll($late);
        $this->assertNull(ReleaseValidation::decide(
            (int) $late->getID(),
            Rules::DECISION_APPROVED,
            Rules::ROLE_CISO,
            '',
            '2026-11-15 08:00:00'
        ));
        $this->release($late, [
            'status' => Change::CLOSED, 'solvedate' => '2026-11-12 10:00:00', 'closedate' => '2026-11-13 10:00:00',
        ]);

        $none = $this->change('PHPUnit MEP sans liste', $shared);
        $this->release($none, ['status' => Change::SOLVED, 'solvedate' => '2026-10-01 10:00:00']);

        $old = $this->change('PHPUnit MEP ancienne', $shared);
        $this->release($old, [
            'status' => Change::CLOSED, 'solvedate' => '2024-01-01 10:00:00', 'closedate' => '2024-01-02 10:00:00',
        ]);
        $ongoing = $this->change('PHPUnit changement en cours', $shared);
        $outOfScope = $this->change('PHPUnit MEP appli du marché', $notInScope);
        $this->release($outOfScope, ['status' => Change::SOLVED, 'solvedate' => '2026-11-01 10:00:00']);
        $foreignRelease = $this->change('PHPUnit MEP entité B', $foreign, $other);
        $this->release($foreignRelease, ['status' => Change::SOLVED, 'solvedate' => '2026-11-01 10:00:00']);

        $names = static fn (array $rows, string $key): array => array_map(
            static fn (array $r): string => (string) $r[$key],
            $rows
        );

        $apps = $names(SecureDevelopment::applications(), 'appliance_name');
        $this->assertContains('PHPUnit appli séparée fictive', $apps);
        $this->assertContains('PHPUnit appli entité B fictive', $apps);
        $this->assertNotContains('PHPUnit appli du marché fictive', $apps, 'Sans fiche : hors registre.');

        $releases = array_column(SecureDevelopment::releases($now), 'state', 'name');
        $this->assertSame(Rules::RELEASE_VALIDATED, $releases['PHPUnit MEP validée'] ?? null);
        $this->assertSame(Rules::RELEASE_VALIDATED_LATE, $releases['PHPUnit MEP validée après coup'] ?? null);
        $this->assertSame(Rules::RELEASE_NONE, $releases['PHPUnit MEP sans liste'] ?? null);
        $this->assertArrayNotHasKey('PHPUnit MEP ancienne', $releases, 'Plus de 12 mois.');
        $this->assertArrayNotHasKey('PHPUnit changement en cours', $releases, 'Pas encore en production.');
        $this->assertArrayNotHasKey('PHPUnit MEP appli du marché', $releases);
        $this->assertArrayHasKey('PHPUnit MEP entité B', $releases);
        $this->assertNotNull($ongoing->getID());

        // Hors de l'entité B : ni son application ni sa mise en production.
        $_SESSION['glpiactiveentities'] = array_values(array_diff($_SESSION['glpiactiveentities'], [$other]));
        $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);
        $apps = $names(SecureDevelopment::applications(), 'appliance_name');
        $this->assertNotContains('PHPUnit appli entité B fictive', $apps);
        $releases = array_column(SecureDevelopment::releases($now), 'state', 'name');
        $this->assertArrayNotHasKey('PHPUnit MEP entité B', $releases);

        $mine = array_values(array_filter(
            SecureDevelopment::applications(),
            static fn (array $a): bool => str_starts_with((string) $a['appliance_name'], 'PHPUnit appli')
        ));
        $mineReleases = array_values(array_filter(
            SecureDevelopment::releases($now),
            static fn (array $r): bool => str_starts_with((string) $r['name'], 'PHPUnit')
        ));
        $indicators = SecureDevelopment::indicators($mine, $mineReleases);
        $this->assertSame(2, $indicators['applications']);
        $this->assertSame(1, $indicators['without_separation']);
        $this->assertSame(3, $indicators['releases']);
        $this->assertSame(2, $indicators['releases_without_validation']);

        $evidence = SecureDevelopment::evidence('A.8.31');
        $this->assertNotNull($evidence);
        $this->assertNotEmpty($evidence['badges']);
        $this->assertNull(SecureDevelopment::evidence('A.5.1'));

        // Sans la lecture de tous les changements : aucune mise en production comptée ni listée.
        $saved = $_SESSION['glpiactiveprofile'][Change::$rightname];
        $_SESSION['glpiactiveprofile'][Change::$rightname] = Change::READMY;
        try {
            $this->assertSame([], SecureDevelopment::releases($now));
            $labels = array_column(SecureDevelopment::evidence('A.8.29')['badges'] ?? [], 'icon');
            $this->assertNotContains('ti ti-rocket', $labels);
        } finally {
            $_SESSION['glpiactiveprofile'][Change::$rightname] = $saved;
        }
    }
}

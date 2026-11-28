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

use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\RiskAppetite;
use GlpiPlugin\Grcmanager\RiskEffectiveness;
use GlpiPlugin\Grcmanager\RiskProfiles;
use GlpiPlugin\Grcmanager\RiskReview;
use GlpiPlugin\Grcmanager\RiskTreatmentAction;
use GlpiPlugin\Grcmanager\Services\Risk\TreatmentEffectivenessRules as Rules;

/**
 * Issue #1182 : revues datées avec instantané, risque cible et alerte quand le plan est terminé sans
 * l'atteindre, acceptation signée (jamais par le propriétaire du risque, contrôle côté serveur) avec
 * expiration et renouvellement, profils prêts à attribuer. Aucun texte attendu dans une langue
 * donnée : seules les valeurs et les identifiants sont comparés.
 */
final class RiskTreatmentEffectivenessTest extends GrcmanagerIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $DB;
        $DB->delete(RiskAppetite::getTable(), ['id' => ['>', 0]]);
        RiskAppetite::clearCache();
        $_SESSION['glpi_currenttime'] ??= date('Y-m-d H:i:s');
    }

    protected function tearDown(): void
    {
        RiskAppetite::clearCache();
        parent::tearDown();
    }

    /** @param array<string, mixed> $extra */
    private function risk(array $extra = []): int
    {
        $id = (int) (new Risk())->add($extra + [
            'title' => 'Risque fictif ' . uniqid(), 'category' => 'technical',
            'probability' => 'certain', 'impact' => 'critical',
        ]);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $risk = new Risk();
        $this->assertTrue($risk->getFromDB($id));

        return $risk->fields;
    }

    public function testColumnsAndReviewTableExist(): void
    {
        global $DB;

        $this->assertTrue($DB->fieldExists(Risk::getTable(), 'target_level'));
        $this->assertTrue($DB->fieldExists(Risk::getTable(), 'date_acceptance_expiry'));
        $this->assertTrue($DB->tableExists(RiskReview::getTable()));
    }

    public function testTargetLevelIsAKnownLevel(): void
    {
        $risk = new Risk();
        $id = $this->risk(['target_level' => 'low']);
        $this->assertSame('low', $this->row($id)['target_level']);
        $risk->update(['id' => $id, 'target_level' => 'extreme']);
        $this->assertSame('', $this->row($id)['target_level']);
    }

    public function testReviewKeepsAServerSideSnapshotAndMovesTheNextReviewDate(): void
    {
        $id = $this->risk([
            'residual_probability' => 'rare', 'residual_impact' => 'high', 'target_level' => 'low',
            'treatment' => 'mitigate',
        ]);
        $next = date('Y-m-d', strtotime('+90 days'));
        $review = new RiskReview();
        $reviewId = (int) $review->add([
            'plugin_grcmanager_risks_id' => $id,
            'comment'                    => 'Constat fictif',
            'next_review_date'           => $next,
            // Valeurs forgées : jamais reprises, l'instantané vient du risque enregistré.
            'risk_level'                 => 'low',
            'computed_score'             => 1,
            'users_id'                   => 99,
        ]);
        $this->assertGreaterThan(0, $reviewId);
        $this->assertTrue($review->getFromDB($reviewId));
        $risk = $this->row($id);
        $this->assertSame($risk['risk_level'], $review->fields['risk_level']);
        $this->assertEqualsWithDelta(
            (float) $risk['computed_score'],
            (float) $review->fields['computed_score'],
            0.001
        );
        $this->assertSame($risk['residual_level'], $review->fields['residual_level']);
        $this->assertSame('low', $review->fields['target_level']);
        $this->assertSame(2, (int) $review->fields['users_id']);
        $this->assertSame('Constat fictif', $review->fields['comment']);
        $this->assertSame($next, substr((string) $risk['review_date'], 0, 10));

        // Instantané figé : jamais modifié.
        $this->assertFalse($review->update(['id' => $reviewId, 'comment' => 'Autre']));
        $this->assertFalse(RiskReview::canUpdate());
        $this->assertFalse(RiskReview::canPurge());
        $this->assertCount(1, RiskReview::forRisk($id));

        // Les revues partent avec leur risque.
        (new Risk())->delete(['id' => $id], true);
        $this->assertSame(0, RiskReview::countForRisk($id));
    }

    public function testReviewIsRefusedWithoutTheRightToUpdateTheRisk(): void
    {
        $id = $this->risk();
        $rights = $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT];
        $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT] = READ;
        try {
            $this->assertFalse(RiskReview::canCreate());
            $this->assertFalse((new RiskReview())->add(['plugin_grcmanager_risks_id' => $id]));
        } finally {
            $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT] = $rights;
        }
        $this->assertSame(0, RiskReview::countForRisk($id));
    }

    public function testCompletedPlanAboveTheTargetRaisesAnAlert(): void
    {
        $id = $this->risk([
            'residual_probability' => 'probable', 'residual_impact' => 'high', 'target_level' => 'low',
            'treatment' => 'mitigate',
        ]);
        $action = new RiskTreatmentAction();
        $actionId = (int) $action->add([
            'plugin_grcmanager_risks_id' => $id, 'description' => 'Action fictive', 'status' => 'in_progress',
        ]);
        $this->assertSame(Rules::PLAN_OPEN, RiskEffectiveness::forRisk($this->row($id))['state']);
        $this->assertNotContains($id, RiskEffectiveness::alertingRiskIds());

        $action->update(['id' => $actionId, 'status' => 'done']);
        $this->assertSame(Rules::NOT_REACHED, RiskEffectiveness::forRisk($this->row($id))['state']);
        $this->assertContains($id, RiskEffectiveness::alertingRiskIds());
        $card = RiskEffectiveness::targetNotReachedCard();
        $this->assertGreaterThanOrEqual(1, $card['number']);

        // Résiduel réévalué au niveau de la cible : plus d'alerte.
        (new Risk())->update(['id' => $id, 'residual_probability' => 'rare', 'residual_impact' => 'low']);
        $this->assertSame(Rules::REACHED, RiskEffectiveness::forRisk($this->row($id))['state']);
        $this->assertNotContains($id, RiskEffectiveness::alertingRiskIds());

        // Sans la lecture du registre : rien.
        $rights = $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT];
        $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT] = 0;
        try {
            $this->assertSame([], RiskEffectiveness::alertingRiskIds());
            $this->assertSame(0, RiskEffectiveness::acceptancesToRenewCount());
        } finally {
            $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT] = $rights;
        }
    }

    public function testTheOwnerOfARiskCannotSignItsAcceptance(): void
    {
        $risk = new Risk();
        // Risque dont la personne connectée (2) est propriétaire : signature refusée côté serveur.
        $own = $this->risk(['users_id' => 2, 'treatment' => 'accept']);
        $this->assertFalse($risk->update(['id' => $own, 'sign_acceptance' => 1]));
        $this->assertSame(0, (int) $this->row($own)['users_id_acceptance']);

        // Au-dessus de l'appétence, l'acceptation formelle est refusée elle aussi au propriétaire.
        RiskAppetite::save(['technical' => [
            'appetite_level' => 'medium', 'tolerance_level' => 'critical', 'statement' => 'Validé en comité',
        ]]);
        $above = $this->risk(['users_id' => 2]);
        $this->assertFalse($risk->update([
            'id' => $above, 'treatment' => 'accept', 'justification' => 'Coût disproportionné',
        ]));
        $this->assertSame('', (string) $this->row($above)['treatment']);

        // Un autre propriétaire : signée par la personne connectée, avec une date d'expiration.
        $owner = $this->createTestUser('Paul', 'Fictif');
        $this->assertTrue($risk->update([
            'id' => $above, 'users_id' => $owner, 'treatment' => 'accept', 'justification' => 'Coût disproportionné',
        ]));
        $row = $this->row($above);
        $this->assertSame(2, (int) $row['users_id_acceptance']);
        $this->assertSame(
            Rules::defaultExpiry(substr((string) $row['date_acceptance'], 0, 10)),
            $row['date_acceptance_expiry']
        );

        // La signature et l'expiration ne viennent jamais de la requête.
        $risk->update(['id' => $above, 'users_id_acceptance' => $owner, 'date_acceptance_expiry' => '2099-01-01']);
        $row = $this->row($above);
        $this->assertSame(2, (int) $row['users_id_acceptance']);
        $this->assertNotSame('2099-01-01', $row['date_acceptance_expiry']);

        // Le signataire devenu propriétaire : refusé, il ne peut pas reprendre l'acceptation à son compte.
        $this->assertFalse($risk->update(['id' => $above, 'users_id' => 2]));
        $this->assertSame($owner, (int) $this->row($above)['users_id']);
    }

    public function testAcceptanceIsSignedOnDemandAndRenewed(): void
    {
        $risk = new Risk();
        $owner = $this->createTestUser('Lina', 'Fictive');
        $id = $this->risk(['users_id' => $owner, 'treatment' => 'accept']);
        // Dans l'appétence (aucune définie), rien n'est signé sans demande.
        $this->assertSame(0, (int) $this->row($id)['users_id_acceptance']);

        $expiry = date('Y-m-d', strtotime('+20 days'));
        $this->assertTrue($risk->update(['id' => $id, 'sign_acceptance' => 1, 'acceptance_expiry' => $expiry]));
        $row = $this->row($id);
        $this->assertSame(2, (int) $row['users_id_acceptance']);
        $this->assertSame($expiry, $row['date_acceptance_expiry']);
        $this->assertSame(Rules::ACCEPTANCE_EXPIRING, RiskEffectiveness::acceptanceState($row));
        $this->assertGreaterThanOrEqual(1, RiskEffectiveness::acceptancesToRenewCount());

        // Renouvellement : nouvelle signature, nouvelle échéance.
        $later = date('Y-m-d', strtotime('+200 days'));
        $this->assertTrue($risk->update(['id' => $id, 'sign_acceptance' => 1, 'acceptance_expiry' => $later]));
        $row = $this->row($id);
        $this->assertSame($later, $row['date_acceptance_expiry']);
        $this->assertSame(Rules::ACCEPTANCE_VALID, RiskEffectiveness::acceptanceState($row));

        // Décision changée : la signature et l'expiration tombent.
        $risk->update(['id' => $id, 'treatment' => 'mitigate']);
        $row = $this->row($id);
        $this->assertSame(0, (int) $row['users_id_acceptance']);
        $this->assertNull($row['date_acceptance_expiry']);
    }

    public function testReadyMadeProfilesGiveOnlyWhatTheirRoleNeeds(): void
    {
        global $DB;

        $audit = RiskProfiles::create('internal_audit');
        $this->assertGreaterThan(0, $audit);
        $this->assertSame($audit, RiskProfiles::create('internal_audit'));
        $rights = [];
        foreach ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => $audit]]) as $row) {
            $rights[$row['name']] = (int) $row['rights'];
        }
        $this->assertSame(READ, $rights[Installer::SENSITIVE_RIGHT]);
        $this->assertSame(READ, $rights[Installer::RIGHT_NAME]);
        // Aucun accès au parc, à l'assistance ni à l'administration.
        foreach (['computer', 'software', 'networking', 'ticket', 'user', 'config', 'profile'] as $core) {
            $this->assertSame(0, $rights[$core] ?? 0, $core);
        }
        foreach ([Installer::GDPR_RIGHT, Installer::CONTINUITY_RIGHT] as $plugin) {
            $this->assertSame(0, $rights[$plugin] ?? 0, $plugin);
        }

        $manager = RiskProfiles::create('risk_manager');
        $managerRight = (int) $DB->request([
            'FROM'  => 'glpi_profilerights',
            'WHERE' => ['profiles_id' => $manager, 'name' => Installer::SENSITIVE_RIGHT],
        ])->current()['rights'];
        $this->assertSame(READ | UPDATE | CREATE | DELETE | PURGE, $managerRight);

        // Sans le droit de créer des profils, ou sans les droits donnés : rien n'est créé.
        $profileRight = $_SESSION['glpiactiveprofile']['profile'];
        $_SESSION['glpiactiveprofile']['profile'] = READ;
        try {
            $this->assertFalse(RiskProfiles::canCreate('risk_owner'));
            $this->assertSame(0, RiskProfiles::create('risk_owner'));
        } finally {
            $_SESSION['glpiactiveprofile']['profile'] = $profileRight;
        }
        $sensitive = $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT];
        $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT] = READ;
        try {
            $this->assertFalse(RiskProfiles::canCreate('risk_committee'));
            $this->assertTrue(RiskProfiles::canCreate('internal_audit'));
        } finally {
            $_SESSION['glpiactiveprofile'][Installer::SENSITIVE_RIGHT] = $sensitive;
        }
        $this->assertSame(0, RiskProfiles::existingId('risk_owner'));
    }
}

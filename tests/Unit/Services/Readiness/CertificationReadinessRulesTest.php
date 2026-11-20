<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Readiness;

use GlpiPlugin\Grcmanager\Services\Clause\IsmsClauseCatalog;
use GlpiPlugin\Grcmanager\Services\Readiness\CertificationReadinessRules as R;
use PHPUnit\Framework\TestCase;

/**
 * Issue #619 : parcours de préparation à la certification, état de chaque étape sur des comptages
 * fictifs, score, prochaine étape.
 */
final class CertificationReadinessRulesTest extends TestCase
{
    public function testStepsFollowThePdcaCycleInOrder(): void
    {
        $previous = 0;
        foreach (R::STEPS as $key => $step) {
            $position = array_search($step['phase'], R::PHASES, true);
            self::assertNotFalse($position, $key);
            self::assertGreaterThanOrEqual($previous, $position, $key . ' : étapes rangées par phase');
            $previous = $position;
            self::assertNotSame('', $step['clauses'], $key);
            foreach ($step['requirements'] as $code) {
                self::assertArrayHasKey($code, IsmsClauseCatalog::SUBCLAUSES, $key . ' : exigence connue');
            }
        }
        self::assertSame('scope', array_key_first(R::STEPS), 'le périmètre d\'abord');
        self::assertSame('nonconformities', array_key_last(R::STEPS));
        $keys = array_keys(R::STEPS);
        self::assertLessThan(array_search('soa', $keys, true), array_search('risk_treatment', $keys, true));
        self::assertLessThan(
            array_search('management_review', $keys, true),
            array_search('internal_audit', $keys, true),
            'audit interne avant la revue de direction'
        );
    }

    public function testScopeUsesTheDeclaredRequirementAndItsDocuments(): void
    {
        self::assertSame(R::STATUS_DONE, R::scope(IsmsClauseCatalog::STATUS_COMPLIANT, 0));
        self::assertSame(R::STATUS_PARTIAL, R::scope(IsmsClauseCatalog::STATUS_IN_PROGRESS, 0));
        self::assertSame(R::STATUS_PARTIAL, R::scope(IsmsClauseCatalog::STATUS_NOT_STARTED, 1), 'document joint');
        self::assertSame(R::STATUS_TODO, R::scope(IsmsClauseCatalog::STATUS_NOT_STARTED, 0));
        self::assertSame(R::STATUS_TODO, R::scope('', 0), 'exigence absente');
    }

    public function testContextNeedsIssuesPartiesAndTheClimateQuestion(): void
    {
        self::assertSame(R::STATUS_TODO, R::context(0, 0, false, 0));
        self::assertSame(R::STATUS_PARTIAL, R::context(3, 0, true, 0), 'aucune partie intéressée');
        self::assertSame(R::STATUS_PARTIAL, R::context(3, 2, false, 0), 'question climatique');
        self::assertSame(R::STATUS_PARTIAL, R::context(3, 2, true, 1), 'revue dépassée');
        self::assertSame(R::STATUS_DONE, R::context(3, 2, true, 0));
    }

    public function testPolicyRolesAndObjectives(): void
    {
        self::assertSame(R::STATUS_TODO, R::policy(0, 0, 0));
        self::assertSame(R::STATUS_PARTIAL, R::policy(0, 1, 0), 'brouillon seulement');
        self::assertSame(R::STATUS_PARTIAL, R::policy(2, 0, 1), 'revue dépassée');
        self::assertSame(R::STATUS_DONE, R::policy(2, 3, 0));

        self::assertSame(R::STATUS_TODO, R::roles(0, 0));
        self::assertSame(R::STATUS_PARTIAL, R::roles(5, 1));
        self::assertSame(R::STATUS_DONE, R::roles(5, 0));

        self::assertSame(R::STATUS_TODO, R::objectives(0, 0));
        self::assertSame(R::STATUS_PARTIAL, R::objectives(3, 2));
        self::assertSame(R::STATUS_DONE, R::objectives(3, 3));
    }

    public function testRiskStepsAndStatementOfApplicability(): void
    {
        self::assertSame(R::STATUS_TODO, R::riskAssessment(0, 0, 0));
        self::assertSame(R::STATUS_PARTIAL, R::riskAssessment(12, 3, 0), '3 risques sans propriétaire');
        self::assertSame(R::STATUS_PARTIAL, R::riskAssessment(12, 0, 2));
        self::assertSame(R::STATUS_DONE, R::riskAssessment(12, 0, 0));

        self::assertSame(R::STATUS_TODO, R::riskTreatment(0, 0, 0, 0));
        self::assertSame(R::STATUS_PARTIAL, R::riskTreatment(4, 1, 0, 0));
        self::assertSame(R::STATUS_PARTIAL, R::riskTreatment(4, 0, 1, 0));
        self::assertSame(R::STATUS_PARTIAL, R::riskTreatment(4, 0, 0, 1));
        self::assertSame(R::STATUS_DONE, R::riskTreatment(4, 0, 0, 0));

        self::assertSame(R::STATUS_DONE, R::soa('up_to_date', false));
        self::assertSame(R::STATUS_DONE, R::soa('up_to_date', true));
        self::assertSame(R::STATUS_PARTIAL, R::soa('changed', false));
        self::assertSame(R::STATUS_PARTIAL, R::soa('never_approved', true), 'demande d\'approbation en cours');
        self::assertSame(R::STATUS_TODO, R::soa('never_approved', false));
    }

    public function testCompetencesNeedFunctionsHoldersPlannedGapsAndAnArchive(): void
    {
        self::assertSame(R::STATUS_TODO, R::competence(0, 0, null, null, 0), 'aucune fonction');
        self::assertSame(R::STATUS_PARTIAL, R::competence(3, 1, 0, 0, 1), 'fonction sans compétence exigée');
        self::assertSame(R::STATUS_PARTIAL, R::competence(3, 0, 1, 0, 1), 'fonction sans titulaire');
        self::assertSame(R::STATUS_PARTIAL, R::competence(3, 0, 0, 2, 1), 'écarts sans formation planifiée');
        self::assertSame(R::STATUS_PARTIAL, R::competence(3, 0, 0, 0, 0), 'dossier jamais archivé');
        self::assertSame(R::STATUS_DONE, R::competence(3, 0, 0, 0, 1));
        self::assertSame(R::STATUS_DONE, R::competence(3, 0, null, null, 2), 'personnes hors des droits');
        $keys = array_keys(R::STEPS);
        self::assertSame(
            array_search('competence', $keys, true) + 1,
            array_search('awareness', $keys, true),
            'compétences (7.2) juste avant la sensibilisation (7.3)'
        );
        self::assertSame('7.2', R::STEPS['competence']['clauses']);
        self::assertSame(['7.3'], R::STEPS['awareness']['requirements']);
    }

    public function testAwarenessAndExercises(): void
    {
        self::assertSame(R::STATUS_TODO, R::awareness(0, 0, null));
        self::assertSame(R::STATUS_PARTIAL, R::awareness(2, 0, 100), 'aucune séance récente');
        self::assertSame(R::STATUS_PARTIAL, R::awareness(2, 1, R::TRAINING_RATE_TARGET - 1));
        self::assertSame(R::STATUS_DONE, R::awareness(2, 1, R::TRAINING_RATE_TARGET));
        self::assertSame(R::STATUS_DONE, R::awareness(2, 1, null), 'aucun participant inscrit');

        self::assertSame(R::STATUS_TODO, R::exercises(0, 0, 0));
        self::assertSame(R::STATUS_PARTIAL, R::exercises(1, 0, 0), 'exercice ancien');
        self::assertSame(R::STATUS_PARTIAL, R::exercises(1, 1, 0), 'bilan non validé');
        self::assertSame(R::STATUS_DONE, R::exercises(1, 1, 1));
    }

    public function testInternalAuditManagementReviewAndNonconformities(): void
    {
        self::assertSame(R::STATUS_TODO, R::internalAudit(false, 0, 0));
        self::assertSame(R::STATUS_PARTIAL, R::internalAudit(false, 1, 0), 'audit prévu');
        self::assertSame(R::STATUS_PARTIAL, R::internalAudit(true, 1, 64), 'couverture incomplète');
        self::assertSame(R::STATUS_DONE, R::internalAudit(true, 1, R::COVERAGE_TARGET));
        self::assertSame(R::STATUS_PARTIAL, R::internalAudit(false, 1, 100), 'audit trop ancien');

        self::assertSame(R::STATUS_TODO, R::managementReview(false, 0, false));
        self::assertSame(R::STATUS_PARTIAL, R::managementReview(false, 1, true), 'revue prévue');
        self::assertSame(R::STATUS_PARTIAL, R::managementReview(true, 1, false), 'sans dossier');
        self::assertSame(R::STATUS_DONE, R::managementReview(true, 1, true));

        self::assertSame(R::STATUS_DONE, R::nonconformities(0, 0, 0), 'rien à traiter');
        self::assertSame(R::STATUS_PARTIAL, R::nonconformities(2, 0, 0));
        self::assertSame(R::STATUS_PARTIAL, R::nonconformities(0, 1, 0));
        self::assertSame(R::STATUS_PARTIAL, R::nonconformities(0, 0, 1));
    }

    public function testScoreCountsPartialStepsHalfAndIgnoresLockedOnes(): void
    {
        self::assertNull(R::score([]));
        self::assertNull(R::score([R::STATUS_LOCKED, R::STATUS_LOCKED]));
        self::assertSame(100, R::score([R::STATUS_DONE, R::STATUS_DONE, R::STATUS_LOCKED]));
        self::assertSame(0, R::score([R::STATUS_TODO, R::STATUS_TODO]));
        self::assertSame(50, R::score([R::STATUS_DONE, R::STATUS_TODO]));
        self::assertSame(50, R::score([R::STATUS_PARTIAL, R::STATUS_PARTIAL]));
        self::assertSame(67, R::score([R::STATUS_DONE, R::STATUS_PARTIAL, R::STATUS_PARTIAL]));
        self::assertNull(R::score(['unknown']), 'statut inconnu ignoré');

        self::assertSame('secondary', R::color(null));
        self::assertSame('red', R::color(49));
        self::assertSame('orange', R::color(50));
        self::assertSame('green', R::color(80));
    }

    public function testCountsNextStepAndDeclaredRequirements(): void
    {
        $statuses = [
            'scope'   => R::STATUS_DONE,
            'context' => R::STATUS_LOCKED,
            'policy'  => R::STATUS_PARTIAL,
            'roles'   => R::STATUS_TODO,
        ];
        self::assertSame(['done' => 1, 'partial' => 1, 'todo' => 1, 'locked' => 1], R::counts($statuses));
        self::assertSame('policy', R::nextStep($statuses), 'étape hors des droits sautée');
        self::assertNull(R::nextStep(['scope' => R::STATUS_DONE, 'context' => R::STATUS_LOCKED]));

        $declared = R::declared(['9.3.1', '9.3.2', '9.9'], [
            '9.3.1' => IsmsClauseCatalog::STATUS_COMPLIANT,
            '9.3.2' => IsmsClauseCatalog::STATUS_IN_PROGRESS,
        ]);
        self::assertSame(['compliant' => 1, 'total' => 2], $declared, 'code absent ignoré');
    }
}

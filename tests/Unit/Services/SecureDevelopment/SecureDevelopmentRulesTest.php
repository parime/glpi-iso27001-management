<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\SecureDevelopment;

use GlpiPlugin\Grcmanager\Services\SecureDevelopment\SecureDevelopmentRules as R;
use PHPUnit\Framework\TestCase;

/**
 * Issue #777 : fiche « développement sécurisé » d'une application et validation de mise en production.
 */
final class SecureDevelopmentRulesTest extends TestCase
{
    public function testSanitizersKeepOnlyKnownValues(): void
    {
        self::assertSame(R::FRAMEWORK_ASVS, R::sanitizeFramework('owasp_asvs'));
        self::assertSame(R::FRAMEWORK_NONE, R::sanitizeFramework('<script>'));
        self::assertSame(R::FRAMEWORK_NONE, R::sanitizeFramework(['owasp_asvs']));
        self::assertSame(R::SEPARATION_NO, R::sanitizeSeparation('no'));
        self::assertSame(R::SEPARATION_UNKNOWN, R::sanitizeSeparation('maybe'));
        self::assertSame(R::ROLE_CISO, R::sanitizeRole('ciso'));
        self::assertSame('', R::sanitizeRole('admin'));
        self::assertSame(1, R::flag('1'));
        self::assertSame(1, R::flag('on'));
        self::assertSame(0, R::flag('2'));
        self::assertSame(0, R::flag(null));
    }

    public function testRepositoryAcceptsOnlyHttpSshOrGitAddresses(): void
    {
        self::assertSame('https://git.example.org/app', R::sanitizeRepository(' https://git.example.org/app '));
        self::assertSame('ssh://git.example.org/app.git', R::sanitizeRepository('ssh://git.example.org/app.git'));
        self::assertSame('git@git.example.org:team/app.git', R::sanitizeRepository('git@git.example.org:team/app.git'));
        self::assertSame('', R::sanitizeRepository('javascript:alert(1)'));
        self::assertSame('', R::sanitizeRepository('https://example.org/"onmouseover="x'));
        self::assertSame('', R::sanitizeRepository('ftp://example.org/app'));
        self::assertTrue(R::isWebLink('https://git.example.org/app'));
        self::assertFalse(R::isWebLink('git@git.example.org:team/app.git'));
    }

    public function testSeparationWithoutJustificationIsReported(): void
    {
        self::assertTrue(R::justificationMissing(R::SEPARATION_NO, '  '));
        self::assertFalse(R::justificationMissing(R::SEPARATION_NO, 'Application interne sans données réelles.'));
        self::assertFalse(R::justificationMissing(R::SEPARATION_YES, ''));
        self::assertTrue(R::withoutSeparation(R::SEPARATION_NO));
        self::assertTrue(R::withoutSeparation(R::SEPARATION_UNKNOWN), 'Non renseignée : comptée comme non séparée.');
        self::assertFalse(R::withoutSeparation(R::SEPARATION_YES));
    }

    public function testSeparationDetailsRequirementsAndOutsourcing(): void
    {
        self::assertSame([], R::separationGaps(['environments_separated' => 'no']), 'Séparation non affirmée.');
        self::assertSame(
            ['separation_data', 'separation_config'],
            R::separationGaps(['environments_separated' => 'yes', 'separation_access' => 1])
        );
        self::assertSame([], R::separationGaps([
            'environments_separated' => 'yes', 'separation_access' => 1, 'separation_data' => 1,
            'separation_config' => 1,
        ]));
        self::assertTrue(R::withoutRequirements(['security_requirements' => '  ']));
        self::assertFalse(R::withoutRequirements(['security_requirements' => 'Authentication with MFA']));
        self::assertTrue(R::withoutArchitecture([]));
        self::assertFalse(R::outsourcingGap(['outsourced' => 0]));
        self::assertTrue(R::outsourcingGap(['outsourced' => 1, 'suppliers_id' => 3, 'outsourcing_clauses' => 0]));
        self::assertTrue(R::outsourcingGap(['outsourced' => 1, 'suppliers_id' => 0, 'outsourcing_clauses' => 1]));
        self::assertFalse(R::outsourcingGap(['outsourced' => 1, 'suppliers_id' => 3, 'outsourcing_clauses' => 1]));
    }

    public function testMissingPractices(): void
    {
        self::assertSame(
            ['framework', 'code_review_required', 'sast', 'dast', 'dependency_scan'],
            R::missingPractices([])
        );
        self::assertSame(['dast'], R::missingPractices([
            'framework' => R::FRAMEWORK_TOP10, 'code_review_required' => 1, 'sast' => 1, 'dast' => 0,
            'dependency_scan' => 1,
        ]));
    }

    public function testApprovalNeedsTheWholeChecklistAndARole(): void
    {
        $partial = ['tests_passed' => 1, 'review_done' => 1, 'vulnerabilities_treated' => 0, 'decision' => ''];
        self::assertSame(['vulnerabilities_treated'], R::uncheckedItems($partial));
        self::assertSame(R::REFUSED_CHECKLIST, R::decisionRefusal($partial, R::DECISION_APPROVED, R::ROLE_CISO, ''));
        self::assertSame(R::REFUSED_ROLE, R::decisionRefusal($partial, R::DECISION_APPROVED, '', ''));

        $complete = ['vulnerabilities_treated' => 1] + $partial;
        self::assertNull(R::decisionRefusal($complete, R::DECISION_APPROVED, R::ROLE_CIO, ''));
        self::assertSame(R::REFUSED_DECISION, R::decisionRefusal($complete, 'maybe', R::ROLE_CIO, ''));
    }

    public function testRejectionNeedsAReasonAndADecisionIsTakenOnce(): void
    {
        $pending = ['tests_passed' => 0, 'decision' => ''];
        self::assertSame(R::REFUSED_REASON, R::decisionRefusal($pending, R::DECISION_REJECTED, R::ROLE_CISO, ' '));
        self::assertNull(
            R::decisionRefusal($pending, R::DECISION_REJECTED, R::ROLE_CISO, 'Vulnérabilité critique ouverte.'),
            'Un refus est possible avec une liste incomplète.'
        );
        self::assertSame(
            R::REFUSED_DECIDED,
            R::decisionRefusal(['decision' => R::DECISION_REJECTED], R::DECISION_APPROVED, R::ROLE_CISO, '')
        );
    }

    public function testReleaseStates(): void
    {
        self::assertSame(R::RELEASE_NONE, R::releaseState(null, '2026-11-10 10:00:00'));
        self::assertSame(R::RELEASE_PENDING, R::releaseState(['decision' => ''], '2026-11-10 10:00:00'));
        self::assertSame(R::RELEASE_REJECTED, R::releaseState(['decision' => 'rejected'], '2026-11-10 10:00:00'));
        $approved = ['decision' => 'approved', 'date_decision' => '2026-11-09 18:00:00'];
        self::assertSame(R::RELEASE_VALIDATED, R::releaseState($approved, '2026-11-10 10:00:00'));
        self::assertSame(R::RELEASE_VALIDATED, R::releaseState($approved, ''), 'Date de mise en production inconnue.');
        self::assertSame(
            R::RELEASE_VALIDATED_LATE,
            R::releaseState(['date_decision' => '2026-11-12 09:00:00'] + $approved, '2026-11-10 10:00:00')
        );
        self::assertFalse(R::withoutValidation(R::RELEASE_VALIDATED));
        foreach ([R::RELEASE_VALIDATED_LATE, R::RELEASE_REJECTED, R::RELEASE_PENDING, R::RELEASE_NONE] as $state) {
            self::assertTrue(R::withoutValidation($state), $state);
        }
    }

    public function testIndicators(): void
    {
        $profiles = [
            ['framework' => 'owasp_asvs', 'code_review_required' => 1, 'sast' => 1, 'dast' => 1,
                'dependency_scan' => 1, 'environments_separated' => 'yes', 'separation_justification' => ''],
            ['framework' => '', 'code_review_required' => 0, 'sast' => 1, 'dast' => 0,
                'dependency_scan' => 0, 'environments_separated' => 'no', 'separation_justification' => ''],
            ['framework' => 'other', 'code_review_required' => 1, 'sast' => 0, 'dast' => 0,
                'dependency_scan' => 1, 'environments_separated' => '', 'separation_justification' => ''],
        ];
        $indicators = R::indicators($profiles, [R::RELEASE_VALIDATED, R::RELEASE_NONE, R::RELEASE_VALIDATED_LATE]);

        self::assertSame(3, $indicators['applications']);
        self::assertSame(2, $indicators['without_separation']);
        self::assertSame(1, $indicators['separation_unjustified']);
        self::assertSame(1, $indicators['without_framework']);
        self::assertSame(1, $indicators['without_review']);
        self::assertSame(1, $indicators['without_sast']);
        self::assertSame(2, $indicators['without_dast']);
        self::assertSame(1, $indicators['without_dependency_scan']);
        self::assertSame(1, $indicators['separation_incomplete'], 'Séparée sans le détail coché.');
        self::assertSame(3, $indicators['without_requirements']);
        self::assertSame(3, $indicators['without_architecture']);
        self::assertSame(0, $indicators['outsourced']);

        $detailed = R::indicators([
            $profiles[0] + ['separation_access' => 1, 'security_requirements' => 'x', 'outsourced' => 1],
            ['outsourced' => 1, 'suppliers_id' => 2, 'outsourcing_clauses' => 1, 'architecture_principles' => 'y'],
        ], []);
        self::assertSame(1, $detailed['separation_incomplete']);
        self::assertSame(1, $detailed['without_requirements']);
        self::assertSame(1, $detailed['without_architecture']);
        self::assertSame(2, $detailed['outsourced']);
        self::assertSame(1, $detailed['outsourced_without_clauses']);
        self::assertSame(3, $indicators['releases']);
        self::assertSame(2, $indicators['releases_without_validation']);
    }
}

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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Context;

use GlpiPlugin\Grcmanager\Services\Context\ContextItemRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #608 : règles du registre du contexte de l'organisation.
 */
final class ContextItemRulesTest extends TestCase
{
    public function testEnumsFallBackToASafeDefault(): void
    {
        $this->assertSame(Rules::KIND_PARTY, Rules::normalizeKind('party'));
        $this->assertSame(Rules::KIND_ISSUE, Rules::normalizeKind('<script>'));
        $this->assertSame('external', Rules::normalizeNature('external'));
        $this->assertSame('internal', Rules::normalizeNature(['external']));
        $this->assertSame(Rules::CATEGORY_CLIMATE, Rules::normalizeCategory('climate_change'));
        $this->assertSame('other', Rules::normalizeCategory('inconnue'));
        $this->assertSame('customer', Rules::normalizePartyType('customer'));
        $this->assertSame('other', Rules::normalizePartyType(null));
        $this->assertSame(Rules::RELEVANCE_TO_ASSESS, Rules::normalizeRelevance('oui'));
    }

    public function testNameIsRequired(): void
    {
        $this->assertSame([Rules::ERROR_NAME], Rules::errors(['name' => '  ', 'kind' => Rules::KIND_ISSUE]));
        $this->assertSame([], Rules::errors(['name' => 'Télétravail généralisé', 'kind' => Rules::KIND_ISSUE]));
    }

    public function testJustificationRequiredWhenNotRetained(): void
    {
        $party = ['name' => 'Clients fictifs PHPUnit', 'kind' => Rules::KIND_PARTY];
        $this->assertSame([], Rules::errors($party + ['relevance' => Rules::RELEVANCE_RETAINED]));
        $this->assertSame(
            [Rules::ERROR_JUSTIFICATION],
            Rules::errors($party + ['relevance' => Rules::RELEVANCE_NOT_RETAINED])
        );
        $this->assertSame([], Rules::errors($party + [
            'relevance' => Rules::RELEVANCE_NOT_RETAINED, 'justification' => 'Aucune donnée échangée.',
        ]));
    }

    public function testClimateNeedsAJustificationWhateverTheAnswer(): void
    {
        $climate = ['name' => 'Changement climatique', 'kind' => Rules::KIND_ISSUE];
        $climate['category'] = Rules::CATEGORY_CLIMATE;
        $this->assertTrue(Rules::isClimate($climate));
        $this->assertFalse(Rules::isClimate(['kind' => Rules::KIND_PARTY, 'category' => Rules::CATEGORY_CLIMATE]));
        $answer = static fn (string $relevance): array => Rules::errors($climate + ['relevance' => $relevance]);
        $this->assertSame([], $answer(Rules::RELEVANCE_TO_ASSESS), 'Pas encore tranché.');
        $this->assertSame([Rules::ERROR_JUSTIFICATION], $answer(Rules::RELEVANCE_RETAINED));
        $this->assertSame([Rules::ERROR_JUSTIFICATION], $answer(Rules::RELEVANCE_NOT_RETAINED));
    }

    public function testClimateAnswered(): void
    {
        $climate = ['kind' => Rules::KIND_ISSUE, 'category' => Rules::CATEGORY_CLIMATE, 'is_deleted' => 0];
        $row = static fn (string $relevance, string $why): array => $climate
            + ['relevance' => $relevance, 'justification' => $why];
        $this->assertFalse(Rules::climateAnswered([]));
        $this->assertFalse(Rules::climateAnswered([$row(Rules::RELEVANCE_TO_ASSESS, 'x')]));
        $this->assertFalse(Rules::climateAnswered([$row(Rules::RELEVANCE_RETAINED, ' ')]));
        $this->assertFalse(
            Rules::climateAnswered([['is_deleted' => 1] + $row(Rules::RELEVANCE_RETAINED, 'x')]),
            'Fiche à la corbeille.'
        );
        $this->assertTrue(Rules::climateAnswered([
            ['kind' => Rules::KIND_ISSUE, 'category' => 'economic', 'relevance' => Rules::RELEVANCE_RETAINED],
            $row(Rules::RELEVANCE_NOT_RETAINED, 'Activité de bureau sans site exposé.'),
        ]));
    }

    public function testOverdueAndClause(): void
    {
        $this->assertTrue(Rules::isOverdue('2026-01-31', '2026-02-01'));
        $this->assertFalse(Rules::isOverdue('2026-02-01', '2026-02-01 10:00:00'));
        $this->assertFalse(Rules::isOverdue(null, '2026-02-01'));
        $this->assertFalse(Rules::isOverdue('', '2026-02-01'));
        $this->assertSame('4.1', Rules::clauseFor(Rules::KIND_ISSUE));
        $this->assertSame('4.2', Rules::clauseFor(Rules::KIND_PARTY));
    }

    public function testNextReviewDateIsOneYearLater(): void
    {
        $this->assertSame('2027-11-19', Rules::nextReviewDate('2026-11-19'));
        $this->assertSame('2027-11-19', Rules::nextReviewDate('2026-11-19 14:30:00'));
        $this->assertSame('2029-03-01', Rules::nextReviewDate('2028-02-29'), 'Année bissextile.');
    }

    public function testLinkFieldFor(): void
    {
        $this->assertSame('_linked_risks', Rules::linkFieldFor('risk'));
        $this->assertSame('_linked_obligations', Rules::linkFieldFor('obligation'));
        $this->assertSame('_linked_controls', Rules::linkFieldFor('control'));
        $this->assertNull(Rules::linkFieldFor('ticket'));
        $this->assertNull(Rules::linkFieldFor(['risk']));
        $this->assertNull(Rules::linkFieldFor(null));
    }

    public function testCsvCellNeutralizesFormulas(): void
    {
        $this->assertSame("'=SUM(A1:A2)", Rules::csvCell('=SUM(A1:A2)'));
        $this->assertSame("'+33 1 00 00 00 00", Rules::csvCell('+33 1 00 00 00 00'));
        $this->assertSame("'-x", Rules::csvCell('-x'));
        $this->assertSame("'@cmd", Rules::csvCell('@cmd'));
        $this->assertSame('Clients fictifs PHPUnit', Rules::csvCell('Clients fictifs PHPUnit'));
        $this->assertSame('12', Rules::csvCell(12));
        $this->assertSame('', Rules::csvCell(null));
    }
}

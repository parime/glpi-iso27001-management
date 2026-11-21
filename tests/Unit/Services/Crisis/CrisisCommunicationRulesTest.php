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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Crisis;

use GlpiPlugin\Grcmanager\Services\Crisis\CrisisCommunicationRules as Rules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #616 : règles de la communication de crise (variables, destinataires, date, preuve).
 */
final class CrisisCommunicationRulesTest extends TestCase
{
    public function testFillReplacesKnownVariablesAndMarksMissingOnes(): void
    {
        $text = 'Incident {reference} : {title}, prochain point {next_update}, {inconnue} {Reference}.';
        $values = ['reference' => 'SEC-2026-0001', 'title' => '  Serveur chiffré '];
        $filled = Rules::fill($text, $values, '[à compléter]');

        $this->assertSame(
            'Incident SEC-2026-0001 : Serveur chiffré, prochain point [à compléter], {inconnue} {Reference}.',
            $filled
        );
    }

    public function testFillKeepsValuesAsPlainText(): void
    {
        // Le remplissage ne transforme rien : l'échappement est fait par Twig à l'affichage.
        $filled = Rules::fill('{title}', ['title' => '<script>alert(1)</script> {reference}'], '?');
        $this->assertSame('<script>alert(1)</script> {reference}', $filled, 'Une valeur n\'est jamais réinterprétée.');
    }

    public function testVariablesInTellsKnownFromUnknown(): void
    {
        $this->assertSame(
            ['known' => ['reference', 'author'], 'unknown' => ['client']],
            Rules::variablesIn('{reference} {client} {author} {reference}')
        );
    }

    public function testSituationOfAnIncidentOrABreach(): void
    {
        $this->assertSame(Rules::SITUATION_DATA_BREACH, Rules::situationFor('other', true));
        $this->assertSame(Rules::SITUATION_DATA_BREACH, Rules::situationFor('data_breach', false));
        $this->assertSame(Rules::SITUATION_UNAVAILABILITY, Rules::situationFor('availability', false));
        $this->assertSame(Rules::SITUATION_RANSOMWARE, Rules::situationFor('malware', false));
        $this->assertSame(Rules::SITUATION_MAJOR_INCIDENT, Rules::situationFor('unauthorized_access', false));
    }

    public function testTemplatesOfTheSituationComeFirstThenByStage(): void
    {
        $sorted = Rules::sortTemplates([
            ['id' => 1, 'name' => 'B fin', 'situation' => 'major_incident', 'stage' => 'end'],
            ['id' => 2, 'name' => 'Rançon', 'situation' => 'ransomware', 'stage' => 'first'],
            ['id' => 3, 'name' => 'A premier', 'situation' => 'major_incident', 'stage' => 'first'],
            ['id' => 4, 'name' => 'Point', 'situation' => 'major_incident', 'stage' => 'update'],
        ], 'major_incident');

        $this->assertSame([3, 4, 1, 2], array_column($sorted, 'id'));
        $this->assertSame([true, true, true, false], array_column($sorted, 'recommended'));
    }

    public function testOnlyOfferedRecipientsAreKept(): void
    {
        $allowed = [Rules::recipientKey('User', 5), Rules::recipientKey(Rules::RECIPIENT_CONTACT, 7)];
        $this->assertSame(
            ['User:5'],
            Rules::selectedRecipients(['User:5', 'User:5', 'User:6', ['x'], 'Group:1'], $allowed)
        );
        $this->assertSame([], Rules::selectedRecipients('User:5', $allowed));
    }

    public function testOtherRecipientsAreOneLineEach(): void
    {
        $this->assertSame(
            ['Presse locale', 'client@example.com'],
            Rules::otherRecipients("  Presse locale \r\n\n client@example.com\nPresse locale")
        );
        $this->assertCount(20, Rules::otherRecipients(implode("\n", range(1, 30))));
    }

    public function testSubjectIsASingleLine(): void
    {
        $this->assertSame('Objet Bcc: x@example.com', Rules::subject("Objet\r\nBcc: x@example.com"));
        $this->assertSame(255, mb_strlen(Rules::subject(str_repeat('é', 300))));
    }

    public function testCommunicationDateIsPastOrNowAndWellFormed(): void
    {
        $now = '2026-11-20 10:00:00';
        $this->assertSame($now, Rules::communicationDate('', $now));
        $this->assertSame('2026-11-20 09:15:00', Rules::communicationDate('2026-11-20T09:15', $now));
        $this->assertSame('2026-11-19 23:59:30', Rules::communicationDate('2026-11-19 23:59:30', $now));
        $this->assertNull(Rules::communicationDate('2026-11-21T08:00', $now), 'Jamais dans le futur.');
        $this->assertNull(Rules::communicationDate('2026-02-30 10:00', $now), 'Date impossible.');
        $this->assertNull(Rules::communicationDate('hier', $now));
    }

    public function testReadinessCountsTemplatesWithoutRecipientsAndMissingSituations(): void
    {
        $summary = Rules::readiness([
            ['situation' => 'major_incident', 'recipients' => 3],
            ['situation' => 'major_incident', 'recipients' => 0],
            ['situation' => 'ransomware', 'recipients' => 1],
        ]);

        $this->assertSame(3, $summary['templates']);
        $this->assertSame(1, $summary['without_recipients']);
        $this->assertSame(['data_breach', 'unavailability'], $summary['missing_situations']);
        $this->assertSame(0, $summary['review_due'], 'Clé facultative.');
        $this->assertSame(0, $summary['without_deputy']);

        $summary = Rules::readiness([
            ['situation' => 'major_incident', 'recipients' => 2, 'review_due' => true, 'without_deputy' => 2],
            ['situation' => 'ransomware', 'recipients' => 1, 'review_due' => false, 'without_deputy' => 1],
        ]);
        $this->assertSame(1, $summary['review_due']);
        $this->assertSame(3, $summary['without_deputy']);
    }

    public function testDeputiesFollowTheirMainRecipient(): void
    {
        $ordered = Rules::orderWithDeputies([
            ['id' => 1, 'deputy_of' => 0],
            ['id' => 2, 'deputy_of' => 3],
            ['id' => 3, 'deputy_of' => 0],
            ['id' => 4, 'deputy_of' => 1],
            ['id' => 5, 'deputy_of' => 99],
        ]);

        $this->assertSame([1, 4, 3, 2, 5], array_column($ordered, 'id'));
        $this->assertSame(0, $ordered[4]['deputy_of'], 'Principal disparu : la ligne redevient principale.');
    }

    public function testDeputyOfADeputyOrOfItselfIsAMainRecipient(): void
    {
        $ordered = Rules::orderWithDeputies([
            ['id' => 1, 'deputy_of' => 0],
            ['id' => 2, 'deputy_of' => 1],
            ['id' => 3, 'deputy_of' => 2],
            ['id' => 4, 'deputy_of' => 4],
        ]);

        $this->assertSame([1, 2, 3, 4], array_column($ordered, 'id'));
        $this->assertSame([0, 1, 0, 0], array_column($ordered, 'deputy_of'));
    }

    public function testMainRecipientsWithoutDeputyIgnoreGroups(): void
    {
        $ordered = Rules::orderWithDeputies([
            ['id' => 1, 'deputy_of' => 0, 'itemtype' => 'User'],
            ['id' => 2, 'deputy_of' => 1, 'itemtype' => 'User'],
            ['id' => 3, 'deputy_of' => 0, 'itemtype' => Rules::RECIPIENT_CONTACT],
            ['id' => 4, 'deputy_of' => 0, 'itemtype' => Rules::RECIPIENT_GROUP],
        ]);

        $this->assertSame(1, Rules::principalsWithoutDeputy($ordered));
        $this->assertSame(0, Rules::principalsWithoutDeputy([]));
    }
}

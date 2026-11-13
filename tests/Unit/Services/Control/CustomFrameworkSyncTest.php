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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkCatalog;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkSync;
use GlpiPlugin\Grcmanager\Services\Dashboard\PilotageCards;
use PHPUnit\Framework\TestCase;

/**
 * Issue #537 : réimport par code d'un référentiel personnalisé, et sa place parmi les référentiels.
 */
final class CustomFrameworkSyncTest extends TestCase
{
    protected function tearDown(): void
    {
        CustomFrameworkCatalog::reset();
    }

    /**
     * @param list<string> $annexA
     * @return array{line: int, code: string, theme: string, title: string, description: string, annex_a: list<string>}
     */
    private function row(int $line, string $code, string $title, array $annexA = [], string $theme = 'T'): array
    {
        return ['line' => $line, 'code' => $code, 'theme' => $theme, 'title' => $title, 'description' => '',
            'annex_a' => $annexA];
    }

    /**
     * @return array{code: string, theme: string, title: string, description: string, annex_a: string, rank: int,
     *     is_removed: int}
     */
    private function stored(string $code, string $title, string $annexA, int $rank, int $removed = 0): array
    {
        return ['code' => $code, 'theme' => 'T', 'title' => $title, 'description' => '', 'annex_a' => $annexA,
            'rank' => $rank, 'is_removed' => $removed];
    }

    public function testFirstImportCreatesEverything(): void
    {
        $plan = CustomFrameworkSync::plan([], [$this->row(2, 'A', 'One', ['A.5.1']), $this->row(3, 'B', 'Two')]);

        $this->assertSame([CustomFrameworkSync::CREATE, CustomFrameworkSync::CREATE], array_column($plan, 'action'));
        $this->assertSame('A.5.1', $plan[0]['fields']['annex_a']);
        $this->assertSame(2, $plan[1]['fields']['rank']);
        // Le statut n'est jamais fourni par le fichier.
        $this->assertArrayNotHasKey('status', $plan[0]['fields']);
    }

    public function testNewVersionUpdatesRemovesAndRestoresByCode(): void
    {
        $existing = [
            10 => $this->stored('A', 'One', 'A.5.1', 1),
            11 => $this->stored('B', 'Two', '', 2),
            12 => $this->stored('C', 'Three', '', 3),
            13 => $this->stored('D', 'Old', '', 4, 1),
        ];
        $plan = CustomFrameworkSync::plan($existing, [
            $this->row(2, 'a', 'One', ['A.5.1']),
            $this->row(3, 'B', 'Two changed', ['A.8.5']),
            $this->row(4, 'D', 'Old'),
            $this->row(5, 'E', 'New'),
        ]);
        $byCode = array_column($plan, null, 'code');

        // Code en minuscules : même exigence, rien de visible ne change (le code suit le fichier).
        $this->assertSame(10, $byCode['a']['id']);
        $this->assertSame(CustomFrameworkSync::UPDATE, $byCode['a']['action']);
        $this->assertSame(['code'], $byCode['a']['changed']);
        $this->assertSame(CustomFrameworkSync::UPDATE, $byCode['B']['action']);
        $this->assertSame(['title', 'annex_a'], $byCode['B']['changed']);
        $this->assertSame(CustomFrameworkSync::RESTORE, $byCode['D']['action']);
        $this->assertSame(13, $byCode['D']['id']);
        $this->assertSame(CustomFrameworkSync::CREATE, $byCode['E']['action']);
        $this->assertSame(CustomFrameworkSync::REMOVE, $byCode['C']['action']);
        $this->assertSame(12, $byCode['C']['id']);
        $this->assertSame([], $byCode['C']['fields']);

        $this->assertSame(
            ['create' => 1, 'update' => 2, 'restore' => 1, 'unchanged' => 0, 'remove' => 1],
            CustomFrameworkSync::summary($plan)
        );
    }

    public function testSameFileTwiceChangesNothing(): void
    {
        $rows = [$this->row(2, 'A', 'One', ['A.5.1']), $this->row(3, 'B', 'Two')];
        $existing = [
            1 => $this->stored('A', 'One', 'A.5.1', 1),
            2 => $this->stored('B', 'Two', '', 2),
        ];
        $summary = CustomFrameworkSync::summary(CustomFrameworkSync::plan($existing, $rows));

        $this->assertSame(2, $summary['unchanged']);
        $this->assertSame(0, $summary['create'] + $summary['update'] + $summary['remove'] + $summary['restore']);
    }

    public function testAlreadyRemovedRequirementIsNotRemovedAgain(): void
    {
        $plan = CustomFrameworkSync::plan([1 => $this->stored('A', 'One', '', 1, 1)], []);

        $this->assertSame([], $plan);
    }

    public function testAnnexAEncoding(): void
    {
        $this->assertSame('A.5.1,A.8.5', CustomFrameworkSync::encodeAnnexA(['A.5.1', 'A.8.5']));
        $this->assertSame(['A.5.1', 'A.8.5'], CustomFrameworkSync::decodeAnnexA(' A.5.1 ,, A.8.5 '));
        $this->assertSame([], CustomFrameworkSync::decodeAnnexA(''));
    }

    public function testCatalogFeedsTheFrameworkRates(): void
    {
        CustomFrameworkCatalog::set([
            ['id' => 7, 'name' => 'Exigences Démo', 'version' => '2026', 'requirements' => [
                ['code' => 'D-1', 'theme' => 'T', 'title' => 'One', 'annex_a' => ['A.5.1']],
                ['code' => 'D-2', 'theme' => 'T', 'title' => 'Two', 'annex_a' => ['A.5.1', 'A.8.5']],
            ]],
            ['id' => 8, 'name' => 'Exigences Démo', 'version' => '2026', 'requirements' => []],
        ]);

        $this->assertSame(
            ['custom_7' => 'Exigences Démo 2026', 'custom_8' => 'Exigences Démo 2026 (#8)'],
            CustomFrameworkCatalog::labels()
        );
        $this->assertSame(['D-1', 'D-2'], CustomFrameworkCatalog::requirementsForControl('custom_7', 'A.5.1'));
        $this->assertSame([], CustomFrameworkCatalog::requirementsForControl('custom_7', 'A.6.1'));
        $this->assertSame(7, CustomFrameworkCatalog::idFromKey('custom_7'));
        $this->assertNull(CustomFrameworkCatalog::idFromKey('hds'));

        $controls = [
            'A.5.1' => ['applicability' => 'yes', 'implementation_status' => 'implemented'],
            'A.8.5' => ['applicability' => 'yes', 'implementation_status' => 'not_started'],
            'A.6.1' => ['applicability' => 'yes', 'implementation_status' => 'not_started'],
        ];
        $rates = PilotageCards::frameworkRates($controls, ['custom_7', 'custom_8']);
        $this->assertSame(['Exigences Démo 2026', 'Exigences Démo 2026 (#8)'], array_keys($rates));
        $this->assertSame(50.0, (float) $rates['Exigences Démo 2026']);
        $this->assertNull($rates['Exigences Démo 2026 (#8)']);
        // Désactivé : absent des taux, comme un référentiel intégré.
        $this->assertArrayNotHasKey('Exigences Démo 2026', PilotageCards::frameworkRates($controls, ['iso27001']));
    }
}

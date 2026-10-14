<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Clause;

use GlpiPlugin\Grcmanager\Services\Clause\IsmsClauseCatalog;
use PHPUnit\Framework\TestCase;

final class IsmsClauseCatalogTest extends TestCase
{
    public function testThirtySubclausesFromFourOneToTenTwo(): void
    {
        $codes = array_keys(IsmsClauseCatalog::SUBCLAUSES);

        self::assertCount(30, $codes);
        self::assertSame('4.1', $codes[0]);
        self::assertSame('10.2', $codes[29]);
    }

    public function testSubclausesAreInNaturalOrderAndUnderTheirOwnClause(): void
    {
        $codes  = array_map('strval', array_keys(IsmsClauseCatalog::SUBCLAUSES));
        $sorted = $codes;
        usort($sorted, [IsmsClauseCatalog::class, 'compareCodes']);
        self::assertSame($sorted, $codes);

        foreach (IsmsClauseCatalog::SUBCLAUSES as $code => $subclause) {
            self::assertContains($subclause['clause'], IsmsClauseCatalog::CLAUSES, (string) $code);
            self::assertStringStartsWith($subclause['clause'] . '.', (string) $code);
        }
        self::assertSame(
            IsmsClauseCatalog::CLAUSES,
            array_values(array_unique(array_column(IsmsClauseCatalog::SUBCLAUSES, 'clause')))
        );
    }

    public function testLinkedModulesAreExistingPluginItemtypes(): void
    {
        $root = dirname(__DIR__, 4);
        foreach (IsmsClauseCatalog::SUBCLAUSES as $code => $subclause) {
            if ($subclause['module'] === null) {
                continue;
            }
            $shortName = strtolower(substr($subclause['module'], strlen('PluginGrcmanager')));
            $file      = $root . '/inc/' . $shortName . '.class.php';
            self::assertFileExists($file, (string) $code);
        }
    }

    public function testKeyRequirementsLinkToTheirModule(): void
    {
        self::assertSame('PluginGrcmanagerRisk', IsmsClauseCatalog::SUBCLAUSES['6.1.2']['module']);
        self::assertSame('PluginGrcmanagerControl', IsmsClauseCatalog::SUBCLAUSES['6.1.3']['module']);
        self::assertSame('PluginGrcmanagerAudit', IsmsClauseCatalog::SUBCLAUSES['9.2.2']['module']);
        self::assertSame('PluginGrcmanagerNonconformity', IsmsClauseCatalog::SUBCLAUSES['10.2']['module']);
    }

    public function testCompletion(): void
    {
        self::assertSame(
            ['not_started' => 2, 'in_progress' => 1, 'compliant' => 1, 'total' => 4, 'percent' => 25],
            IsmsClauseCatalog::completion(['compliant', 'in_progress', 'not_started', 'bogus'])
        );
        self::assertSame(
            ['not_started' => 0, 'in_progress' => 0, 'compliant' => 0, 'total' => 0, 'percent' => 0],
            IsmsClauseCatalog::completion([])
        );
    }
}

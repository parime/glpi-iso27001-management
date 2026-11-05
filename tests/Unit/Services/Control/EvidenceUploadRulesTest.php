<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;
use PHPUnit\Framework\TestCase;

final class EvidenceUploadRulesTest extends TestCase
{
    public function testSafeNameKeepsOnlyTheLastSegment(): void
    {
        self::assertSame('rapport.pdf', EvidenceUploadRules::safeName('../../etc/rapport.pdf'));
        self::assertSame('rapport.pdf', EvidenceUploadRules::safeName('C:\\Users\\x\\rapport.pdf'));
        self::assertSame('Revue des accès.xlsx', EvidenceUploadRules::safeName("Revue\tdes accès.xlsx"));
        self::assertSame('ab.pdf', EvidenceUploadRules::safeName("a<b>\x00.pdf"));
    }

    public function testSafeNameRefusesNamesWithoutAUsableExtension(): void
    {
        foreach (['', 'rapport', '.pdf', '...', 'rapport.', 'rapport.p-f', '../', 'a.pdf/'] as $name) {
            self::assertNull(EvidenceUploadRules::safeName($name), $name);
        }
        self::assertNull(EvidenceUploadRules::safeName(['a.pdf']));
    }

    public function testSafeNameIsShortened(): void
    {
        $name = (string) EvidenceUploadRules::safeName(str_repeat('é', 400) . '.pdf');
        self::assertSame(EvidenceUploadRules::MAX_NAME_LENGTH, mb_strlen($name));
        self::assertStringEndsWith('.pdf', $name);
    }

    public function testUploadErrors(): void
    {
        self::assertNull(EvidenceUploadRules::uploadError(UPLOAD_ERR_OK, 10, 100));
        self::assertSame(EvidenceUploadRules::ERROR_TOO_LARGE, EvidenceUploadRules::uploadError(UPLOAD_ERR_OK, 101, 100));
        self::assertSame(EvidenceUploadRules::ERROR_TOO_LARGE, EvidenceUploadRules::uploadError(UPLOAD_ERR_INI_SIZE, 0, 100));
        self::assertSame(EvidenceUploadRules::ERROR_EMPTY, EvidenceUploadRules::uploadError(UPLOAD_ERR_OK, 0, 100));
        self::assertSame(EvidenceUploadRules::ERROR_EMPTY, EvidenceUploadRules::uploadError(UPLOAD_ERR_NO_FILE, 0, 100));
        self::assertSame(EvidenceUploadRules::ERROR_FAILED, EvidenceUploadRules::uploadError(UPLOAD_ERR_PARTIAL, 5, 100));
    }

    public function testRealTypeMustMatchTheExtension(): void
    {
        self::assertTrue(EvidenceUploadRules::realTypeMatches('pdf', 'application/pdf', 'application/pdf'));
        self::assertTrue(EvidenceUploadRules::realTypeMatches('PDF', 'application/pdf', null));
        self::assertTrue(EvidenceUploadRules::realTypeMatches('csv', 'text/plain', 'text/csv'));
        self::assertTrue(EvidenceUploadRules::realTypeMatches('docx', 'application/zip', null));
        // Type que GLPI associe à une extension que le plugin ne connaît pas.
        self::assertTrue(EvidenceUploadRules::realTypeMatches('tiff', 'image/tiff', 'image/tiff'));

        self::assertFalse(EvidenceUploadRules::realTypeMatches('pdf', 'text/x-php', 'application/pdf'));
        self::assertFalse(EvidenceUploadRules::realTypeMatches('png', 'application/pdf', 'image/png'));
        self::assertFalse(EvidenceUploadRules::realTypeMatches('tiff', 'image/png', null));
        self::assertFalse(EvidenceUploadRules::realTypeMatches('pdf', '', 'application/pdf'));
    }

    public function testActiveContentIsNeverAccepted(): void
    {
        // Même si un administrateur a rendu le type téléversable dans GLPI.
        self::assertFalse(EvidenceUploadRules::realTypeMatches('html', 'text/html', 'text/html'));
        self::assertFalse(EvidenceUploadRules::realTypeMatches('svg', 'image/svg+xml', 'image/svg+xml'));
        self::assertFalse(EvidenceUploadRules::realTypeMatches('txt', 'text/html', 'text/plain'));
    }
}

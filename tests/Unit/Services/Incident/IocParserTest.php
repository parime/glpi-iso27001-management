<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Incident;

use GlpiPlugin\Grcmanager\Services\Incident\IocParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IocParserTest extends TestCase
{
    #[DataProvider('detectionProvider')]
    public function testDetectsTypeAndNormalizes(string $raw, string $type, string $value): void
    {
        self::assertSame(['type' => $type, 'value' => $value], IocParser::detect($raw));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function detectionProvider(): array
    {
        return [
            'ipv4'              => ['203.0.113.7', 'ipv4', '203.0.113.7'],
            'ipv4 désamorcée'   => ['203[.]0[.]113[.]7', 'ipv4', '203.0.113.7'],
            'ipv6'              => ['2001:DB8::1', 'ipv6', '2001:db8::1'],
            'md5'               => ['D41D8CD98F00B204E9800998ECF8427E', 'md5', 'd41d8cd98f00b204e9800998ecf8427e'],
            'sha1'              => [str_repeat('a', 40), 'sha1', str_repeat('a', 40)],
            'sha256'            => [str_repeat('B', 64), 'sha256', str_repeat('b', 64)],
            'url hxxp'          => ['hxxps://evil[.]example/payload', 'url', 'https://evil.example/payload'],
            'domaine'           => ['Login-Microsoft.example.COM', 'domain', 'login-microsoft.example.com'],
            'e-mail désamorcé'  => ['ceo[at]example[.]org', 'email', 'ceo@example.org'],
            'fichier exécutable' => ['facture.pdf.exe', 'filename', 'facture.pdf.exe'],
            'fichier simple'    => ['payload_v2.dll', 'filename', 'payload_v2.dll'],
        ];
    }

    public function testParseDeduplicatesAndKeepsRejectedLines(): void
    {
        $raw = "203.0.113.7\n\n# commentaire\n203[.]0[.]113[.]7\n";
        $result = IocParser::parse($raw . "not an ioc at all\nevil.example\n");

        self::assertSame(
            [['type' => 'ipv4', 'value' => '203.0.113.7'], ['type' => 'domain', 'value' => 'evil.example']],
            $result['iocs']
        );
        self::assertSame(['not an ioc at all'], $result['rejected']);
    }

    public function testLookupLinksAreEncodedAndAbsentForFiles(): void
    {
        $links = IocParser::lookupLinks('ipv4', '203.0.113.7');
        self::assertSame('https://www.abuseipdb.com/check/203.0.113.7', $links['AbuseIPDB']);
        self::assertStringContainsString(
            rawurlencode('https://evil.example/a?b=c'),
            IocParser::lookupLinks('url', 'https://evil.example/a?b=c')['VirusTotal']
        );
        self::assertSame([], IocParser::lookupLinks('filename', 'a.exe'));
    }
}

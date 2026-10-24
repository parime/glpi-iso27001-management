<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Network;

use GlpiPlugin\Grcmanager\Services\Network\NetworkFlowCsv;
use PHPUnit\Framework\TestCase;

final class NetworkFlowCsvTest extends TestCase
{
    public function testFrenchHeaderWithSemicolons(): void
    {
        $csv = "\xEF\xBB\xBFNom;Type;Source;Destination;Protocole;Ports;Justification;Propriétaire;Date fin\r\n"
            . "Site public;publication;Internet;Serveur web;TCP;80,443;Vitrine;rssi;31/12/2027\r\n"
            . ";sauvegarde;Site A;Site B;tcp;10000-10010;;;\r\n";
        $result = NetworkFlowCsv::parse($csv);

        self::assertSame([], $result['errors']);
        self::assertCount(2, $result['rows']);
        $first = $result['rows'][0];
        self::assertSame('internet_publication', $first['flow_type']);
        self::assertSame('80,443', $first['ports']);
        self::assertSame('rssi', $first['owner']);
        self::assertSame('2027-12-31', $first['date_end']);
        self::assertSame('backup', $result['rows'][1]['flow_type']);
        self::assertSame('Site A → Site B (10000-10010)', $result['rows'][1]['name'], 'Nom construit si absent.');
    }

    public function testEnglishHeaderWithCommasAndQuotedValues(): void
    {
        $csv = "name,source,destination,protocol,ports,justification\n"
            . "\"DNS, resolvers\",LAN,DNS servers,udp,53,\"Name resolution, internal\"\n";
        $result = NetworkFlowCsv::parse($csv);

        self::assertSame([], $result['errors']);
        self::assertSame('DNS, resolvers', $result['rows'][0]['name']);
        self::assertSame('udp', $result['rows'][0]['protocol']);
        self::assertSame('internal', $result['rows'][0]['flow_type'], 'Type absent : flux interne.');
    }

    public function testInvalidLinesAreReportedWithTheirNumber(): void
    {
        $csv = "source;destination;ports;date_fin\n"
            . "A;B;https;\n"
            . ";B;22;\n"
            . "A;B;22;31/02/2027\n"
            . "A;B;22;\n";
        $result = NetworkFlowCsv::parse($csv);

        self::assertSame([
            ['line' => 2, 'message' => 'invalid_ports'],
            ['line' => 3, 'message' => 'missing_endpoint'],
            ['line' => 4, 'message' => 'invalid_date'],
        ], $result['errors']);
        self::assertCount(1, $result['rows']);
        self::assertSame(5, $result['rows'][0]['line']);
    }

    public function testMissingColumnsOrEmptyFile(): void
    {
        $missing = NetworkFlowCsv::parse("nom;ports\nA;22\n");
        self::assertSame([['line' => 1, 'message' => 'missing_columns']], $missing['errors']);
        self::assertSame([['line' => 1, 'message' => 'empty']], NetworkFlowCsv::parse("\n\n")['errors']);
    }
}

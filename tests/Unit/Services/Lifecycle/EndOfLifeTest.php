<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Lifecycle;

use GlpiPlugin\Grcmanager\Services\Lifecycle\CacheRefresh;
use GlpiPlugin\Grcmanager\Services\Lifecycle\CycleMatcher;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeClient;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeException;
use GlpiPlugin\Grcmanager\Services\Lifecycle\ProductMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Issue #704 : fin de vie d'après endoflife.date. Aucun appel réseau : un faux serveur rejoue des
 * réponses enregistrées (fixtures/) ou renvoie le code HTTP voulu.
 */
final class EndOfLifeTest extends TestCase
{
    private const TODAY = '2026-11-18';

    /**
     * Faux serveur endoflife.date : produit => [statut, corps] ; les adresses demandées sont notées.
     *
     * @param array<string, array{0: int, 1: string}> $answers
     * @param list<string> $calls
     */
    private function client(array $answers, array &$calls = []): EndOfLifeClient
    {
        return new EndOfLifeClient(
            'https://eol.example.test/api/v1/products/{product}/',
            static function (string $url) use ($answers, &$calls): array {
                $calls[] = $url;
                preg_match('#/products/([^/]+)/$#', $url, $m);
                [$status, $body] = $answers[rawurldecode($m[1] ?? '')] ?? [404, ''];

                return ['status' => $status, 'body' => $body, 'retry_after' => 0];
            }
        );
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name . '.json');
    }

    /**
     * @return list<array{cycle: string, codename: string, eol: string, latest: string}>
     */
    private static function cycles(string $name): array
    {
        return EndOfLifeClient::parse((array) json_decode(self::fixture($name), true));
    }

    public function testV1ResponseIsValidatedBeforeUse(): void
    {
        $cycles = self::cycles('debian');

        $this->assertSame(
            ['13', '12', '11', '10'],
            array_column($cycles, 'cycle'),
            'Lignes invalides ignorées, caractère de contrôle retiré.'
        );
        $this->assertSame('Bookworm', $cycles[1]['codename']);
        $this->assertSame('2026-06-10', $cycles[1]['eol']);
        $this->assertSame(EndOfLifeClient::EOL_WITHOUT_DATE, $cycles[3]['eol'], 'Fin atteinte sans date publiée.');
        $this->assertSame('12.12', $cycles[1]['latest']);
    }

    public function testLegacyResponseAndInvalidDates(): void
    {
        $cycles = self::cycles('legacy');

        $this->assertSame(['3.13', '3.11', '2.7', '3.9'], array_column($cycles, 'cycle'));
        $this->assertSame(EndOfLifeClient::EOL_WITHOUT_DATE, $cycles[2]['eol']);
        $this->assertSame('', $cycles[3]['eol'], 'Date impossible (30 février) écartée.');
    }

    public function testUnexpectedStructureIsRefused(): void
    {
        $this->expectException(EndOfLifeException::class);
        $this->expectExceptionMessage(EndOfLifeException::UNEXPECTED);
        EndOfLifeClient::parse(['result' => ['releases' => 'nothing']]);
    }

    public function testClientMapsHttpErrorsWithoutNetwork(): void
    {
        $calls = [];
        $client = $this->client([
            'debian' => [200, self::fixture('debian')],
            'busy'   => [429, ''],
            'down'   => [503, ''],
            'html'   => [200, '<html>'],
            'nope'   => [0, ''],
        ], $calls);

        $this->assertCount(4, $client->cycles('debian'));
        $expected = [
            'busy' => 'rate_limited', 'down' => 'server_error', 'html' => 'unexpected',
            'nope' => 'unreachable', 'unknown' => 'not_found',
        ];
        foreach ($expected as $product => $code) {
            try {
                $client->cycles($product);
                $this->fail('Exception attendue pour ' . $product);
            } catch (EndOfLifeException $e) {
                $this->assertSame($code, $e->getMessage());
            }
        }
        $this->assertSame('https://eol.example.test/api/v1/products/debian/', $calls[0]);
        $this->assertSame(6, $client->requests());
    }

    public function testInvalidProductIsNeverSent(): void
    {
        $calls = [];
        $client = $this->client([], $calls);
        try {
            $client->cycles('../admin');
            $this->fail('Identifiant refusé attendu.');
        } catch (EndOfLifeException $e) {
            $this->assertSame(EndOfLifeException::NOT_FOUND, $e->getMessage());
        }
        $this->assertSame([], $calls);
        $this->assertSame(
            'https://mirror.example.test/eol/php.json',
            EndOfLifeClient::productUrl('https://mirror.example.test/eol', 'php')
        );
    }

    public function testAutomaticLinkOnlyWhenTheWholeNameIsKnown(): void
    {
        $this->assertSame('firefox', ProductMatcher::automatic('software', 'Mozilla Firefox (x64 fr)'));
        $this->assertSame('python', ProductMatcher::automatic('software', 'Python 3.11.4 (64-bit)'));
        $this->assertSame('office', ProductMatcher::automatic('software', 'Microsoft Office Professional Plus 2019'));
        $this->assertSame('windows', ProductMatcher::automatic('os', 'Microsoft Windows 11 Professionnel'));
        $this->assertSame('windows-server', ProductMatcher::automatic('os', 'Microsoft Windows Server 2019 Standard'));
        $this->assertSame('debian', ProductMatcher::automatic('os', 'Debian GNU/Linux'));
        $this->assertSame('fortios', ProductMatcher::automatic('firmware', 'FortiOS'));
        // Ressemblance seulement : jamais rapproché.
        $this->assertSame('', ProductMatcher::automatic('software', 'Firefox Helper Toolbar'));
        $this->assertSame('', ProductMatcher::automatic('software', 'DemoSoft PHP Editor'));
        $this->assertSame('', ProductMatcher::automatic('software', 'Debian GNU/Linux'), 'Le type d\'élément compte.');
    }

    public function testManualLinkWinsAndCanStopFollowing(): void
    {
        $manual = [
            ProductMatcher::key('firmware', 'DemoNet SwitchOS') => 'cisco-ios-xe',
            ProductMatcher::key('software', 'mozilla firefox (x64 fr)') => '',
        ];

        $this->assertSame(
            ['product' => 'cisco-ios-xe', 'origin' => 'manual'],
            ProductMatcher::resolve('firmware', 'demonet switchos', $manual)
        );
        $none = ['product' => '', 'origin' => 'none'];
        $this->assertSame($none, ProductMatcher::resolve('software', 'Mozilla Firefox (x64 fr)', $manual));
        $this->assertSame(
            ['product' => 'debian', 'origin' => 'auto'],
            ProductMatcher::resolve('os', 'Debian GNU/Linux', $manual)
        );
        $this->assertSame($none, ProductMatcher::resolve('software', 'DemoSoft Suite', $manual));
    }

    public function testCycleFromInstalledVersion(): void
    {
        $debian = self::cycles('debian');
        $windows = self::cycles('windows');
        $python = self::cycles('legacy');

        $this->assertSame('12', CycleMatcher::match('Debian GNU/Linux', '12.5', $debian)['cycle'] ?? null);
        $this->assertSame('12', CycleMatcher::match('Debian GNU/Linux', '12 (bookworm)', $debian)['cycle'] ?? null);
        $cycle = static fn (string $name, string $version, array $cycles): ?string
            => CycleMatcher::match($name, $version, $cycles)['cycle'] ?? null;
        $this->assertSame('11', $cycle('Debian GNU/Linux', 'bullseye', $debian), 'Nom de code.');
        $this->assertSame('3.11', $cycle('Python 3.11.4 (64-bit)', '3.11.4150.0', $python));
        // Windows : la variante dont la fin est la plus proche (prudence).
        $this->assertSame('11-23h2-w', $cycle('Microsoft Windows 11 Professionnel', '23H2', $windows));
        $this->assertSame('10-22h2', $cycle('Microsoft Windows 10 Entreprise', '22H2', $windows));
        $this->assertNull($cycle('Microsoft Windows 11 Pro', '', $windows), 'Sans version, pas de cycle deviné.');
        $this->assertNull(CycleMatcher::match('Debian GNU/Linux', 'latest', $debian));
        $this->assertSame('', CycleMatcher::nameNumber('Mozilla Firefox (x64 fr)'));
        $this->assertSame('2019', CycleMatcher::nameNumber('Microsoft SQL Server 2019 (64-bit)'));
    }

    public function testStatusFromEndOfSupportDate(): void
    {
        $debian = self::cycles('debian');
        $windows = self::cycles('windows');
        $evaluate = static fn (string $name, string $version, array $cycles, string $product = 'demo'): array
            => CycleMatcher::evaluate($product, $name, $version, $cycles, self::TODAY, 90);

        $this->assertSame('eol', $evaluate('Debian GNU/Linux', '11.9', $debian)['status']);
        $this->assertSame('eol', $evaluate('Debian GNU/Linux', '10.13', $debian)['status'], 'Fin sans date.');
        $this->assertSame('supported', $evaluate('Debian GNU/Linux', '13.1', $debian)['status']);
        $soon = $evaluate('Microsoft Windows 11 Entreprise', '24H2', $windows);
        $this->assertSame(['eol', '11-24h2-w'], [$soon['status'], $soon['cycle']], 'Variante la plus prudente.');

        $short = CycleMatcher::evaluate('debian', 'Debian', '12', $debian, '2026-04-01', 90);
        $this->assertSame('soon', $short['status']);
        $this->assertSame(70, $short['days']);
        $sameDay = CycleMatcher::evaluate('debian', 'Debian', '12', $debian, '2026-06-10', 90);
        $this->assertSame('eol', $sameDay['status'], 'Le jour même : plus supporté.');

        $this->assertSame('no_product', CycleMatcher::evaluate('', 'X', '1', $debian, self::TODAY, 90)['reason']);
        $this->assertSame('not_checked', CycleMatcher::evaluate('debian', 'X', '1', null, self::TODAY, 90)['reason']);
        $notFound = CycleMatcher::evaluate('demo', 'X', '1', [], self::TODAY, 90, true);
        $this->assertSame('product_not_found', $notFound['reason']);
        $unknown = CycleMatcher::evaluate('debian', 'Debian', '99', $debian, self::TODAY, 90);
        $this->assertSame(
            ['unknown', 'no_cycle'],
            [$unknown['status'], $unknown['reason']],
            'Jamais « supporté » par défaut.'
        );
    }

    public function testRefreshUsesCacheAndStopsOnRateLimitWithoutException(): void
    {
        $calls = [];
        $client = $this->client([
            'debian' => [200, self::fixture('debian')],
            'php'    => [429, ''],
            'python' => [200, self::fixture('legacy')],
        ], $calls);
        $cache = [
            'windows' => ['date_success' => '2026-11-18 01:00:00', 'error' => ''],
            'nginx'   => ['date_success' => '2026-11-10 01:00:00', 'error' => ''],
        ];

        $result = CacheRefresh::run(
            ['debian', 'demo-unknown', 'php', 'python', 'windows', 'nginx'],
            $cache,
            $client,
            '2026-11-18 03:00:00'
        );
        $report = $result['report'];

        $this->assertSame(6, $report['products']);
        $this->assertSame(1, $report['fetched']);
        $this->assertSame(1, $report['cached'], 'Lecture de moins d\'un jour : aucun appel.');
        $this->assertSame(1, $report['not_found']);
        $this->assertSame(3, $report['requests'], 'Plus aucun appel après la limite de requêtes.');
        $this->assertSame([
            ['product' => 'php', 'code' => 'rate_limited'],
            ['product' => 'python', 'code' => 'skipped'],
            ['product' => 'nginx', 'code' => 'skipped'],
        ], $report['errors']);
        $writes = array_column($result['writes'], null, 'product');
        $this->assertCount(4, $writes['debian']['cycles'] ?? []);
        $this->assertSame([], $writes['demo-unknown']['cycles'], 'Produit inconnu : enregistré comme tel.');
        $this->assertNull($writes['php']['cycles'], 'Limite : cycles déjà connus gardés.');
        $this->assertFalse($writes['php']['success']);
        $this->assertArrayNotHasKey('python', $writes);
    }

    public function testRefreshBudget(): void
    {
        $client = $this->client([
            'debian' => [200, self::fixture('debian')],
            'python' => [200, self::fixture('legacy')],
        ]);
        $result = CacheRefresh::run(['debian', 'python'], [], $client, '2026-11-18 03:00:00', 1);

        $this->assertSame(1, $result['report']['fetched']);
        $this->assertSame([['product' => 'python', 'code' => 'skipped']], $result['report']['errors']);
    }
}

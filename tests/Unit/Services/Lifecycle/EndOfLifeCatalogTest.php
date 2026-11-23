<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Lifecycle;

use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeCatalog;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeClient;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeException;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1056 : correspondances proposées d'après le catalogue de l'API v1 d'endoflife.date
 * (https://endoflife.date/docs/api/v1/, schéma https://endoflife.date/docs/api/v1/openapi.yml).
 *
 * Faux serveur : fixtures/catalog-*.json sont des extraits des réponses publiques de
 * GET /products, /identifiers/cpe et /identifiers/purl (structure ProductListResponse et
 * IdentifierListResponse du schéma), auxquels sont ajoutées, pour ce test, deux lignes invalides
 * dans catalog-products.json et une ligne vers un produit absent dans catalog-cpe.json.
 */
final class EndOfLifeCatalogTest extends TestCase
{
    /**
     * @return array<mixed>
     */
    private static function fixture(string $name): array
    {
        return (array) json_decode((string) file_get_contents(__DIR__ . '/fixtures/catalog-' . $name . '.json'), true);
    }

    /**
     * @return array<string, array{label: string, category: string,
     *     terms: list<array{term: string, level: int, source: string}>}>
     */
    private static function catalog(): array
    {
        return EndOfLifeCatalog::build(self::fixture('products'), self::fixture('cpe'), self::fixture('purl'));
    }

    /**
     * @param list<array{kind: string, name: string}> $names
     * @param array<string, true> $rejected
     * @return array<string, list<string>> Nom => produits proposés.
     */
    private static function proposed(array $names, array $rejected = []): array
    {
        $out = [];
        foreach (EndOfLifeCatalog::suggest($names, self::catalog(), $rejected) as $row) {
            $out[$row['name']] = array_column($row['candidates'], 'product');
        }

        return $out;
    }

    public function testCatalogIsBuiltFromTheDocumentedFieldsOnly(): void
    {
        $catalog = self::catalog();

        $this->assertArrayNotHasKey('Not A Valid Id!', $catalog, 'Identifiant invalide écarté.');
        $this->assertArrayNotHasKey('not-in-catalogue', $catalog, 'Identifiant vers un produit absent écarté.');
        $this->assertCount(12, $catalog);
        $this->assertSame('Apache HTTP Server', $catalog['apache-http-server']['label']);
        $this->assertSame('server-app', $catalog['apache-http-server']['category']);
        $terms = array_column($catalog['ubuntu']['terms'], 'level', 'term');
        // Exemples du schéma : alias « ubuntu-linux », identifiant « cpe:/o:canonical:ubuntu_linux ».
        $this->assertSame(EndOfLifeCatalog::LEVEL_PRODUCT, $terms['ubuntu linux'], 'Alias, plus sûr que le CPE.');
        $this->assertSame(EndOfLifeCatalog::LEVEL_CPE, $terms['canonical ubuntu linux']);
    }

    public function testUnexpectedProductListIsRefused(): void
    {
        $this->expectException(EndOfLifeException::class);
        EndOfLifeCatalog::build(['result' => ['name' => 'not a list']]);
    }

    public function testCpeAndPurlDecomposition(): void
    {
        $words = EndOfLifeCatalog::cpeWords('cpe:/o:canonical:ubuntu_linux');
        $this->assertSame(['ubuntu_linux', 'canonical ubuntu_linux'], $words);
        $words = EndOfLifeCatalog::cpeWords('cpe:2.3:a:mozilla:firefox:*:*:*:*:*:*:*:*');
        $this->assertSame(['firefox', 'mozilla firefox'], $words);
        $this->assertSame([], EndOfLifeCatalog::cpeWords('cpe:2.3:a:*:*'));
        $this->assertSame([], EndOfLifeCatalog::cpeWords('not-a-cpe'));
        $this->assertSame(['core', 'angular core'], EndOfLifeCatalog::purlWords('pkg:npm/%40angular/core'));
        $words = EndOfLifeCatalog::purlWords('pkg:deb/debian/firefox-esr');
        $this->assertSame(['firefox-esr', 'debian firefox-esr'], $words);
        $this->assertSame(['firefox'], EndOfLifeCatalog::purlWords('pkg:generic/firefox'));
        $this->assertSame(
            ['oraclelinux'],
            EndOfLifeCatalog::purlWords('pkg:oci/oraclelinux?repository_url=ghcr.io/oracle'),
            'Qualificatifs ignorés.'
        );
        $this->assertSame([], EndOfLifeCatalog::purlWords('https://example.test/'));
    }

    public function testProposalsUseTheSafestLevelAndExactNamesOnly(): void
    {
        $names = [
            ['kind' => 'software', 'name' => 'Apache HTTP Server 2.4.62 (x64)'],
            ['kind' => 'os', 'name' => 'Canonical Ubuntu Linux'],
            ['kind' => 'software', 'name' => 'firefox-esr'],
            ['kind' => 'software', 'name' => 'Angular'],
            ['kind' => 'software', 'name' => 'Core'],
            ['kind' => 'os', 'name' => 'Linux'],
            ['kind' => 'software', 'name' => 'Contoso Tool'],
            ['kind' => 'software', 'name' => 'Apache HTTP Server Tools'],
        ];
        $proposed = self::proposed($names);

        $apache = $proposed['Apache HTTP Server 2.4.62 (x64)'];
        $this->assertSame(['apache-http-server'], $apache, 'Version et précision retirées.');
        $this->assertSame(['ubuntu'], $proposed['Canonical Ubuntu Linux'], 'CPE : vendeur et produit.');
        $this->assertSame(['firefox'], $proposed['firefox-esr'], 'purl : nom du paquet.');
        $this->assertSame(['angular'], $proposed['Angular'], 'Le nom du produit passe avant le purl d\'AngularJS.');
        $this->assertArrayNotHasKey('Core', $proposed, 'Terme trop vague (plus de trois produits) : rien.');
        $linux = $proposed['Linux'];
        $this->assertSame(['amazon-linux', 'oracle-linux'], $linux, 'Deux produits : choix laissé à la personne.');
        $this->assertArrayNotHasKey('Contoso Tool', $proposed, 'Nom inconnu : rien.');
        $this->assertArrayNotHasKey('Apache HTTP Server Tools', $proposed, 'Jamais de ressemblance.');
    }

    public function testRejectedProductIsNoLongerProposed(): void
    {
        $names = [['kind' => 'os', 'name' => 'Linux'], ['kind' => 'software', 'name' => 'Linux']];
        $rejected = [EndOfLifeCatalog::rejectKey('os', 'LINUX', 'amazon-linux') => true];

        $rows = EndOfLifeCatalog::suggest($names, self::catalog(), $rejected);
        $products = array_column($rows[0]['candidates'], 'product');
        $this->assertSame(['oracle-linux'], $products, 'Refus propre au nom et au type.');
        $this->assertSame(['amazon-linux', 'oracle-linux'], array_column($rows[1]['candidates'], 'product'));
        $this->assertNotSame($rows[0]['token'], $rows[1]['token']);
        $this->assertSame(EndOfLifeCatalog::token('os', 'Linux'), EndOfLifeCatalog::token('os', 'linux'));

        $all = [
            EndOfLifeCatalog::rejectKey('os', 'Linux', 'amazon-linux')  => true,
            EndOfLifeCatalog::rejectKey('os', 'Linux', 'oracle-linux')  => true,
        ];
        $this->assertCount(1, EndOfLifeCatalog::suggest($names, self::catalog(), $all), 'Tout refusé : plus proposé.');
    }

    public function testCatalogRequestsFollowTheConfiguredSource(): void
    {
        $calls = [];
        $client = new EndOfLifeClient(
            'https://eol.example.test/api/v1/products/{product}/',
            static function (string $url) use (&$calls): array {
                $calls[] = $url;

                return str_ends_with($url, '/identifiers/purl')
                    ? ['status' => 429, 'body' => '', 'retry_after' => 60]
                    : ['status' => 200, 'body' => (string) json_encode(self::fixture('products')), 'retry_after' => 0];
            }
        );

        $this->assertTrue($client->hasCatalog());
        $this->assertCount(14, $client->json('products')['result']);
        try {
            $client->json('identifiers/purl');
            $this->fail('Limite de requêtes signalée.');
        } catch (EndOfLifeException $e) {
            $this->assertSame(EndOfLifeException::RATE_LIMITED, $e->getMessage());
        }
        $this->assertSame(
            ['https://eol.example.test/api/v1/products', 'https://eol.example.test/api/v1/identifiers/purl'],
            $calls
        );
        $this->assertSame(2, $client->requests());
        $this->assertSame(
            'https://endoflife.date/api/v1/identifiers/cpe',
            EndOfLifeClient::listUrl(EndOfLifeClient::DEFAULT_URL, 'identifiers/cpe')
        );
        $this->assertNull(EndOfLifeClient::listUrl(EndOfLifeClient::DEFAULT_URL, '../admin'), 'Chemin refusé.');
        $legacy = new EndOfLifeClient('https://mirror.example.test/{product}.json', static fn (): array => []);
        $this->assertFalse($legacy->hasCatalog(), 'Ancienne API : pas de catalogue.');
    }
}

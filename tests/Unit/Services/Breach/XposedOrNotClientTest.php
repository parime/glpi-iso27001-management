<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Breach;

use GlpiPlugin\Grcmanager\Services\Breach\BreachProvider;
use GlpiPlugin\Grcmanager\Services\Breach\BreachRules;
use GlpiPlugin\Grcmanager\Services\Breach\XposedOrNotClient;
use GlpiPlugin\Grcmanager\Tests\Mock\MockXposedOrNot;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1055 : client XposedOrNot sur le faux serveur bâti sur les exemples de la documentation
 * officielle (aucun appel extérieur).
 */
final class XposedOrNotClientTest extends TestCase
{
    private const JANE = 'jane@grc-xon-test.example';

    /** @var list<int> */
    private array $waits = [];

    private function client(MockXposedOrNot $server, string $key = ''): XposedOrNotClient
    {
        return new XposedOrNotClient($key, $server->transport(), function (int $seconds): void {
            $this->waits[] = $seconds;
        });
    }

    public function testIsABreachProvider(): void
    {
        $client = $this->client(new MockXposedOrNot());

        self::assertInstanceOf(BreachProvider::class, $client);
        self::assertSame('XposedOrNot', $client->name());
    }

    public function testAccountBreachesAreDetailedFromTheCatalogWithoutKey(): void
    {
        $server = new MockXposedOrNot([self::JANE]);
        $response = $this->client($server)->breachedAccount(self::JANE);

        self::assertTrue($response->isOk());
        $breaches = (array) $response->data;
        self::assertCount(3, $breaches);
        $apk = BreachRules::summarize($breaches[0]);
        self::assertNotNull($apk);
        self::assertSame('APK.TW', $apk['breach_name']);
        self::assertSame('apk.tw', $apk['breach_domain']);
        self::assertSame('2022-09-01', substr((string) $apk['breach_date'], 0, 10));
        self::assertTrue(BreachRules::exposesPasswords($apk['data_classes']));
        self::assertSame(1, $apk['is_verified']);
        self::assertSame(['Name' => 'KiwiFarms'], $breaches[2], 'Nom absent du catalogue : gardé tel quel.');

        self::assertSame('GET', $server->calls[0]['method']);
        self::assertSame('/check-email/' . rawurlencode(self::JANE), $server->calls[0]['path']);
        self::assertSame('/breaches', $server->calls[1]['path']);
        self::assertArrayNotHasKey('x-api-key', $server->calls[0]['headers']);
        self::assertSame('GLPI-GRC-Manager', $server->calls[0]['headers']['User-Agent']);
    }

    public function testNotFoundWith404Or200MeansNoBreach(): void
    {
        $client = $this->client(new MockXposedOrNot());

        foreach (['nobody@grc-xon-test.example', MockXposedOrNot::NOT_FOUND_200] as $email) {
            $response = $client->breachedAccount($email);
            self::assertTrue($response->isOk(), $email);
            self::assertSame([], $response->data, $email);
        }
    }

    public function testCatalogIsReadOnceAndRequestsAreSpacedByOneSecond(): void
    {
        $server = new MockXposedOrNot([self::JANE, 'bob@grc-xon-test.example']);
        $client = $this->client($server);
        $client->breachedAccount(self::JANE);
        $client->breachedAccount('bob@grc-xon-test.example');

        $paths = array_column($server->calls, 'path');
        self::assertSame(1, count(array_keys($paths, '/breaches', true)));
        self::assertCount(3, $paths);
        self::assertSame([1, 1], $this->waits, '2 requêtes par seconde au plus : une seconde entre deux.');
    }

    public function testStopsByItselfUnderTheHourlyLimit(): void
    {
        $server = new MockXposedOrNot();
        $client = $this->client($server);
        for ($i = 0; $i < XposedOrNotClient::MAX_ACCOUNTS_PER_RUN; $i++) {
            self::assertTrue($client->breachedAccount("u$i@grc-xon-test.example")->isOk());
        }
        $stopped = $client->breachedAccount('one-more@grc-xon-test.example');

        self::assertTrue($stopped->isRateLimited());
        self::assertGreaterThanOrEqual(3600, $stopped->retryAfter);
        self::assertCount(XposedOrNotClient::MAX_ACCOUNTS_PER_RUN, $server->calls, 'Aucune requête de trop.');
        self::assertLessThan(25, XposedOrNotClient::MAX_ACCOUNTS_PER_RUN);
    }

    public function testServiceLimitIsAStopForTheRun(): void
    {
        $response = $this->client(new MockXposedOrNot([], 'grc-xon-test.example', 0))->breachedAccount(self::JANE);

        self::assertTrue($response->isRateLimited());
        self::assertGreaterThanOrEqual(3600, $response->retryAfter, 'Limite horaire ou journalière.');
    }

    public function testWithoutKeyNoDomainIsVerifiedAndNothingIsPosted(): void
    {
        $server = new MockXposedOrNot([self::JANE]);
        $client = $this->client($server);

        self::assertSame([], $client->subscribedDomains()->data);
        self::assertFalse($client->breachedDomain('grc-xon-test.example')->isOk());
        self::assertSame([], $server->calls);
    }

    public function testDomainKeyListsAliasesWithOneRequest(): void
    {
        $server = new MockXposedOrNot([self::JANE, 'Bob@grc-xon-test.example']);
        $client = $this->client($server, MockXposedOrNot::VALID_KEY);

        self::assertSame(['grc-xon-test.example'], $client->subscribedDomains()->data);
        $aliases = $client->breachedDomain('grc-xon-test.example');
        self::assertTrue($aliases->isOk());
        self::assertSame(['jane' => ['APK.TW'], 'bob' => ['APK.TW']], $aliases->data);
        self::assertSame([], $client->breachedDomain('other.example')->data);

        self::assertCount(1, $server->calls, 'Rapport du domaine lu une seule fois.');
        self::assertSame('POST', $server->calls[0]['method']);
        self::assertSame(MockXposedOrNot::VALID_KEY, $server->calls[0]['headers']['x-api-key']);
        self::assertSame('0', $server->calls[0]['headers']['Content-Length']);
    }

    public function testTestConnectionUsesTheDocumentedExampleAddressOrTheKey(): void
    {
        $server = new MockXposedOrNot();
        self::assertTrue($this->client($server)->testConnection()->isOk());
        self::assertSame('/check-email/' . rawurlencode(XposedOrNotClient::TEST_ACCOUNT), $server->calls[0]['path']);

        $refused = $this->client(new MockXposedOrNot(), 'wrong-key')->testConnection();
        self::assertTrue($refused->isUnauthorized());

        $verified = $this->client(new MockXposedOrNot([self::JANE]), MockXposedOrNot::VALID_KEY)->testConnection();
        self::assertSame(['grc-xon-test.example'], $verified->data);
    }

    public function testInvalidJsonIsAnError(): void
    {
        $client = new XposedOrNotClient(
            '',
            static fn (): array => ['status' => 200, 'body' => 'oops', 'retry_after' => 0]
        );

        self::assertSame(0, $client->breachedAccount(self::JANE)->status);
    }
}

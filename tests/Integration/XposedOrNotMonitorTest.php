<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use Config;
use GlpiPlugin\Grcmanager\BreachExposure;
use GlpiPlugin\Grcmanager\Services\Breach\BreachMonitor;
use GlpiPlugin\Grcmanager\Services\Breach\BreachMonitorConfig;
use GlpiPlugin\Grcmanager\Services\Breach\HibpClient;
use GlpiPlugin\Grcmanager\Services\Breach\XposedOrNotClient;
use GlpiPlugin\Grcmanager\Tests\Mock\MockXposedOrNot;

/**
 * Issue #1055 : surveillance des fuites avec XposedOrNot (faux serveur bâti sur la documentation
 * officielle) et choix du fournisseur dans la configuration.
 */
final class XposedOrNotMonitorTest extends GrcmanagerIntegrationTestCase
{
    private const DOMAIN = 'grc-xon-test.example';

    /** @var array<string, mixed> */
    private array $savedConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedConfig = Config::getConfigurationValues(BreachMonitorConfig::CONTEXT, [
            BreachMonitorConfig::ENABLED_KEY, BreachMonitorConfig::API_KEY, BreachMonitorConfig::DOMAINS_KEY,
            BreachMonitorConfig::CURSOR_KEY, BreachMonitorConfig::PROVIDER_KEY, BreachMonitorConfig::XON_API_KEY,
        ]);
    }

    protected function tearDown(): void
    {
        $restore = [];
        foreach (
            [
                BreachMonitorConfig::ENABLED_KEY, BreachMonitorConfig::API_KEY, BreachMonitorConfig::DOMAINS_KEY,
                BreachMonitorConfig::CURSOR_KEY, BreachMonitorConfig::PROVIDER_KEY, BreachMonitorConfig::XON_API_KEY,
            ] as $key
        ) {
            $restore[$key] = (string) ($this->savedConfig[$key] ?? '');
        }
        Config::setConfigurationValues(BreachMonitorConfig::CONTEXT, $restore);
        parent::tearDown();
    }

    private function userWithEmail(string $email): int
    {
        return $this->createTestUser('Breach', 'Xon', ['_useremails' => [-1 => $email]]);
    }

    private static function client(MockXposedOrNot $server, string $key = ''): XposedOrNotClient
    {
        return new XposedOrNotClient($key, $server->transport(), static function (int $seconds): void {
        });
    }

    public function testAddressByAddressRecordsExposuresWithoutKeyNorPersonalAddress(): void
    {
        $jane = $this->userWithEmail('jane@' . self::DOMAIN);
        $this->userWithEmail('bob@' . self::DOMAIN);
        $this->userWithEmail('someone@gmail.com');
        $server = new MockXposedOrNot(['jane@' . self::DOMAIN]);

        $result = (new BreachMonitor(self::client($server)))->run([self::DOMAIN]);

        self::assertSame('', $result->error);
        self::assertSame(3, $result->created, 'APK.TW, Tesco et KiwiFarms (hors catalogue).');
        $sent = implode(' ', array_column($server->calls, 'path'));
        self::assertStringNotContainsString('gmail', $sent, 'Adresse personnelle jamais envoyée.');
        $rows = (new BreachExposure())->find(['users_id' => $jane, 'breach_name' => 'APK.TW']);
        self::assertCount(1, $rows);
        $row = reset($rows);
        self::assertSame('', $row['email'], 'Adresse non stockée quand un utilisateur la porte.');
        self::assertSame('apk.tw', $row['breach_domain']);
        self::assertStringContainsString('Passwords', (string) $row['data_classes']);

        // Relancé : aucun doublon.
        $again = (new BreachMonitor(self::client(new MockXposedOrNot(['jane@' . self::DOMAIN]))))->run([self::DOMAIN]);
        self::assertSame(0, $again->created);
    }

    public function testRunStopsUnderTheLimitAndResumesAtTheSameUser(): void
    {
        $first = 0;
        for ($i = 0; $i <= XposedOrNotClient::MAX_ACCOUNTS_PER_RUN; $i++) {
            $id = $this->userWithEmail("user$i@" . self::DOMAIN);
            $first = $first === 0 ? $id : $first;
        }

        $result = (new BreachMonitor(self::client(new MockXposedOrNot())))->run([self::DOMAIN]);

        self::assertTrue($result->rateLimited);
        self::assertSame(XposedOrNotClient::MAX_ACCOUNTS_PER_RUN, $result->checked);
        self::assertGreaterThanOrEqual($first, $result->cursor);

        $server = new MockXposedOrNot();
        $next = (new BreachMonitor(self::client($server)))->run([self::DOMAIN], $result->cursor);
        self::assertFalse($next->rateLimited);
        self::assertSame(0, $next->cursor, 'Liste parcourue jusqu\'au bout.');
        self::assertCount(1, $server->calls, 'Seul l\'utilisateur restant est vérifié.');
    }

    public function testVerifiedDomainNeedsOneRequest(): void
    {
        $jane = $this->userWithEmail('jane@' . self::DOMAIN);
        $server = new MockXposedOrNot(['jane@' . self::DOMAIN, 'gone@' . self::DOMAIN]);

        $result = (new BreachMonitor(self::client($server, MockXposedOrNot::VALID_KEY)))->run([self::DOMAIN]);

        self::assertSame('', $result->error);
        self::assertSame(2, $result->created);
        self::assertSame(['/domain-breaches/', '/breaches'], array_column($server->calls, 'path'));
        self::assertCount(1, (new BreachExposure())->find(['users_id' => $jane]));
        self::assertCount(1, (new BreachExposure())->find(['users_id' => 0, 'email' => 'gone@' . self::DOMAIN]));
    }

    public function testProviderChoiceAndReadiness(): void
    {
        Config::setConfigurationValues(BreachMonitorConfig::CONTEXT, [
            BreachMonitorConfig::PROVIDER_KEY => '', BreachMonitorConfig::API_KEY => '',
            BreachMonitorConfig::XON_API_KEY => '', BreachMonitorConfig::CURSOR_KEY => '42',
        ]);
        self::assertSame(BreachMonitorConfig::PROVIDER_XON, BreachMonitorConfig::provider(), 'Gratuit par défaut.');

        BreachMonitorConfig::save(true, self::DOMAIN, '', false, BreachMonitorConfig::PROVIDER_XON);
        self::assertTrue(BreachMonitorConfig::isReady(), 'XposedOrNot tourne sans clé.');
        self::assertInstanceOf(XposedOrNotClient::class, BreachMonitorConfig::client());

        BreachMonitorConfig::save(true, self::DOMAIN, '', false, BreachMonitorConfig::PROVIDER_HIBP);
        self::assertFalse(BreachMonitorConfig::isReady(), 'Have I Been Pwned demande une clé.');
        self::assertSame(0, BreachMonitorConfig::cursor(), 'Changer de fournisseur repart du début.');

        BreachMonitorConfig::save(true, self::DOMAIN, 'hibp-key', false, 'unknown', 'xon-key');
        self::assertSame(BreachMonitorConfig::PROVIDER_HIBP, BreachMonitorConfig::provider(), 'Valeur inconnue.');
        self::assertInstanceOf(HibpClient::class, BreachMonitorConfig::client());
        self::assertTrue(BreachMonitorConfig::hasXonApiKey());
        $stored = Config::getConfigurationValues(BreachMonitorConfig::CONTEXT, [BreachMonitorConfig::XON_API_KEY]);
        self::assertNotSame('xon-key', $stored[BreachMonitorConfig::XON_API_KEY] ?? '', 'Clé chiffrée par GLPI.');

        BreachMonitorConfig::save(true, self::DOMAIN, '', false, '', '', true);
        self::assertFalse(BreachMonitorConfig::hasXonApiKey(), 'Clé effacée sur demande.');
        self::assertTrue(BreachMonitorConfig::hasApiKey(), 'Clé vide : la clé enregistrée est gardée.');
    }
}

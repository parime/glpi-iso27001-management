<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\Feature\FeatureRegistry;
use GlpiPlugin\Grcmanager\Feature\MailDomainSecurityFeature;
use GlpiPlugin\Grcmanager\Install\MailDomainTables;
use GlpiPlugin\Grcmanager\MailDomainSecurity;
use GlpiPlugin\Grcmanager\Services\MailDomain\DnsReader;

/**
 * Issue #855 : sécurité de la messagerie et des domaines. Domaines saisis dans une entité de test,
 * lus par un faux serveur DNS (aucun appel réseau) ; écarts, preuve, KRI et cloisonnement par entité.
 */
final class MailDomainSecurityTest extends GrcmanagerIntegrationTestCase
{
    private int $entity;

    /** @var array<string, mixed> */
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedSession = [
            'glpiactiveentities'        => $_SESSION['glpiactiveentities'],
            'glpiactiveentities_string' => $_SESSION['glpiactiveentities_string'],
            'glpiactive_entity'         => $_SESSION['glpiactive_entity'],
        ];
        $this->entity = $this->createTestEntity(0, 'PhpUnitDemo messagerie ' . random_int(1000, 9999));
        $this->useEntity($this->entity);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            $_SESSION[$key] = $value;
        }
        parent::tearDown();
    }

    private function useEntity(int $entity): void
    {
        $_SESSION['glpiactiveentities'] = [$entity];
        $_SESSION['glpiactiveentities_string'] = self::entitiesString([$entity]);
        $_SESSION['glpiactive_entity'] = $entity;
    }

    /**
     * Faux serveur DNS : enregistrements TXT par nom, MX du domaine, délégation signée ou non.
     *
     * @param array<string, list<string>> $txt
     * @param list<string> $mx
     */
    private static function fakeDns(array $txt, array $mx, ?bool $ds, bool $exists = true): DnsReader
    {
        return new class ($txt, $mx, $ds, $exists) implements DnsReader {
            /** @var list<string> */
            public array $asked = [];

            /**
             * @param array<string, list<string>> $txt
             * @param list<string> $mx
             */
            public function __construct(
                private readonly array $txt,
                private readonly array $mx,
                private readonly ?bool $ds,
                private readonly bool $exists
            ) {
            }

            public function txt(string $name): array
            {
                $this->asked[] = $name;

                return $this->txt[$name] ?? [];
            }

            public function mx(string $name): array
            {
                return $this->mx;
            }

            public function exists(string $name): bool
            {
                return $this->exists;
            }

            public function hasDs(string $name): ?bool
            {
                return $this->ds;
            }
        };
    }

    public function testDomainsAreValidatedCheckedAndRestrictedToTheEntity(): void
    {
        $this->assertTrue((bool) $GLOBALS['DB']->tableExists(MailDomainTables::DOMAINS));
        $this->assertSame([], MailDomainSecurity::domains(), 'module inactif sans domaine');
        $this->assertSame('name', MailDomainSecurity::add(['name' => 'user@example.org'])['error']);

        $weak = MailDomainSecurity::add([
            'name' => 'Example.ORG', 'dkim_selectors' => 'selector1, selector2', 'domain_expiry' => '2000-01-01',
            'certificate_expiry' => 'bad', 'comment' => '<b>main</b>',
        ]);
        $this->assertSame('', $weak['error']);
        $this->assertSame('duplicate', MailDomainSecurity::add(['name' => 'example.org'])['error']);
        $strong = MailDomainSecurity::add([
            'name' => 'example.net', 'dkim_selectors' => 'google', 'domain_expiry' => '2099-01-01',
        ]);
        $this->assertSame('', $strong['error']);

        $domains = MailDomainSecurity::domains();
        $this->assertSame(['example.net', 'example.org'], array_column($domains, 'name'));
        $this->assertNull($domains[1]['certificate_expiry'], 'date invalide ignorée');
        $this->assertSame('selector1, selector2', $domains[1]['dkim_selectors']);

        // Avant lecture : seule l'échéance dépassée est signalée, aucune question DNS posée.
        $overview = MailDomainSecurity::overview();
        $this->assertSame(
            ['total' => 2, 'checked' => 0, 'dmarc_enforced' => 0, 'to_fix' => 1, 'expiring' => 1],
            $overview['summary']
        );

        $weakDns = self::fakeDns([
            'example.org'                       => ['v=spf1 +all', 'some-verification=1'],
            '_dmarc.example.org'                => ['v=DMARC1; p=none'],
            'selector1._domainkey.example.org'  => ['v=DKIM1; p=MIIB'],
        ], ['mx1.example.org'], false);
        $this->assertTrue(MailDomainSecurity::check($weak['id'], $weakDns));
        $this->assertContains('selector2._domainkey.example.org', $weakDns->asked);
        $this->assertContains('_mta-sts.example.org', $weakDns->asked);
        $this->assertContains('_smtp._tls.example.org', $weakDns->asked);

        $strongDns = self::fakeDns([
            'example.net'                   => ['v=spf1 include:_spf.example.net -all'],
            '_dmarc.example.net'            => ['v=DMARC1; p=reject; rua=mailto:dmarc@example.net'],
            '_mta-sts.example.net'          => ['v=STSv1; id=1'],
            '_smtp._tls.example.net'        => ['v=TLSRPTv1; rua=mailto:tls@example.net'],
            'google._domainkey.example.net' => ['v=DKIM1; k=rsa; p=MIIB'],
        ], ['mx.example.net'], true);
        $this->assertTrue(MailDomainSecurity::check($strong['id'], $strongDns));

        $overview = MailDomainSecurity::overview();
        $this->assertSame(
            ['total' => 2, 'checked' => 2, 'dmarc_enforced' => 1, 'to_fix' => 1, 'expiring' => 1],
            $overview['summary']
        );
        $byName = array_column($overview['domains'], null, 'name');
        $this->assertSame('success', $byName['example.net']['worst']);
        $this->assertSame([], $byName['example.net']['findings']);
        $this->assertSame('danger', $byName['example.org']['worst']);
        $this->assertSame(['mx1.example.org'], $byName['example.org']['mx']);
        $this->assertSame(
            ['spf_pass_all', 'dmarc_none', 'dmarc_no_reports', 'dkim_missing', 'dnssec_missing', 'mta_sts_missing',
                'tls_rpt_missing', 'domain_expired'],
            array_column($byName['example.org']['findings'], 'code')
        );
        $this->assertSame('danger', $overview['gaps'][0]['severity'], 'les plus graves d\'abord');
        $this->assertSame('danger', $byName['example.org']['badges']['spf']['color']);
        $this->assertSame('success', $byName['example.net']['badges']['dnssec']['color']);

        // KRI : un domaine lu sur deux sans DMARC appliqué.
        $this->assertSame(50.0, MailDomainSecurity::domainsWithoutDmarcEnforced([$this->entity]));
        $this->assertNull(MailDomainSecurity::domainsWithoutDmarcEnforced([]));

        // Preuve sur A.5.14 et A.8.21 seulement.
        $this->assertNull(MailDomainSecurity::evidence('A.8.13'));
        $evidence = MailDomainSecurity::evidence('A.5.14');
        $this->assertNotNull($evidence);
        $this->assertSame('mail_domains', $evidence['sources'][0]['key']);
        $this->assertSame(
            ['warning', 'danger', 'warning'],
            array_column($evidence['sources'][0]['badges'], 'color')
        );

        // Un changement de sélecteurs efface la dernière lecture ; un autre nom déjà suivi est refusé.
        $this->assertSame('duplicate', MailDomainSecurity::update($weak['id'], ['name' => 'example.net']));
        $this->assertSame('', MailDomainSecurity::update($weak['id'], [
            'name' => 'example.org', 'dkim_selectors' => 'selector1', 'domain_expiry' => '2099-06-30',
        ]));
        $byName = array_column(MailDomainSecurity::overview()['domains'], null, 'name');
        $this->assertSame('', $byName['example.org']['last_check_label']);
        $this->assertSame('secondary', $byName['example.org']['worst'], 'pas de verdict avant la lecture');

        // Autre entité : rien n'est visible, modifiable ni supprimable.
        $other = $this->createTestEntity(0, 'PhpUnitDemo messagerie autre ' . random_int(1000, 9999));
        $this->useEntity($other);
        $this->assertSame([], MailDomainSecurity::domains());
        $this->assertFalse(MailDomainSecurity::check($weak['id'], $weakDns));
        $this->assertSame('not_found', MailDomainSecurity::update($weak['id'], ['name' => 'example.com']));
        $this->assertFalse(MailDomainSecurity::delete($weak['id']));
        $this->assertSame('', MailDomainSecurity::add(['name' => 'example.org'])['error'], 'même nom, autre entité');

        $this->useEntity($this->entity);
        $this->assertTrue(MailDomainSecurity::delete($weak['id']));
        $this->assertSame(['example.net'], array_column(MailDomainSecurity::domains(), 'name'));
    }

    public function testUnresolvedDomainAndRights(): void
    {
        $added = MailDomainSecurity::add(['name' => 'missing.example']);
        $this->assertSame('', $added['error']);
        $this->assertTrue(MailDomainSecurity::check($added['id'], self::fakeDns([], [], null, false)));
        $domain = MailDomainSecurity::overview()['domains'][0];
        $this->assertSame(['unresolved', 'domain_expiry_unknown'], array_column($domain['findings'], 'code'));

        $saved = $_SESSION['glpiactiveprofile'][MailDomainSecurity::RIGHTNAME] ?? 0;
        $_SESSION['glpiactiveprofile'][MailDomainSecurity::RIGHTNAME] = READ;
        try {
            $this->assertSame('rights', MailDomainSecurity::add(['name' => 'example.com'])['error']);
            $this->assertFalse(MailDomainSecurity::check($added['id'], self::fakeDns([], [], null)));
            $this->assertFalse(MailDomainSecurity::delete($added['id']));
            $this->assertSame(0, MailDomainSecurity::checkAll(self::fakeDns([], [], null)));
            $this->assertCount(1, MailDomainSecurity::domains(), 'la lecture reste possible');
        } finally {
            $_SESSION['glpiactiveprofile'][MailDomainSecurity::RIGHTNAME] = $saved;
        }
    }

    public function testDnsServerSetting(): void
    {
        $this->assertNull(MailDomainSecurity::setDnsServer('dns.example.org'), 'adresse IP seulement');
        $this->assertSame('192.0.2.53', MailDomainSecurity::setDnsServer(' 192.0.2.53 '));
        $this->assertSame('192.0.2.53', MailDomainSecurity::dnsServer());
        $this->assertSame('2001:db8::53', MailDomainSecurity::setDnsServer('2001:db8::53'));
        $this->assertSame('', MailDomainSecurity::setDnsServer(''));
        $this->assertSame('', MailDomainSecurity::dnsServer());
    }

    public function testFeatureIsRegistered(): void
    {
        $this->assertArrayHasKey('maildomainsecurity', MailDomainSecurityFeature::pageHelp());
        $this->assertArrayHasKey('mail_domains_without_dmarc', MailDomainSecurityFeature::riskIndicatorSources());
        $this->assertContains(
            MailDomainSecurityFeature::class,
            FeatureRegistry::implementing(\GlpiPlugin\Grcmanager\Feature\ControlEvidenceProvider::class)
        );
    }
}

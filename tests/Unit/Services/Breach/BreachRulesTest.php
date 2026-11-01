<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Services\Breach;

use GlpiPlugin\Grcmanager\Services\Breach\BreachRules;
use PHPUnit\Framework\TestCase;

/**
 * Issue #183 : règles de la surveillance des fuites (domaines, adresses surveillées, minimisation).
 */
final class BreachRulesTest extends TestCase
{
    public function testDomainsAreNormalizedAndInvalidOnesIgnored(): void
    {
        self::assertSame(
            ['example.com', 'sub.example.org'],
            BreachRules::normalizeDomains(" Example.COM, @sub.example.org\nexample.com; not a domain, http://x.y/z, a")
        );
        self::assertSame([], BreachRules::normalizeDomains(''));
    }

    public function testOnlyWorkAddressesAreMonitored(): void
    {
        $domains = ['example.com'];
        self::assertTrue(BreachRules::isMonitored('Jane.Doe@Example.com', $domains));
        self::assertFalse(BreachRules::isMonitored('jane@gmail.com', $domains), 'Adresse personnelle.');
        self::assertFalse(BreachRules::isMonitored('jane@mail.example.com', $domains), 'Sous-domaine non déclaré.');
        self::assertFalse(BreachRules::isMonitored('not-an-address', $domains));
        self::assertFalse(BreachRules::isMonitored('@example.com', $domains));
    }

    public function testSummaryKeepsOnlyWhatIsNeeded(): void
    {
        $summary = BreachRules::summarize([
            'Name'         => 'Adobe',
            'Title'        => 'Adobe',
            'Domain'       => 'adobe.com',
            'BreachDate'   => '2013-10-04',
            'AddedDate'    => '2013-12-04T00:00:00Z',
            'PwnCount'     => 152445165,
            'Description'  => '<p>In October 2013…</p>',
            'DataClasses'  => ['Email addresses', 'Password hints', 'Passwords', 'Usernames'],
            'IsVerified'   => true,
            'IsSpamList'   => false,
        ]);

        self::assertNotNull($summary);
        self::assertSame('Adobe', $summary['breach_name']);
        self::assertSame('2013-10-04 00:00:00', $summary['breach_date']);
        self::assertSame(1, $summary['is_verified']);
        self::assertSame(0, $summary['is_spam_list']);
        self::assertArrayNotHasKey('Description', $summary, 'Pas de description (HTML externe).');
        self::assertArrayNotHasKey('PwnCount', $summary);
        self::assertTrue(BreachRules::exposesPasswords($summary['data_classes']));
    }

    public function testFabricatedRetiredOrNamelessBreachesAreIgnored(): void
    {
        self::assertNull(BreachRules::summarize(['Name' => 'Fake', 'IsFabricated' => true]));
        self::assertNull(BreachRules::summarize(['Name' => 'Old', 'IsRetired' => true]));
        self::assertNull(BreachRules::summarize(['Title' => 'No name']));
    }

    public function testPasswordExposureDetection(): void
    {
        self::assertFalse(BreachRules::exposesPasswords(['Email addresses', 'Names']));
        self::assertTrue(BreachRules::exposesPasswords(['Email addresses', 'Passwords']));
    }
}

<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Unit\Services\Control;

use GlpiPlugin\Grcmanager\Services\Control\DockerInventoryEvidence;
use PHPUnit\Framework\TestCase;

/**
 * Docker Inventory #10 : preuves construites à partir des réponses de l'API du plugin Inventaire
 * Docker (jeu fictif). Assertions sur des nombres, des codes et des noms fictifs, jamais sur des
 * libellés traduits.
 */
final class DockerInventoryEvidenceTest extends TestCase
{
    private const NOW = '2026-03-10 12:00:00';
    private const MINE = 10;
    private const OTHER = 99;

    /** @var array<int, true> */
    private const ALLOWED = [0 => true, self::MINE => true];

    /**
     * Réponses de l'API, au format de GlpiPlugin\Dockerinventory\Api\Evidence (version 1), avec une
     * ligne d'une entité non autorisée dans chaque liste.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function api(): array
    {
        $info = static fn (string $label, string $color): array
            => ['label' => $label, 'color' => $color, 'icon' => 'ti ti-alert-triangle', 'help' => ''];

        return [
            'inventory' => [
                'services' => 4, 'retired_services' => 1, 'stacks' => 2, 'nodes' => 3,
                'services_url' => '/plugins/dockerinventory/front/service.php',
                'can_view_connections' => true,
                'connections' => [
                    ['name' => 'portainer-fictif', 'type_label' => 'Portainer', 'entities_id' => self::MINE,
                        'last_success_date' => '2026-03-10 08:00:00', 'url' => '/c?id=1'],
                    ['name' => 'wud-fictif', 'type_label' => 'WUD', 'entities_id' => 0,
                        'last_success_date' => '2026-03-01 08:00:00', 'url' => 'javascript:alert(1)'],
                    ['name' => 'harbor-fictif', 'type_label' => 'Harbor', 'entities_id' => self::MINE,
                        'last_success_date' => '', 'url' => '/c?id=3'],
                    ['name' => 'portainer-autre-entite', 'type_label' => 'Portainer', 'entities_id' => self::OTHER,
                        'last_success_date' => '2026-03-10 08:00:00', 'url' => '/c?id=4'],
                ],
            ],
            'gaps' => [
                'counts' => ['major' => 1, 'minor' => 2, 'patch' => 3, 'none' => 5],
                'gaps' => ['major' => $info('Majeure', 'danger'), 'minor' => $info('Mineure', 'warning'),
                    'patch' => $info('Correctif', 'info" onmouseover="x')],
                'outdated' => [
                    ['service' => 'boutique_web', 'url' => '/s?id=1', 'stack' => 'boutique', 'image' => 'nginx',
                        'deployed_tag' => '1.25.0', 'latest_tag' => '2.0.0', 'gap' => 'major',
                        'entities_id' => self::MINE],
                    ['service' => 'service-autre-entite', 'url' => '/s?id=9', 'stack' => '', 'image' => 'redis',
                        'deployed_tag' => '6.0', 'latest_tag' => '7.0', 'gap' => 'major', 'entities_id' => self::OTHER],
                    ['service' => 'sans-entite', 'gap' => 'patch'],
                ],
                'outdated_total' => 6,
                'page_url' => '/v',
            ],
            'vulnerabilities' => [
                'has_harbor' => true,
                'counts' => ['critical' => 1, 'high' => 1, 'medium' => 0, 'low' => 0, 'none' => 2, '' => 1],
                'severities' => ['critical' => $info('Critique', 'danger'), 'high' => $info('Haute', 'orange')],
                'services' => [
                    ['service' => 'api_haute', 'url' => '/s?id=2', 'stack' => 'boutique', 'image' => 'api',
                        'tag' => '1.0', 'severity' => 'high', 'critical' => 0, 'high' => 4, 'fixable' => 2,
                        'entities_id' => self::MINE],
                    ['service' => 'web_critique', 'url' => '/s?id=1', 'stack' => 'boutique', 'image' => 'web',
                        'tag' => '2.4', 'severity' => 'critical', 'critical' => 3, 'high' => 1, 'fixable' => 3,
                        'entities_id' => 0],
                    ['service' => 'critique-autre-entite', 'url' => '/s?id=9', 'stack' => '', 'image' => 'x',
                        'tag' => '1', 'severity' => 'critical', 'critical' => 9, 'high' => 9, 'fixable' => 0,
                        'entities_id' => self::OTHER],
                ],
                'page_url' => '/h',
            ],
            'configuration' => [
                'services' => [
                    ['service' => 'boutique_web', 'url' => '/s?id=1', 'stack' => 'boutique',
                        'image' => 'registry.exemple.test/boutique/web', 'tag' => '2.4.1', 'digest' => '1f1e1d1c1b1a',
                        'replicas' => '3 / 3', 'replicas_ok' => true, 'ports' => ['443:8443/tcp', ['x']],
                        'provenance' => 'managed', 'entities_id' => self::MINE],
                    ['service' => 'config-autre-entite', 'url' => '/s?id=9', 'stack' => '', 'image' => 'redis',
                        'tag' => '7', 'digest' => '', 'replicas' => '1 / 1', 'replicas_ok' => true, 'ports' => [],
                        'provenance' => 'unmanaged', 'entities_id' => self::OTHER],
                ],
                'services_total' => 4,
                'provenance_counts' => ['managed' => 3, 'unmanaged' => 1, 'unknown' => 0],
                'provenances' => ['managed' => $info('Registre maîtrisé', 'success')],
                'services_url' => '/s',
            ],
            'changes' => [
                'changes' => [
                    ['date' => '2026-03-09 10:00:00', 'service' => 'boutique_web', 'url' => '/s?id=1',
                        'stack' => 'boutique', 'kind' => 'tag', 'kind_label' => 'Nouveau tag',
                        'old_image' => 'nginx', 'new_image' => 'nginx', 'old_tag' => '1.25.0', 'new_tag' => '1.25.1',
                        'old_digest' => 'aaaaaaaaaaaa', 'new_digest' => 'bbbbbbbbbbbb', 'entities_id' => self::MINE],
                    ['date' => '2026-03-08 10:00:00', 'service' => 'proxy', 'url' => '', 'stack' => '',
                        'kind' => 'digest', 'kind_label' => 'Reconstruite', 'old_image' => 'caddy',
                        'new_image' => 'caddy', 'old_tag' => 'latest', 'new_tag' => 'latest',
                        'old_digest' => 'cccccccccccc', 'new_digest' => 'dddddddddddd', 'entities_id' => self::MINE],
                    ['date' => '2026-03-07 10:00:00', 'service' => 'change-autre-entite', 'url' => '/s?id=9',
                        'stack' => '', 'kind' => 'tag', 'kind_label' => 'Nouveau tag', 'old_image' => 'x',
                        'new_image' => 'x', 'old_tag' => '1', 'new_tag' => '2', 'old_digest' => '',
                        'new_digest' => '', 'entities_id' => self::OTHER],
                ],
                'total' => 2,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evidence(string $code): array
    {
        $evidence = DockerInventoryEvidence::build($code, self::api(), self::ALLOWED, self::NOW);
        $this->assertIsArray($evidence);

        return $evidence;
    }

    public function testTheFourAnnexAControlsAreCovered(): void
    {
        foreach (['A.5.9', 'A.8.8', 'A.8.9', 'A.8.32'] as $code) {
            $this->assertTrue(DockerInventoryEvidence::isRelevantControl($code), $code);
        }
        $this->assertFalse(DockerInventoryEvidence::isRelevantControl('A.8.16'));
        $this->assertNull(DockerInventoryEvidence::build('A.8.16', self::api(), self::ALLOWED, self::NOW));
        $this->assertSame(['gaps', 'vulnerabilities'], array_keys($this->evidence('A.8.8')));
    }

    public function testWithoutAnyApiAnswerNothingIsShown(): void
    {
        foreach (['A.5.9', 'A.8.8', 'A.8.9', 'A.8.32'] as $code) {
            $this->assertNull(DockerInventoryEvidence::build($code, [], self::ALLOWED, self::NOW), $code);
            $this->assertNull(DockerInventoryEvidence::build(
                $code,
                array_fill_keys(DockerInventoryEvidence::sectionsFor($code), null),
                self::ALLOWED,
                self::NOW
            ), $code);
        }
    }

    public function testTheApiVersionIsChecked(): void
    {
        $this->assertTrue(DockerInventoryEvidence::isSupportedApiVersion(1));
        $this->assertTrue(DockerInventoryEvidence::isSupportedApiVersion(2));
        $this->assertFalse(DockerInventoryEvidence::isSupportedApiVersion(0));
        $this->assertFalse(DockerInventoryEvidence::isSupportedApiVersion('1'));
        $this->assertFalse(DockerInventoryEvidence::isSupportedApiVersion(null));
    }

    public function testInventoryCountsAndLastSuccessfulImportOfEachConnection(): void
    {
        $inventory = $this->evidence('A.5.9')['inventory'];
        $this->assertSame([4, 1, 2, 3], [
            $inventory['services'], $inventory['retired_services'], $inventory['stacks'], $inventory['nodes'],
        ]);
        $this->assertSame(
            ['portainer-fictif', 'wud-fictif', 'harbor-fictif'],
            array_column($inventory['connections'], 'name')
        );
        $this->assertSame(
            [DockerInventoryEvidence::FRESHNESS_FRESH, DockerInventoryEvidence::FRESHNESS_STALE,
                DockerInventoryEvidence::FRESHNESS_NEVER],
            array_column($inventory['connections'], 'freshness')
        );
        $this->assertSame('2026-03-10 08:00:00', $inventory['connections'][0]['last_success_date']);
        $this->assertSame('', $inventory['connections'][1]['url'], 'Lien non http(s) écarté.');
    }

    public function testVersionGapsAndVulnerabilities(): void
    {
        $evidence = $this->evidence('A.8.8');
        $gaps = $evidence['gaps'];
        $this->assertSame(['major' => 1, 'minor' => 2, 'patch' => 3], array_combine(
            array_column($gaps['counts'], 'code'),
            array_column($gaps['counts'], 'count')
        ));
        $this->assertSame('secondary', $gaps['counts'][2]['color'], 'Couleur mal formée remplacée.');
        $this->assertSame(['boutique_web'], array_column($gaps['rows'], 'service'));
        $this->assertSame('2.0.0', $gaps['rows'][0]['latest_tag']);
        $this->assertSame(6, $gaps['total']);

        $vulnerabilities = $evidence['vulnerabilities'];
        $this->assertTrue($vulnerabilities['has_harbor']);
        // Critiques d'abord, puis hautes.
        $this->assertSame(['web_critique', 'api_haute'], array_column($vulnerabilities['rows'], 'service'));
        $this->assertSame(3, $vulnerabilities['rows'][0]['critical']);
        $this->assertSame(2, $vulnerabilities['total']);
        $this->assertSame(1, $vulnerabilities['counts'][0]['count']);
    }

    public function testConfigurationAndShareOfControlledRegistries(): void
    {
        $configuration = $this->evidence('A.8.9')['configuration'];
        $this->assertSame(['boutique_web'], array_column($configuration['rows'], 'service'));
        $row = $configuration['rows'][0];
        $this->assertSame(['2.4.1', '1f1e1d1c1b1a', '3 / 3'], [$row['tag'], $row['digest'], $row['replicas']]);
        $this->assertSame(['443:8443/tcp'], $row['ports']);
        $this->assertSame('managed', $row['provenance']['code']);
        $this->assertSame(75.0, $configuration['managed_share']);
        $this->assertSame([3, 4, 4], [$configuration['managed'], $configuration['evaluated'], $configuration['total']]);

        $this->assertNull(DockerInventoryEvidence::managedShare(['managed' => 0, 'unmanaged' => 0, 'unknown' => 5]));
        $this->assertSame(100.0, DockerInventoryEvidence::managedShare(['managed' => 2]));
    }

    public function testVersionChangesOverThePeriod(): void
    {
        $changes = $this->evidence('A.8.32')['changes'];
        $this->assertSame(['boutique_web', 'proxy'], array_column($changes['rows'], 'service'));
        $this->assertSame('nginx:1.25.0', $changes['rows'][0]['old']);
        $this->assertSame('nginx:1.25.1', $changes['rows'][0]['new']);
        $this->assertSame('caddy:latest (dddddddddddd)', $changes['rows'][1]['new']);
        $this->assertSame(DockerInventoryEvidence::CHANGE_DAYS, $changes['days']);
        $this->assertSame('2025-12-10 12:00:00', DockerInventoryEvidence::changesSince(self::NOW));
    }

    public function testAnEntityThatIsNotAllowedIsNeverCited(): void
    {
        foreach (['A.5.9', 'A.8.8', 'A.8.9', 'A.8.32'] as $code) {
            $json = (string) json_encode($this->evidence($code));
            $this->assertStringNotContainsString('autre-entite', $json, $code);
            $this->assertStringNotContainsString('?id=9', $json, $code);
            $this->assertStringNotContainsString('?id=4', $json, $code);
        }
        // Sans aucune entité autorisée, aucune ligne n'est citée (seuls les compteurs de l'API restent).
        $evidence = DockerInventoryEvidence::build('A.8.8', self::api(), [], self::NOW);
        $this->assertIsArray($evidence);
        $this->assertSame([], $evidence['gaps']['rows']);
        $this->assertSame([], $evidence['vulnerabilities']['rows']);
    }
}

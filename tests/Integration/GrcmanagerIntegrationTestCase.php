<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Base class for the integration suite (real GLPI Kernel boot, real DB — see
 * tests/integration-bootstrap.php). Each test runs inside a transaction rolled back in tearDown,
 * ported unchanged from the absorbed glpi-security-incidents plugin's own
 * `SecurityIncidentsTestCase` (ROADMAP.md "Version 2.0"), same pattern as the sibling plugin
 * assetsign-glpi's own `AssetsignTestCase` (same author). Not an absolute safety net (a query that
 * implicitly commits, e.g. DDL, would escape the rollback) — never run against a production
 * database.
 */
abstract class GrcmanagerIntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $DB;
        $DB->beginTransaction();

        // tests/integration-bootstrap.php simulates no real session — $_SESSION stays empty.
        // Without native GLPI rights, CommonDBTM::can() always fails, even for a legitimate test.
        // Reproduces the Super-Admin profile's own rights (profiles_id = 4 on a fresh install)
        // rather than enumerating every right this suite happens to touch.
        if (!isset($_SESSION['glpiactiveprofile'])) {
            $_SESSION['glpiactiveprofile'] = ['interface' => 'central'];
            foreach ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => 4]]) as $row) {
                $_SESSION['glpiactiveprofile'][$row['name']] = (int) $row['rights'];
            }
        }
        $_SESSION['glpiactiveentities'] ??= [0];
        $_SESSION['glpiactiveentities_string'] ??= '0';
        $_SESSION['glpiID'] ??= 2; // native "glpi" super-admin account.
        $_SESSION['glpiname'] ??= 'glpi';
    }

    protected function tearDown(): void
    {
        global $DB;
        $DB->rollBack();

        parent::tearDown();
    }

    protected function createTestEntity(int $parentId, string $name): int
    {
        global $DB;

        static $nextId = null;
        if ($nextId === null) {
            $nextId = random_int(500000, 599999);
        }
        $id = $nextId++;

        $DB->insert('glpi_entities', [
            'id'              => $id,
            'name'            => $name,
            'completename'    => $name,
            'entities_id'     => $parentId,
            'level'           => 1,
            'ancestors_cache' => json_encode([0]),
        ]);

        $_SESSION['glpiactiveentities'][] = $id;
        $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);

        return $id;
    }

    protected function createTestUser(string $firstname, string $realname, array $extra = []): int
    {
        return (int) (new \User())->add(array_merge([
            'name'      => strtolower($firstname) . '.' . strtolower($realname) . '.' . random_int(100000, 999999),
            'firstname' => $firstname,
            'realname'  => $realname,
        ], $extra));
    }
}

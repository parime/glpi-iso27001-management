<?php

/**
 * PHPUnit bootstrap for the integration suite (real GLPI Kernel boot, real DB) — ported from the
 * absorbed glpi-security-incidents plugin (ROADMAP.md "Version 2.0"), same mechanism as the
 * sibling plugin assetsign-glpi (same author): GLPI 11 has no lightweight bootstrap any more,
 * `Glpi\Kernel\Kernel` (the same one `bin/console` uses) is the real entry point, reproduced here
 * to get a working DB connection and autoloading without an HTTP context.
 *
 * Deliberately separate from the default `phpunit.xml.dist` (bootstrap `vendor/autoload.php`,
 * testsuite `unit`, no DB/Kernel): the `unit` CI job runs with no live GLPI instance at all, so a
 * shared bootstrap that unconditionally boots the Kernel would break it. Run this suite with
 * `vendor/bin/phpunit -c phpunit-integration.xml.dist`.
 *
 * Must run against a GLPI instance DEDICATED TO TESTS, never production, with this plugin
 * installed and active.
 *
 * GLPI_ROOT_DIR: absolute path to the GLPI root (the folder containing vendor/, src/,
 * bin/console...). Defaults to three levels above this file (tests/ -> plugin root -> plugins/ ->
 * <glpi>/), assuming this plugin lives at <glpi>/plugins/grcmanager/tests/.
 */

$glpiRoot = getenv('GLPI_ROOT_DIR') ?: dirname(__DIR__, 3);

if (!is_file($glpiRoot . '/vendor/autoload.php')) {
    fwrite(
        STDERR,
        "GLPI not found at '$glpiRoot'.\n" .
        "Set the GLPI_ROOT_DIR environment variable to your GLPI installation root\n" .
        "(the folder containing vendor/, src/ and bin/console), e.g.:\n" .
        "  GLPI_ROOT_DIR=/var/www/glpi vendor/bin/phpunit -c phpunit-integration.xml.dist\n"
    );
    exit(1);
}

require $glpiRoot . '/vendor/autoload.php';

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

$kernel = new \Glpi\Kernel\Kernel('production');
// A plain local variable here never becomes a real PHP global (this bootstrap is include_once'd
// from inside a method, not run at top-level script scope) — GLPI's own legacy code (e.g.
// isAPI()/getMainRequest()-dependent paths reached from CommonDBTM::add() hooks such as
// notification queueing) does `global $kernel` internally and finds nothing without this,
// crashing with "Call to a member function getMainRequest() on null". Confirmed live: exactly
// this fatal on any test that adds a User (GrcmanagerIntegrationTestCase::createTestUser()) before
// this line was added. Same fix already proven necessary in the sibling plugin
// Configuration-glpi-auto's own tests/integration-bootstrap.php.
$GLOBALS['kernel'] = $kernel;
$kernel->boot();

if (!\Plugin::isPluginActive('grcmanager')) {
    fwrite(
        STDERR,
        "The 'grcmanager' plugin is not installed/active on this test GLPI instance.\n" .
        "Install and activate it before running the tests:\n" .
        "  bin/console plugin:install grcmanager ; plugin:activate grcmanager\n"
    );
    exit(1);
}

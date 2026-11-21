<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests\Install;

use GlpiPlugin\Grcmanager\Install\ConfigScanTables;
use GlpiPlugin\Grcmanager\Install\DiskEncryptionTables;
use GlpiPlugin\Grcmanager\Install\EvidencePackTables;
use GlpiPlugin\Grcmanager\Install\FeatureTables;
use GlpiPlugin\Grcmanager\Install\FeatureTablesRegistry;
use GlpiPlugin\Grcmanager\Install\HardwareEndOfLifeTables;
use GlpiPlugin\Grcmanager\Install\LogCoverageTables;
use GlpiPlugin\Grcmanager\Install\MfaCoverageTables;
use PHPUnit\Framework\TestCase;

/**
 * Découverte des tables des fonctionnalités par l'installeur : une classe src/Install/*Tables.php qui
 * implémente FeatureTables est installée et désinstallée sans être citée dans Installer.php.
 */
final class FeatureTablesRegistryTest extends TestCase
{
    public function testDiscoversFeatureTablesInFileNameOrder(): void
    {
        $this->assertSame([
            ConfigScanTables::class,
            DiskEncryptionTables::class,
            EvidencePackTables::class,
            HardwareEndOfLifeTables::class,
            LogCoverageTables::class,
            MfaCoverageTables::class,
        ], array_values(array_intersect(FeatureTablesRegistry::classes(), [
            ConfigScanTables::class,
            DiskEncryptionTables::class,
            EvidencePackTables::class,
            HardwareEndOfLifeTables::class,
            LogCoverageTables::class,
            MfaCoverageTables::class,
        ])));
        $classes = FeatureTablesRegistry::classes();
        $sorted = $classes;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $classes, 'ordre alphabétique stable');
    }

    /**
     * Une classe *Tables qui n'implémente pas FeatureTables n'est installée que si Installer.php
     * l'appelle : chacune doit y figurer, sinon ses tables ne seraient jamais créées.
     */
    public function testEveryTablesClassIsInstalledOnce(): void
    {
        $installer = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Install/Installer.php');
        $discovered = FeatureTablesRegistry::classes();
        foreach (glob(dirname(__DIR__, 3) . '/src/Install/*Tables.php') ?: [] as $file) {
            $short = basename($file, '.php');
            $class = 'GlpiPlugin\\Grcmanager\\Install\\' . $short;
            if ($class === FeatureTables::class) {
                continue;
            }
            $called = str_contains($installer, $short . '::install(');
            if (in_array($class, $discovered, true)) {
                $this->assertFalse($called, $short . ' est découverte : ne pas l\'appeler aussi dans Installer.php');
            } else {
                $this->assertTrue($called, $short . ' : implémenter FeatureTables (ou l\'appeler dans Installer.php)');
            }
        }
    }

    public function testPluginClassesComeFromTheFeatures(): void
    {
        $this->assertContains(\GlpiPlugin\Grcmanager\EvidencePack::class, FeatureTablesRegistry::pluginClasses());
    }
}

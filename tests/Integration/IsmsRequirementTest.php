<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\Services\Clause\IsmsClauseCatalog;
use PluginGrcmanagerIsmsRequirement;

/**
 * Issue #113 : exigences du SMSI (articles 4 à 10) sur une vraie instance GLPI — semis à
 * l'installation, mise à jour, garde-fous, liens vers les modules.
 */
final class IsmsRequirementTest extends GrcmanagerIntegrationTestCase
{
    private function requirement(string $code): PluginGrcmanagerIsmsRequirement
    {
        $requirement = new PluginGrcmanagerIsmsRequirement();
        $this->assertTrue($requirement->getFromDBByCrit(['code' => $code]), "sous-article $code semé");

        return $requirement;
    }

    public function testEverySubclauseOfTheCatalogIsSeededOnce(): void
    {
        global $DB;

        $codes = array_column(iterator_to_array($DB->request([
            'SELECT' => ['code'],
            'FROM'   => PluginGrcmanagerIsmsRequirement::getTable(),
        ]), false), 'code');

        // Tri explicite en chaînes : le tri par défaut de PHP (et donc assertEqualsCanonicalizing())
        // compare « 6.2 » comme un nombre et « 6.1.1 » comme un texte, ordre incohérent.
        $expected = array_map('strval', array_keys(IsmsClauseCatalog::SUBCLAUSES));
        sort($expected, SORT_STRING);
        sort($codes, SORT_STRING);
        $this->assertSame($expected, $codes);
    }

    public function testUpdateKeepsTheCodeAndRejectsAnUnknownStatus(): void
    {
        $requirement = $this->requirement('6.1.2');

        $this->assertTrue($requirement->update([
            'id'      => $requirement->getID(),
            'status'  => IsmsClauseCatalog::STATUS_COMPLIANT,
            'comment' => 'Registre des risques revu en comité.',
            'code'    => 'HACK',
        ]));
        $requirement->getFromDB($requirement->getID());
        $this->assertSame('6.1.2', $requirement->fields['code'], 'le numéro n\'est jamais modifiable');
        $this->assertSame(IsmsClauseCatalog::STATUS_COMPLIANT, $requirement->fields['status']);

        $this->assertFalse($requirement->update(['id' => $requirement->getID(), 'status' => 'bogus']));
    }

    public function testCatalogIsFixed(): void
    {
        $this->assertFalse(PluginGrcmanagerIsmsRequirement::canCreate());
        $this->assertFalse(PluginGrcmanagerIsmsRequirement::canDelete());
    }

    public function testLinkedModuleAndCompletion(): void
    {
        $module = PluginGrcmanagerIsmsRequirement::getLinkedModule('6.1.3');
        $this->assertNotNull($module);
        $this->assertStringContainsString('control.php', $module['url']);
        $this->assertNull(PluginGrcmanagerIsmsRequirement::getLinkedModule('4.1'), 'aucun module ne couvre 4.1');

        $before = PluginGrcmanagerIsmsRequirement::getCompletion();
        $requirement = $this->requirement('9.2.2');
        $requirement->update(['id' => $requirement->getID(), 'status' => IsmsClauseCatalog::STATUS_COMPLIANT]);
        $after = PluginGrcmanagerIsmsRequirement::getCompletion();

        $this->assertSame(30, $after['total']);
        $this->assertSame($before['compliant'] + 1, $after['compliant']);
    }
}

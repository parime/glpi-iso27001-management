<?php

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use CommonGLPI;
use GlpiPlugin\Grcmanager\Policy;
use GlpiPlugin\Grcmanager\Services\Policy\OperatingProcedureRules;
use KnowbaseItem;
use KnowbaseItem_Item;

/**
 * Issue #1165 : ouvrir une procédure d'exploitation (et chacun de ses onglets) avec un profil qui
 * ne voit pas toutes les entités. L'onglet natif « Base de connaissances » filtrait sur
 * glpi_plugin_grcmanager_policies.entities_id, colonne absente : erreur SQL à l'ouverture.
 * Indépendant de la langue de l'instance : aucune comparaison de texte traduit.
 */
final class PolicyKnowbaseTabTest extends GrcmanagerIntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $savedSession = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['glpishowallentities', 'glpiparententities', 'glpishow_count_on_tabs'] as $key) {
            $this->savedSession[$key] = $_SESSION[$key] ?? null;
        }
        // Profil ordinaire : pas de « Voir toutes les entités », compteurs d'onglets affichés.
        $_SESSION['glpishowallentities'] = 0;
        $_SESSION['glpiparententities'] = [];
        $_SESSION['glpishow_count_on_tabs'] = 1;
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSession as $key => $value) {
            if ($value === null) {
                unset($_SESSION[$key]);
            } else {
                $_SESSION[$key] = $value;
            }
        }
        parent::tearDown();
    }

    public function testProcedureOpensWithAllTabsAndLinkedArticle(): void
    {
        $policy = new Policy();
        $id = (int) $policy->add([
            'title'    => 'Procédure fictive de sauvegarde 1165',
            'status'   => 'draft',
            'users_id' => 2,
            'category' => OperatingProcedureRules::CATEGORY_PROCEDURE,
            // Ignorés : la bibliothèque reste commune à toutes les entités.
            'entities_id'  => 99,
            'is_recursive' => 0,
        ]);
        $this->assertGreaterThan(0, $id);
        $article = new KnowbaseItem();
        $articleId = (int) $article->add([
            'name'   => 'Article fictif 1165',
            'answer' => 'Texte fictif de la procédure.',
        ]);
        $this->assertGreaterThan(0, $articleId);

        try {
            $this->assertTrue($policy->getFromDB($id));
            $this->assertSame(0, (int) $policy->fields['entities_id']);
            $this->assertSame(1, (int) $policy->fields['is_recursive']);
            $this->assertFalse($policy->isEntityAssign());

            $link = new KnowbaseItem_Item();
            $this->assertGreaterThan(0, (int) $link->add([
                'knowbaseitems_id' => $articleId,
                'itemtype'         => Policy::class,
                'items_id'         => $id,
            ]));

            // Le chemin exact du plantage : compteur de l'onglet et liste des articles liés.
            $this->assertNotSame('', (string) $link->getTabNameForItem($policy));
            $this->assertCount(1, iterator_to_array(KnowbaseItem_Item::getItems($policy)));

            // Chaque onglet de la fiche s'affiche sans exception.
            $tabs = $policy->defineTabs();
            $this->assertNotEmpty($tabs);
            foreach (array_keys($tabs) as $tab) {
                ob_start();
                try {
                    CommonGLPI::displayStandardTab($policy, $tab);
                } finally {
                    $html = (string) ob_get_clean();
                }
                $this->assertIsString($html, 'Onglet ' . $tab);
            }
        } finally {
            $article->delete(['id' => $articleId], true);
            $policy->delete(['id' => $id], true);
        }
    }
}

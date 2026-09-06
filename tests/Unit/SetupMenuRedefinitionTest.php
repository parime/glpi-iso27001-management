<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../setup.php';

/**
 * `plugin_grcmanager_redefine_menus()` (setup.php) is a pure array-in/array-out function : no
 * GLPI dependency in its own body, `PluginGrcmanagerMenu::class` resolves to a plain string at
 * compile time regardless of whether the class is actually loaded. Safe to exercise directly by
 * requiring setup.php — that only declares constants/functions and requires vendor/autoload.php,
 * none of which touches a real GLPI instance (confirmed by reading the file: no top-level code
 * runs plugin_init_grcmanager() or any other GLPI-dependent function).
 *
 * This is the *wiring* half of the fix for two real bugs (CHANGELOG v1.1.3/v1.1.4: wrong
 * breadcrumb sector, then a duplicate "GRC & Conformité" row in its own submenu) — the *effect*
 * those fixes have on GLPI's actual rendered menu isn't practically unit-testable (depends on
 * `Html::generateMenuSession()`/`Html::header()` rendering against a real GLPI instance), but this
 * function's own contract — "remove exactly the anchor class's own row, touch nothing else" — is,
 * and had no test at all despite that.
 */
final class SetupMenuRedefinitionTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../setup.php';
    }

    public function testRemovesOnlyTheMenuAnchorsOwnRow(): void
    {
        $anchorKey = strtolower(\PluginGrcmanagerMenu::class);

        $menu = [
            'grcmanager' => [
                'title' => 'GRC & Conformité',
                'content' => [
                    $anchorKey => ['title' => 'GRC & Conformité', 'page' => '/front/risk.php'],
                    'plugingrcmanagerrisk' => ['title' => 'Registre des risques', 'page' => '/front/risk.php'],
                    'plugingrcmanagercontrol' => ['title' => 'Contrôles (SoA)', 'page' => '/front/control.php'],
                ],
            ],
        ];

        $result = plugin_grcmanager_redefine_menus($menu);

        $this->assertArrayNotHasKey(
            $anchorKey,
            $result['grcmanager']['content'],
            'The menu anchor\'s own row must be removed — this is the actual regression: it used ' .
            'to duplicate the section title as a clickable submenu entry.'
        );
        $this->assertArrayHasKey(
            'plugingrcmanagerrisk',
            $result['grcmanager']['content'],
            'Sibling menu entries must be untouched.'
        );
        $this->assertArrayHasKey(
            'plugingrcmanagercontrol',
            $result['grcmanager']['content'],
            'Sibling menu entries must be untouched.'
        );
        $this->assertSame(
            'GRC & Conformité',
            $result['grcmanager']['title'],
            'The sector title itself must be untouched — only the anchor\'s own content row is removed.'
        );
    }

    public function testIsANoOpWhenTheAnchorRowIsAlreadyAbsent(): void
    {
        $menu = [
            'grcmanager' => [
                'title' => 'GRC & Conformité',
                'content' => [
                    'plugingrcmanagerrisk' => ['title' => 'Registre des risques', 'page' => '/front/risk.php'],
                ],
            ],
        ];

        $result = plugin_grcmanager_redefine_menus($menu);

        $this->assertSame(
            $menu,
            $result,
            'Calling this twice (or on an already-clean menu) must not error or alter anything.'
        );
    }

    public function testLeavesOtherSectorsCompletelyUntouched(): void
    {
        $menu = [
            'tools' => [
                'title' => 'Outils',
                'content' => [
                    'pluginothersomething' => ['title' => 'Autre plugin', 'page' => '/front/other.php'],
                ],
            ],
            'grcmanager' => [
                'title' => 'GRC & Conformité',
                'content' => [
                    strtolower(\PluginGrcmanagerMenu::class) => [
                        'title' => 'GRC & Conformité',
                        'page' => '/front/risk.php',
                    ],
                ],
            ],
        ];

        $result = plugin_grcmanager_redefine_menus($menu);

        $this->assertSame(
            $menu['tools'],
            $result['tools'],
            'A different plugin\'s own menu sector must never be touched.'
        );
    }
}

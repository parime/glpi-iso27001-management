<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-grc-manager
 * -------------------------------------------------------------------------
 * LICENSE
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version. See LICENSE for the full text.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager;

use CommonDBTM;
use CommonGLPI;
use Computer;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Feature\AssetSecuritySourcesProvider;
use GlpiPlugin\Grcmanager\Feature\FeatureRegistry;
use GlpiPlugin\Grcmanager\Services\Security360\Security360Rules as Rules;
use NetworkEquipment;
use Session;
use Throwable;

/**
 * Issue #837 : onglet « Sécurité 360° » des ordinateurs et matériels réseau, en LECTURE SEULE.
 *
 * Résume chaque source de sécurité disponible pour l'actif (vulnérabilités, chiffrement, contrôleur de
 * gestion, conformité de configuration, risques, incidents...) avec un badge d'état, un lien vers le
 * détail et un score global expliqué (Security360Rules). Les sources viennent du registre des
 * fonctionnalités (AssetSecuritySourcesProvider dans src/Feature/) : une nouvelle source se branche
 * sans toucher à cette classe. Une source absente (module non utilisé, aucune donnée, pas le droit)
 * n'est pas affichée ; une source en erreur est ignorée sans casser l'onglet.
 *
 * Droits : droit de lecture du plugin et lecture de l'élément ; chaque source vérifie en plus ses
 * propres droits. Le calcul n'est fait qu'à l'ouverture de l'onglet (pas pour son titre), une fois
 * par élément et par requête.
 */
class AssetSecurity360 extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const ITEMTYPES = [Computer::class, NetworkEquipment::class];

    /** @var array<string, array<string, mixed>> */
    private static array $memo = [];

    public static function getTypeName($nb = 0)
    {
        return __('Security 360°', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-shield-half-filled';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ);
    }

    public static function reset(): void
    {
        self::$memo = [];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!self::isTarget($item) || !self::canView()) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(1), 0, $item::class, self::getIcon());
    }

    /**
     * ajax/common.tabs.php sert ce contenu sans passer par getTabNameForItem() : droits vérifiés ici.
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM || !self::isTarget($item) || !self::canView() || !$item->canViewItem()) {
            return false;
        }
        TemplateRenderer::getInstance()->display(
            '@grcmanager/asset_security_360_tab.html.twig',
            self::tabData($item)
        );

        return true;
    }

    private static function isTarget(CommonGLPI $item): bool
    {
        return $item instanceof CommonDBTM && !$item->isNewItem() && in_array($item::class, self::ITEMTYPES, true);
    }

    /**
     * Sources déclarées par les fonctionnalités, dans l'ordre du registre (clé « after » respectée).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function sources(): array
    {
        $sources = [];
        foreach (FeatureRegistry::implementing(AssetSecuritySourcesProvider::class) as $class) {
            $sources = FeatureRegistry::insertAfter($sources, $class::assetSecuritySources());
        }

        return $sources;
    }

    /**
     * @return array<string, mixed>
     */
    public static function tabData(CommonDBTM $item): array
    {
        $key = $item::class . ':' . (int) $item->getID();
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $rows = [];
        foreach (self::sources() as $name => $source) {
            $compute = $source['compute'] ?? null;
            if (!in_array($item::class, (array) ($source['itemtypes'] ?? []), true) || !is_callable($compute)) {
                continue;
            }
            try {
                $result = Rules::normalize($compute($item));
            } catch (Throwable $e) {
                // Une source en erreur ne casse pas la vue : elle est simplement absente.
                $result = null;
            }
            if ($result === null) {
                continue;
            }
            $weight = max(0, (int) ($source['weight'] ?? 1));
            $rows[] = $result + [
                'key'    => (string) $name,
                'label'  => (string) ($source['label'] ?? $name),
                'icon'   => (string) ($source['icon'] ?? 'ti ti-shield'),
                'weight' => $weight,
                'points' => $weight > 0 ? Rules::points($result['status']) : null,
            ];
        }

        return self::$memo[$key] = [
            'sources'   => Rules::sort($rows),
            'score'     => Rules::score($rows),
            'statuses'  => self::statusInfos(),
            'levels'    => self::levelInfos(),
            'good_from' => Rules::GOOD_FROM,
            'fair_from' => Rules::FAIR_FROM,
        ];
    }

    /**
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function statusInfos(): array
    {
        return [
            Rules::STATUS_CRITICAL => [
                'label' => __('Critical', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-alert-octagon',
            ],
            Rules::STATUS_WARNING  => [
                'label' => __('To watch', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-alert-triangle',
            ],
            Rules::STATUS_OK       => [
                'label' => __('Compliant', 'grcmanager'),
                'color' => 'success',
                'icon'  => 'ti ti-circle-check',
            ],
            Rules::STATUS_INFO     => [
                'label' => __('Information', 'grcmanager'),
                'color' => 'secondary',
                'icon'  => 'ti ti-info-circle',
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, color: string, icon: string}>
     */
    public static function levelInfos(): array
    {
        return [
            Rules::LEVEL_GOOD => [
                'label' => __('Good security posture', 'grcmanager'),
                'color' => 'success',
                'icon'  => 'ti ti-shield-check',
            ],
            Rules::LEVEL_FAIR => [
                'label' => __('Security posture to improve', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-shield-half',
            ],
            Rules::LEVEL_POOR => [
                'label' => __('Weak security posture', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-shield-x',
            ],
            Rules::LEVEL_NONE => [
                'label' => __('Not enough data to assess', 'grcmanager'),
                'color' => 'secondary',
                'icon'  => 'ti ti-shield',
            ],
        ];
    }

    /**
     * Lien vers un onglet de la fiche de l'élément (détail d'une source).
     */
    public static function tabUrl(CommonDBTM $item, string $tabClass): string
    {
        return $item::getFormURLWithID((int) $item->getID()) . '&forcetab=' . rawurlencode($tabClass . '$1');
    }
}

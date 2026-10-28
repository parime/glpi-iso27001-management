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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Cve\CertFrRules;
use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync;

/**
 * Issue #167 (phase 3 de #160) : veille de sécurité des fournisseurs du registre. Les avis du
 * CERT-FR et les vulnérabilités du parc qui citent le fournisseur s'affichent sur la fiche de son
 * risque, et son propriétaire est prévenu d'un nouvel avis. Rien n'est modifié dans le registre.
 */
class PluginGrcmanagerSecurityWatch extends CommonGLPI
{
    public const NOTIFY_EVENT = 'security_watch';

    /** Avis déjà signalés au propriétaire d'un risque fournisseur. */
    public const NOTIFIED_TABLE = 'glpi_plugin_grcmanager_certfrnotices_supplierrisks';

    /** Avis affichés sur l'onglet, en jours. */
    private const TAB_DAYS = 90;

    /** Avis récents comptés sur l'onglet et signalés, en jours. */
    private const RECENT_DAYS = 30;

    public static function getTypeName($nb = 0)
    {
        return __('Veille sécurité', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-radar';
    }

    /**
     * Noms recherchés pour le fournisseur d'un risque.
     *
     * @return list<string>
     */
    public static function keywordsFor(PluginGrcmanagerSupplierRisk $risk): array
    {
        $supplier = new Supplier();
        if (!$supplier->getFromDB((int) ($risk->fields['suppliers_id'] ?? 0))) {
            return [];
        }

        return CertFrRules::supplierKeywords((string) $supplier->fields['name']);
    }

    /**
     * Avis du CERT-FR des derniers jours qui citent l'un des noms (titre ou résumé).
     *
     * @param list<string> $keywords
     * @return list<array<string, mixed>>
     */
    public static function noticesFor(array $keywords, int $days): array
    {
        if ($keywords === []) {
            return [];
        }

        return array_values(array_filter(
            self::recentNotices($days),
            static fn (array $notice): bool => CertFrRules::mentionsAny(
                $notice['title'] . ' ' . $notice['summary'],
                $keywords
            )
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recentNotices(int $days): array
    {
        global $DB;

        // Lignes enregistrées avant le contrôle de CertFrRules::parseFeed() : un lien qui n'est
        // pas https sur le site du CERT-FR n'atteint jamais un href.
        return array_values(array_filter(
            iterator_to_array($DB->request([
                'FROM'  => ParkVulnerabilitySync::CERTFR_TABLE,
                'WHERE' => ['published' => ['>=', date('Y-m-d H:i:s', strtotime('-' . $days . ' days'))]],
                'ORDER' => 'published DESC',
            ]), false),
            static fn (array $notice): bool => CertFrRules::isNoticeLink((string) $notice['link'])
        ));
    }

    /**
     * Vulnérabilités du parc des logiciels de ce fournisseur, regroupées par logiciel.
     *
     * @param list<string> $keywords
     * @return list<array{product: string, cves: int, kev: int, priority: int, machines: int}>
     */
    public static function parkFor(array $keywords): array
    {
        if ($keywords === []) {
            return [];
        }
        $products = [];
        foreach (PluginGrcmanagerParkVulnerability::getVulnerabilityRows() as $row) {
            if (!CertFrRules::mentionsAny((string) $row['product'], $keywords)) {
                continue;
            }
            $name = (string) $row['product'];
            $product = $products[$name]
                ?? ['product' => $name, 'cves' => 0, 'kev' => 0, 'priority' => 0, 'machines' => 0];
            $product['cves']++;
            $product['kev'] += $row['is_kev'] ? 1 : 0;
            $product['priority'] += $row['priority'] ? 1 : 0;
            $product['machines'] = max($product['machines'], (int) $row['machines']);
            $products[$name] = $product;
        }

        return array_values($products);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            !$item instanceof PluginGrcmanagerSupplierRisk || $item->isNewItem()
            || !PluginGrcmanagerSupplierRisk::canView()
        ) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(),
            count(self::noticesFor(self::keywordsFor($item), self::RECENT_DAYS))
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        global $CFG_GLPI;

        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof PluginGrcmanagerSupplierRisk || !$item->can($item->getID(), READ)) {
            return false;
        }
        $keywords = self::keywordsFor($item);
        TemplateRenderer::getInstance()->display('@grcmanager/supplier_security_watch.html.twig', [
            'keywords'     => $keywords,
            'notices'      => self::noticesFor($keywords, self::TAB_DAYS),
            'days'         => self::TAB_DAYS,
            'park'         => PluginGrcmanagerParkVulnerability::canView() ? self::parkFor($keywords) : [],
            'park_url'     => PluginGrcmanagerParkVulnerability::getSearchURL(),
            'enabled'      => ParkVulnerabilitySync::isEnabled(),
            'last_refresh' => ParkVulnerabilitySync::lastCertFrRefresh(),
            'config_url'   => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/config.php',
        ]);

        return true;
    }

    /**
     * Tâche planifiée : prévient le propriétaire de chaque risque fournisseur des nouveaux avis
     * qui citent son fournisseur (une seule fois par avis).
     *
     * @return int Notifications envoyées.
     */
    public static function notifyOwners(): int
    {
        global $DB;

        if (self::recentNotices(self::RECENT_DAYS) === []) {
            return 0;
        }
        $sent = 0;
        $risk = new PluginGrcmanagerSupplierRisk();
        foreach ($risk->find(['users_id' => ['>', 0]]) as $row) {
            $risk->getFromDB((int) $row['id']);
            $notices = self::noticesFor(self::keywordsFor($risk), self::RECENT_DAYS);
            if ($notices === []) {
                continue;
            }
            $known = [];
            foreach (
                $DB->request([
                    'SELECT' => ['plugin_grcmanager_certfrnotices_id'],
                    'FROM'   => self::NOTIFIED_TABLE,
                    'WHERE'  => ['plugin_grcmanager_supplierrisks_id' => $risk->getID()],
                ]) as $link
            ) {
                $known[(int) $link['plugin_grcmanager_certfrnotices_id']] = true;
            }
            $new = array_values(array_filter(
                $notices,
                static fn (array $notice): bool => !isset($known[(int) $notice['id']])
            ));
            if ($new === []) {
                continue;
            }
            foreach ($new as $notice) {
                $DB->insert(self::NOTIFIED_TABLE, [
                    'plugin_grcmanager_certfrnotices_id' => (int) $notice['id'],
                    'plugin_grcmanager_supplierrisks_id' => $risk->getID(),
                    'date_creation'                      => date('Y-m-d H:i:s'),
                ]);
            }
            if (NotificationEvent::raiseEvent(self::NOTIFY_EVENT, $risk, ['notices' => $new])) {
                $sent++;
            }
        }

        return $sent;
    }
}

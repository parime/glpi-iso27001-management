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

use CommonGLPI;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\License\LicenseComplianceRules as Rules;
use GlpiPlugin\Grcmanager\Services\License\LicenseInventory;
use Html;
use Session;
use Software;
use SoftwareLicense;

/**
 * Issue #779 : conformité des licences logicielles (ISO 27001, mesure A.5.32, droits de propriété
 * intellectuelle), d'après les logiciels et les licences natifs de GLPI.
 *
 * - Trois constats (Rules) : logiciel commercial sans licence, surdéploiement, licence expirée encore
 *   utilisée. Calculés à la demande, avec les droits et les entités de la personne ; rien n'est
 *   conservé et aucune donnée de GLPI (ticket, licence, logiciel) n'est modifiée.
 * - Page de synthèse par entité (sous-page « Sécurité technique »), export CSV protégé contre
 *   l'injection de formules, preuve de la mesure A.5.32, règle du contrôle de cohérence.
 *
 * Droits : droit des registres sensibles du plugin (comme les vulnérabilités du parc), plus les
 * droits natifs de lecture des logiciels et des licences. Ancre sans table (CommonGLPI).
 *
 * @phpstan-import-type Finding from Rules
 */
class SoftwareLicenseCompliance extends CommonGLPI
{
    /** Constats affichés au plus sur la page ; le reste est compté et reste dans l'export. */
    public const MAX_ROWS = 300;

    /**
     * @var array<string, array{findings: list<Finding>, coverage: array{watched: int, licensed: int}}>
     *     Constats et couverture déjà calculés pendant la requête, par date.
     */
    private static array $memo = [];

    public static function getTypeName($nb = 0)
    {
        return __('Software license compliance', 'grcmanager');
    }

    public static function getMenuName()
    {
        return __('Software licenses', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-license';
    }

    public static function canView(): bool
    {
        return Session::haveRight(ParkVulnerability::RIGHTNAME, READ)
            && Session::haveRight(Software::$rightname, READ)
            && Session::haveRight(SoftwareLicense::$rightname, READ);
    }

    /**
     * Listes des éditeurs surveillés : réglage du plugin (droit de configuration).
     */
    public static function canConfigure(): bool
    {
        return self::canView() && Session::haveRight(Install\Installer::RIGHT_NAME, UPDATE);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        // Sous-page de la rubrique « Sécurité technique » (Menu::sections()).
        return [
            'title' => self::getMenuName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/softwarelicensecompliance.php';
    }

    public static function getExportURL(bool $full = true): string
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/softwarelicensecompliance.export.php';
    }

    /**
     * Oublie les constats calculés (après un changement de réglage, tests).
     */
    public static function reset(): void
    {
        self::$memo = [];
    }

    // ------------------------------------------------------------------ Libellés.

    /**
     * Libellé, couleur, icône et aide de chaque constat.
     *
     * @return array<string, array{label: string, color: string, icon: string, help: string}>
     */
    public static function findingInfos(): array
    {
        return [
            Rules::FINDING_UNLICENSED   => [
                'label' => __('Commercial software without a license', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-license-off',
                'help'  => __(
                    'Installed software from a watched publisher, with no license recorded in GLPI for this entity.',
                    'grcmanager'
                ),
            ],
            Rules::FINDING_OVERDEPLOYED => [
                'label' => __('More installations than licensed seats', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-users-plus',
                'help'  => __(
                    'More assets have the software than the valid licenses allow. An unlimited license '
                        . '(number 0 or less) covers everything. A parent license and its child licenses '
                        . 'count for the larger of the parent number and the sum of the children.',
                    'grcmanager'
                ),
            ],
            Rules::FINDING_EXPIRED      => [
                'label' => __('Expired license still in use', 'grcmanager'),
                'color' => 'info',
                'icon'  => 'ti ti-calendar-x',
                'help'  => __(
                    'The license has expired but assets are still assigned to it, or the software is still '
                        . 'installed with no other valid license.',
                    'grcmanager'
                ),
            ],
            // Lot 2 : alerte d'anticipation, pas un écart (absente du contrôle de cohérence).
            Rules::FINDING_EXPIRING     => [
                'label' => __('License expiring soon', 'grcmanager'),
                'color' => 'secondary',
                'icon'  => 'ti ti-calendar-time',
                'help'  => __(
                    'A license in use expires within the alert horizon and no renewal is recorded in GLPI yet. '
                        . 'This is a reminder, not a gap.',
                    'grcmanager'
                ),
            ],
        ];
    }

    /**
     * Phrase de détail d'un constat.
     *
     * @param Finding $finding
     */
    public static function detail(array $finding): string
    {
        $where = $finding['scope'] === Rules::SCOPE_SUBTREE
            ? __('in this entity and its sub-entities', 'grcmanager')
            : __('in this entity', 'grcmanager');

        return match ($finding['type']) {
            Rules::FINDING_UNLICENSED   => sprintf(
                _n(
                    '%1$d asset has it %2$s, no license recorded.',
                    '%1$d assets have it %2$s, no license recorded.',
                    $finding['installs'],
                    'grcmanager'
                ),
                $finding['installs'],
                $where
            ),
            Rules::FINDING_OVERDEPLOYED => sprintf(
                _n(
                    '%1$d asset has it %3$s for %2$d licensed seat(s).',
                    '%1$d assets have it %3$s for %2$d licensed seat(s).',
                    $finding['installs'],
                    'grcmanager'
                ),
                $finding['installs'],
                $finding['seats'],
                $where
            ) . ($finding['overquota_allowed']
                ? ' ' . __(
                    'The license allows going over the number of seats: settle it with the publisher.',
                    'grcmanager'
                )
                : '') . ($finding['nested']
                ? ' ' . __(
                    'Child licenses share the seats of their parent license: each family is counted once.',
                    'grcmanager'
                )
                : ''),
            Rules::FINDING_EXPIRING     => sprintf(
                __('Expires on %1$s: %2$d asset(s) assigned, %3$d installation(s) %4$s.', 'grcmanager'),
                Html::convDate($finding['expire']),
                $finding['assigned'],
                $finding['installs'],
                $where
            ),
            default                     => sprintf(
                __('Expired on %1$s: %2$d asset(s) still assigned, %3$d installation(s) %4$s.', 'grcmanager'),
                Html::convDate($finding['expire']),
                $finding['assigned'],
                $finding['installs'],
                $where
            ),
        };
    }

    // ------------------------------------------------------------------ Constats.

    /**
     * Constats visibles par la personne connectée ([] sans les droits).
     *
     * @param string|null $today « 2026-11-20 » (tests) ; date du jour sinon.
     * @return list<Finding>
     */
    public static function findings(?string $today = null): array
    {
        if (!self::canView()) {
            return [];
        }
        return self::compute($today)['findings'];
    }

    /**
     * Taux de couverture des logiciels commerciaux surveillés installés (couples logiciel, entité)
     * dans les entités de la personne ; null sans les droits.
     *
     * @return array{watched: int, licensed: int, percent: int|null}|null
     */
    public static function coverage(?string $today = null): ?array
    {
        if (!self::canView()) {
            return null;
        }
        $coverage = self::compute($today)['coverage'];

        return $coverage + [
            'percent' => $coverage['watched'] > 0
                ? (int) floor(100 * $coverage['licensed'] / $coverage['watched']) : null,
        ];
    }

    /**
     * Constats et couverture, calculés une fois par requête et par date.
     *
     * @return array{findings: list<Finding>, coverage: array{watched: int, licensed: int}}
     */
    private static function compute(?string $today): array
    {
        $today ??= substr((string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')), 0, 10);
        if (!isset(self::$memo[$today])) {
            $data = LicenseInventory::visible();
            $settings = LicenseInventory::settings();
            self::$memo[$today] = [
                'findings' => Rules::evaluate(
                    $data['software'],
                    $data['installs'],
                    $data['licences'],
                    $data['assignments'],
                    $data['ancestors'],
                    $settings['publishers'],
                    $settings['ignored'],
                    $today,
                    $settings['expiring_days']
                ),
                'coverage' => Rules::coverage(
                    $data['software'],
                    $data['installs'],
                    $data['licences'],
                    $data['ancestors'],
                    $settings['publishers'],
                    $settings['ignored'],
                    $today
                ),
            ];
        }

        return self::$memo[$today];
    }

    /**
     * Ligne affichable d'un constat : libellés, entité, liens vers le logiciel, la licence et les
     * actifs (seulement ceux que la personne peut ouvrir).
     *
     * @param Finding $finding
     * @return array<string, mixed>
     */
    private static function present(array $finding): array
    {
        $assets = [];
        foreach ($finding['assets'] as $asset) {
            $itemtype = $asset['itemtype'];
            $type = is_a($itemtype, CommonGLPI::class, true) ? $itemtype::getTypeName(1) : $itemtype;
            $assets[] = [
                'name' => $asset['item_name'] !== ''
                    ? $asset['item_name']
                    : sprintf(__('%1$s (ID %2$d)', 'grcmanager'), $type, $asset['items_id']),
                'url'  => is_a($itemtype, \CommonDBTM::class, true)
                    ? $itemtype::getFormURLWithID($asset['items_id']) : '',
            ];
        }

        return [
            'type'          => $finding['type'],
            'info'          => self::findingInfos()[$finding['type']],
            'entity_id'     => $finding['entity_id'],
            'entity'        => self::entityName($finding['entity_id']),
            'software_name' => $finding['software_name'],
            'software_url'  => Software::getFormURLWithID($finding['software_id']),
            'manufacturer'  => $finding['manufacturer'],
            'licence_name'  => $finding['licence_name'] !== ''
                ? $finding['licence_name']
                : ($finding['licence_id'] > 0
                    ? sprintf(
                        __('%1$s (ID %2$d)', 'grcmanager'),
                        SoftwareLicense::getTypeName(1),
                        $finding['licence_id']
                    )
                    : ''),
            'licence_url'   => $finding['licence_id'] > 0
                ? SoftwareLicense::getFormURLWithID($finding['licence_id']) : '',
            'installs'      => $finding['installs'],
            'seats'         => $finding['seats'],
            'subtree'       => $finding['scope'] === Rules::SCOPE_SUBTREE,
            'overquota_allowed' => $finding['overquota_allowed'],
            'nested'        => $finding['nested'],
            'detail'        => self::detail($finding),
            'assets'        => $assets,
            'more_assets'   => max(0, $finding['installs'] - count($assets)),
        ];
    }

    private static function entityName(int $entityId): string
    {
        return (string) Dropdown::getDropdownName('glpi_entities', $entityId);
    }

    /**
     * Données de la page : compteurs, constats regroupés par entité, réglages.
     *
     * @return array<string, mixed>
     */
    public static function pageData(?string $today = null): array
    {
        $findings = self::findings($today);
        $groups = [];
        foreach (array_slice($findings, 0, self::MAX_ROWS) as $finding) {
            $row = self::present($finding);
            $groups[$finding['entity_id']] ??= ['entity' => $row['entity'], 'rows' => []];
            $groups[$finding['entity_id']]['rows'][] = $row;
        }
        uasort($groups, static fn (array $a, array $b): int => strcmp($a['entity'], $b['entity']));
        $settings = LicenseInventory::settings();

        return [
            'counts'        => Rules::counts($findings),
            'coverage'      => self::coverage($today),
            'expiring_days' => $settings['expiring_days'],
            'max_expiring_days' => Rules::MAX_EXPIRING_DAYS,
            'infos'         => self::findingInfos(),
            'groups'        => array_values($groups),
            'total'         => count($findings),
            'shown'         => min(count($findings), self::MAX_ROWS),
            'export_url'    => self::getExportURL(),
            'action'        => self::getSearchURL(),
            'can_config'    => self::canConfigure(),
            'publishers'    => implode("\n", $settings['publishers']),
            'ignored'       => implode("\n", $settings['ignored']),
            'publisher_list' => $settings['publishers'],
            'license_search_url' => SoftwareLicense::getSearchURL(),
        ];
    }

    public static function showPage(): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/software_license_compliance.html.twig', self::pageData());
    }

    /**
     * Lignes de l'export CSV : un constat par ligne, avec tous ses chiffres.
     *
     * @return list<list<string>>
     */
    public static function csvRows(?string $today = null): array
    {
        $rows = [[
            __('Finding', 'grcmanager'),
            __('Entity', 'grcmanager'),
            __('Scope', 'grcmanager'),
            __('Software', 'grcmanager'),
            __('Publisher', 'grcmanager'),
            __('Installations', 'grcmanager'),
            __('Licensed seats', 'grcmanager'),
            __('License', 'grcmanager'),
            __('Expiration date', 'grcmanager'),
            __('Assets still assigned', 'grcmanager'),
            __('Over-quota allowed by the license', 'grcmanager'),
            __('Detail', 'grcmanager'),
        ]];
        foreach (self::findings($today) as $finding) {
            $rows[] = [
                self::findingInfos()[$finding['type']]['label'],
                self::entityName($finding['entity_id']),
                $finding['scope'] === Rules::SCOPE_SUBTREE
                    ? __('Entity and sub-entities', 'grcmanager')
                    : __('Entity only', 'grcmanager'),
                $finding['software_name'],
                $finding['manufacturer'],
                (string) $finding['installs'],
                $finding['type'] === Rules::FINDING_UNLICENSED ? '' : (string) $finding['seats'],
                $finding['licence_name'],
                $finding['expire'],
                in_array($finding['type'], [Rules::FINDING_EXPIRED, Rules::FINDING_EXPIRING], true)
                    ? (string) $finding['assigned'] : '',
                $finding['type'] === Rules::FINDING_OVERDEPLOYED
                    ? ($finding['overquota_allowed'] ? __('Yes') : __('No')) : '',
                self::detail($finding),
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------------ Preuves et cohérence.

    /**
     * Preuve de la mesure A.5.32 : nombre de constats de chaque type (entités actives). Null si la
     * mesure n'est pas concernée ou sans les droits.
     *
     * Lot 2 : licences qui arrivent à échéance (alerte, hors total des écarts) et taux de couverture.
     *
     * @return array{unlicensed: int, overdeployed: int, expired: int, expiring: int, total: int,
     *     coverage: array{watched: int, licensed: int, percent: int|null}|null, url: string,
     *     export_url: string}|null
     */
    public static function evidence(string $code): ?array
    {
        if ($code !== 'A.5.32' || !self::canView()) {
            return null;
        }
        $counts = Rules::counts(self::findings());

        return [
            'unlicensed'   => $counts[Rules::FINDING_UNLICENSED],
            'overdeployed' => $counts[Rules::FINDING_OVERDEPLOYED],
            'expired'      => $counts[Rules::FINDING_EXPIRED],
            'expiring'     => $counts[Rules::FINDING_EXPIRING],
            'total'        => $counts[Rules::FINDING_UNLICENSED] + $counts[Rules::FINDING_OVERDEPLOYED]
                + $counts[Rules::FINDING_EXPIRED],
            'coverage'     => self::coverage(),
            'url'          => self::getSearchURL(),
            'export_url'   => self::getExportURL(),
        ];
    }

    /**
     * Contrôle de cohérence : un écart par constat, sur le logiciel (ou la licence expirée). Null
     * sans les droits (règle non évaluable).
     *
     * @return list<array{itemtype: class-string<\CommonDBTM>, items_id: int, name: string,
     *     detail: string}>|null
     */
    public static function consistencyGaps(?string $today = null): ?array
    {
        if (!self::canView()) {
            return null;
        }
        $gaps = [];
        foreach (self::findings($today) as $finding) {
            // L'échéance proche est un rappel, pas un écart.
            if (!in_array($finding['type'], Rules::GAP_FINDINGS, true)) {
                continue;
            }
            $label = self::findingInfos()[$finding['type']]['label'];
            $detail = sprintf(
                __('%1$s (%2$s): %3$s', 'grcmanager'),
                $label,
                self::entityName($finding['entity_id']),
                self::detail($finding)
            );
            if ($finding['type'] === Rules::FINDING_EXPIRED) {
                $gaps[] = [
                    'itemtype' => SoftwareLicense::class,
                    'items_id' => $finding['licence_id'],
                    'name'     => $finding['licence_name'] !== ''
                        ? $finding['licence_name'] : $finding['software_name'],
                    'detail'   => $detail,
                ];
                continue;
            }
            $gaps[] = [
                'itemtype' => Software::class,
                'items_id' => $finding['software_id'],
                'name'     => $finding['software_name'],
                'detail'   => $detail,
            ];
        }

        return $gaps;
    }
}

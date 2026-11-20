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
use Computer;
use ComputerType;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\DiskEncryptionTables;
use GlpiPlugin\Grcmanager\Services\Compliance\DiskEncryptionRules as Rules;
use Html;
use Session;

/**
 * Issue #847 : chiffrement des disques du parc (BitLocker, FileVault, LUKS…) d'après l'inventaire
 * natif de GLPI, pour les mesures A.8.24 (cryptographie) et A.8.1 (terminaux des utilisateurs).
 *
 * - Réutilise le contrôle technique « chiffrement » (ComplianceAudit::diskEncryptionRows()) : même
 *   lecture des volumes, mêmes exclusions (volumes jamais chiffrés, statuts d'ordinateur exclus).
 * - Taux par entité, type et lieu ; règle réglable « doit être chiffré » ; écarts avec le dernier
 *   inventaire, l'outil et l'algorithme ; algorithme faible ou ancien signalé.
 * - Clé de récupération : le plugin ne lit ni ne stocke jamais de clé. L'administrateur peut
 *   seulement déclarer qu'elle est sauvegardée (case et date).
 *
 * Droits : droit du plugin et lecture native des ordinateurs, dans les entités de la session ;
 * modification du plugin pour la règle et les déclarations. Rien n'est modifié dans GLPI.
 */
class DiskEncryption extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /** Ordinateurs listés au plus sur la page (écarts d'abord). */
    public const MAX_ROWS = 300;

    /** @var list<array<string, mixed>>|null lignes calculées pendant la requête */
    private static ?array $memo = null;

    public static function getTypeName($nb = 0)
    {
        return __('Disk encryption', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-lock';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/diskencryption.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ) && (bool) Computer::canView();
    }

    /**
     * Règle « doit être chiffré » et déclarations de sauvegarde des clés.
     */
    public static function canManage(): bool
    {
        return self::canView() && (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        // Sous-page de la rubrique « Sécurité technique » (Menu::sections()).
        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * Oublie les lignes calculées (après un changement de règle, tests).
     */
    public static function reset(): void
    {
        self::$memo = null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(): array
    {
        return self::$memo ??= self::canView() ? ComplianceAudit::diskEncryptionRows() : [];
    }

    // ------------------------------------------------------------------ Libellés.

    /**
     * @return array<string, array{label: string, color: string, icon: string, help: string}>
     */
    public static function statusInfos(): array
    {
        return [
            Rules::STATUS_ENCRYPTED => [
                'label' => __('Fully encrypted', 'grcmanager'),
                'color' => 'success',
                'icon'  => 'ti ti-lock',
                'help'  => __('Every inventoried volume is encrypted.', 'grcmanager'),
            ],
            Rules::STATUS_PARTIAL   => [
                'label' => __('Partially encrypted', 'grcmanager'),
                'color' => 'warning',
                'icon'  => 'ti ti-lock-half',
                'help'  => __('At least one volume is encrypted, but not all of them.', 'grcmanager'),
            ],
            Rules::STATUS_CLEAR     => [
                'label' => __('Not encrypted', 'grcmanager'),
                'color' => 'danger',
                'icon'  => 'ti ti-lock-open',
                'help'  => __('No inventoried volume is encrypted.', 'grcmanager'),
            ],
            Rules::STATUS_UNKNOWN   => [
                'label' => __('Unknown', 'grcmanager'),
                'color' => 'secondary',
                'icon'  => 'ti ti-help-circle',
                'help'  => __(
                    'No volume inventoried: no inventory agent, or an agent too old to report encryption.',
                    'grcmanager'
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function scopeLabels(): array
    {
        return [
            Rules::SCOPE_ALL     => __('All computers', 'grcmanager'),
            Rules::SCOPE_LAPTOPS => __('Laptops and tablets only', 'grcmanager'),
            Rules::SCOPE_TYPES   => __('Computers of the selected types', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function confidentialityLabels(): array
    {
        return [
            ''       => __('Do not use the classification', 'grcmanager'),
            'medium' => __('Confidentiality medium or high', 'grcmanager'),
            'high'   => __('Confidentiality high', 'grcmanager'),
        ];
    }

    /**
     * Phrase qui résume la règle en vigueur.
     *
     * @param array{scope: string, types: list<int>, confidentiality: string} $rule
     */
    public static function ruleSummary(array $rule): string
    {
        $summary = match ($rule['scope']) {
            Rules::SCOPE_LAPTOPS => __('Laptops and tablets must be encrypted.', 'grcmanager'),
            Rules::SCOPE_TYPES   => $rule['types'] === []
                ? __('No computer type is selected: only the classification below applies.', 'grcmanager')
                : sprintf(
                    __('Computers of these types must be encrypted: %s.', 'grcmanager'),
                    implode(', ', array_map(
                        static fn (int $id): string => self::typeName($id),
                        $rule['types']
                    ))
                ),
            default              => __('All computers must be encrypted.', 'grcmanager'),
        };
        if ($rule['confidentiality'] === 'medium') {
            $summary .= ' '
                . __('So must any computer classified with a medium or high confidentiality.', 'grcmanager');
        } elseif ($rule['confidentiality'] === 'high') {
            $summary .= ' ' . __('So must any computer classified with a high confidentiality.', 'grcmanager');
        }

        return $summary;
    }

    private static function typeName(int $id): string
    {
        return (string) Dropdown::getDropdownName(ComputerType::getTable(), $id);
    }

    // ------------------------------------------------------------------ Calculs.

    /**
     * Synthèse : ordinateurs concernés par la règle, et portables.
     *
     * @return array{required: array<string, mixed>, laptops: array<string, mixed>, all: array<string, mixed>,
     *     gaps: int, weak: int, legacy: int}
     */
    public static function summary(): array
    {
        $rows = self::rows();
        $required = array_values(array_filter($rows, static fn (array $row): bool => (bool) $row['required']));

        return [
            'required' => Rules::summary($required),
            'laptops'  => Rules::summary(array_values(array_filter(
                $rows,
                static fn (array $row): bool => (bool) $row['is_laptop']
            ))),
            'all'      => Rules::summary($rows),
            'gaps'     => count(array_filter($rows, static fn (array $row): bool => (bool) $row['is_gap'])),
            'weak'     => count(array_filter(
                $required,
                static fn (array $row): bool => $row['strength'] === Rules::ALGO_WEAK
            )),
            'legacy'   => count(array_filter(
                $required,
                static fn (array $row): bool => $row['strength'] === Rules::ALGO_LEGACY
            )),
        ];
    }

    /**
     * Déclarations de sauvegarde des clés de récupération, par ordinateur.
     *
     * @param list<int> $computerIds
     * @return array<int, array{is_backed_up: bool, backup_date: string}>
     */
    public static function keyDeclarations(array $computerIds): array
    {
        global $DB;

        if ($computerIds === []) {
            return [];
        }
        $declarations = [];
        foreach (
            $DB->request(['FROM' => DiskEncryptionTables::KEYS, 'WHERE' => ['computers_id' => $computerIds]]) as $row
        ) {
            $declarations[(int) $row['computers_id']] = [
                'is_backed_up' => (bool) $row['is_backed_up'],
                'backup_date'  => (string) ($row['backup_date'] ?? ''),
            ];
        }

        return $declarations;
    }

    /**
     * Déclare (ou retire) la sauvegarde de la clé de récupération d'un ordinateur visible. Aucune clé
     * n'est demandée ni conservée : une case et une date.
     */
    public static function declareKey(int $computerId, bool $backedUp, mixed $date): bool
    {
        global $DB;

        $computer = new Computer();
        if (!self::canManage() || !$computer->getFromDB($computerId) || !$computer->canViewItem()) {
            return false;
        }
        $today = date('Y-m-d', strtotime((string) (Session::getCurrentTime() ?? 'now')));
        $fields = [
            'is_backed_up' => $backedUp ? 1 : 0,
            'backup_date'  => $backedUp ? Rules::normalizeBackupDate($date, $today) : null,
            'users_id'     => (int) Session::getLoginUserID(),
            'date_mod'     => Session::getCurrentTime() ?? date('Y-m-d H:i:s'),
        ];
        $exists = countElementsInTable(DiskEncryptionTables::KEYS, ['computers_id' => $computerId]) > 0;
        $ok = $exists
            ? $DB->update(DiskEncryptionTables::KEYS, $fields, ['computers_id' => $computerId])
            : $DB->insert(DiskEncryptionTables::KEYS, $fields + ['computers_id' => $computerId]);
        AuditLog::record('update', Computer::class, $computerId, 0, 'success', [
            'recovery_key_backed_up' => $backedUp,
            'backup_date'            => $fields['backup_date'],
        ]);

        return (bool) $ok;
    }

    // ------------------------------------------------------------------ Page.

    /**
     * @return array<string, mixed>
     */
    public static function pageData(): array
    {
        $rows = self::rows();
        $rule = ComplianceAudit::getEncryptionRule();
        $required = array_values(array_filter($rows, static fn (array $row): bool => (bool) $row['required']));
        // Écarts d'abord, puis états inconnus, puis le reste, par nom.
        $listed = $required;
        $rank = static fn (array $row): int => $row['is_gap']
            ? 0
            : ($row['status'] === Rules::STATUS_UNKNOWN ? 1 : 2);
        usort($listed, static fn (array $a, array $b): int => [$rank($a), $a['name']] <=> [$rank($b), $b['name']]);
        $shown = array_slice($listed, 0, self::MAX_ROWS);
        $keys = self::keyDeclarations(array_map(static fn (array $row): int => (int) $row['id'], $shown));
        foreach ($shown as &$row) {
            $row['key'] = $keys[$row['id']] ?? ['is_backed_up' => false, 'backup_date' => ''];
            $row['last_inventory_label'] = $row['last_inventory'] !== ''
                ? (string) Html::convDateTime($row['last_inventory'])
                : '';
        }
        unset($row);

        return [
            'summary'         => self::summary(),
            'infos'           => self::statusInfos(),
            'by_entity'       => Rules::breakdown($required, 'entity'),
            'by_type'         => Rules::breakdown($required, 'type'),
            'by_location'     => Rules::breakdown($required, 'location'),
            'rows'            => $shown,
            'total_rows'      => count($listed),
            'rule'            => $rule,
            'rule_summary'    => self::ruleSummary($rule),
            'scopes'          => self::scopeLabels(),
            'thresholds'      => self::confidentialityLabels(),
            'type_names'      => array_map(static fn (int $id): string => self::typeName($id), $rule['types']),
            'can_manage'      => self::canManage(),
            'action'          => self::getSearchURL(),
            'audit_url'       => ComplianceAudit::getSearchURL(),
            'computer_url'    => Computer::getSearchURL(),
            'today'           => date('Y-m-d'),
        ];
    }

    public static function showPage(): void
    {
        $data = self::pageData();
        TemplateRenderer::getInstance()->display('@grcmanager/disk_encryption.html.twig', $data);
    }

    // ------------------------------------------------------------------ Preuves, tableau de bord.

    /**
     * Preuve des mesures A.8.24 et A.8.1 : taux, date du calcul, nombre d'écarts. Null si la mesure
     * n'est pas concernée ou sans les droits.
     *
     * @return array{rate: ?int, laptop_rate: ?int, required: int, gaps: int, unknown: int, weak: int,
     *     date: string, url: string}|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, ['A.8.24', 'A.8.1'], true) || !self::canView()) {
            return null;
        }
        $summary = self::summary();

        return [
            'rate'        => $summary['required']['rate'],
            'laptop_rate' => $summary['laptops']['rate'],
            'required'    => (int) $summary['required']['total'],
            'gaps'        => $summary['gaps'],
            'unknown'     => (int) $summary['required'][Rules::STATUS_UNKNOWN],
            'weak'        => $summary['weak'],
            'date'        => (string) Html::convDateTime(Session::getCurrentTime() ?? date('Y-m-d H:i:s')),
            'url'         => self::getSearchURL(),
        ];
    }

    /**
     * Carte du tableau de bord : ordinateurs concernés entièrement chiffrés (%).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function dashboardCard(array $params = []): array
    {
        $rate = self::canView() ? self::summary()['required']['rate'] : null;

        return [
            'number' => $rate ?? 0,
            'url'    => self::getSearchURL(),
            'label'  => $params['label'] ?? __('Computers that must be encrypted and are (%)', 'grcmanager'),
            'icon'   => self::getIcon(),
        ];
    }

    /**
     * Préparation à la certification : constat affiché sous l'étape du traitement des risques, null
     * sans les droits ou sans ordinateur concerné dont l'état est connu.
     */
    public static function readinessFact(): ?string
    {
        if (!self::canView()) {
            return null;
        }
        $summary = self::summary();
        if ($summary['required']['rate'] === null) {
            return null;
        }

        return sprintf(
            __('Disk encryption: %1$d%% of the computers that must be encrypted are, %2$d gap(s)', 'grcmanager'),
            $summary['required']['rate'],
            $summary['gaps']
        );
    }
}

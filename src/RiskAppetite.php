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
use GlpiPlugin\Grcmanager\Services\Risk\RiskAppetiteRules;
use Session;

/**
 * Issue #378 : appétence et tolérance au risque, par catégorie du registre des risques.
 *
 * Une ligne par catégorie (personnes, processus, physique, tiers, technique) : niveau accepté sans
 * action (appétence), niveau au-delà duquel le risque n'est plus supportable (tolérance), énoncé
 * validé par la direction, et qui l'a validé quand (la personne qui enregistre). Comme le registre
 * des risques, ces réglages sont globaux et relèvent du droit des registres sensibles.
 *
 * Rien n'est imposé tant qu'une catégorie n'a pas d'appétence : les risques existants ne changent
 * pas. Un risque au-dessus de l'appétence demande une décision ; l'accepter devient une
 * acceptation formelle, justifiée et signée (Risk::applyAppetite()).
 */
class RiskAppetite extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager_sensitive';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    public static function getTable(): string
    {
        return 'glpi_plugin_grcmanager_riskappetites';
    }

    public static function getTypeName($nb = 0)
    {
        return __('Risk appetite and tolerance', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-gauge';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/riskappetite.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ);
    }

    public static function canUpdate(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * Libellés des positions d'un risque.
     *
     * @return array<string, string>
     */
    public static function getPositions(): array
    {
        return [
            RiskAppetiteRules::WITHIN          => __('Within appetite', 'grcmanager'),
            RiskAppetiteRules::ABOVE_APPETITE  => __('Above appetite', 'grcmanager'),
            RiskAppetiteRules::ABOVE_TOLERANCE => __('Above tolerance', 'grcmanager'),
            RiskAppetiteRules::UNDEFINED       => __('No appetite defined', 'grcmanager'),
        ];
    }

    /**
     * Badge d'un niveau de risque pour les gabarits (_badge.html.twig), mêmes couleurs que
     * RiskAssessmentTrait::riskLevelBadge().
     *
     * @return array{class: string, icon: ?string, label: string}
     */
    public static function levelBadgeData(string $level): array
    {
        $classes = [
            'low' => 'bg-green-lt', 'medium' => 'bg-yellow-lt', 'high' => 'bg-orange-lt', 'critical' => 'bg-red-lt',
        ];

        return [
            'class' => $classes[$level] ?? 'bg-secondary-lt',
            'icon'  => in_array($level, ['high', 'critical'], true) ? 'ti-alert-triangle' : null,
            'label' => Risk::getImpacts()[$level] ?? $level,
        ];
    }

    /**
     * Réglages enregistrés, par catégorie.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        global $DB;

        if (self::$cache === null) {
            self::$cache = [];
            if ($DB->tableExists(self::getTable())) {
                foreach ($DB->request(['FROM' => self::getTable()]) as $row) {
                    self::$cache[(string) $row['category']] = $row;
                }
            }
        }

        return self::$cache;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * Position d'un niveau de risque dans sa catégorie.
     */
    public static function positionFor(string $category, ?string $level): string
    {
        $row = self::all()[$category] ?? null;
        if ($row === null) {
            return RiskAppetiteRules::UNDEFINED;
        }

        return RiskAppetiteRules::position(
            $level,
            (string) $row['appetite_level'],
            (string) ($row['tolerance_level'] ?? '')
        );
    }

    /**
     * Enregistre les réglages postés (une entrée par catégorie). Une catégorie laissée sans
     * appétence est supprimée ; un couple incohérent (tolérance sous l'appétence) est refusé. La
     * personne qui enregistre un changement en devient l'approbateur, à la date du jour.
     *
     * @param array<string, mixed> $input catégorie => [appetite_level, tolerance_level, statement]
     * @return list<string> catégories refusées
     */
    public static function save(array $input): array
    {
        global $DB;

        if (!self::canUpdate()) {
            return [];
        }
        $current = self::all();
        $rejected = [];
        $changes = [];
        foreach (array_keys(Risk::getCategories()) as $category) {
            $posted = $input[$category] ?? null;
            if (!is_array($posted)) {
                continue;
            }
            $appetite = (string) ($posted['appetite_level'] ?? '');
            $tolerance = (string) ($posted['tolerance_level'] ?? '');
            $statement = trim((string) ($posted['statement'] ?? ''));
            if ($appetite === '') {
                if (isset($current[$category])) {
                    $DB->delete(self::getTable(), ['category' => $category]);
                    $changes[$category] = null;
                }
                continue;
            }
            if (!RiskAppetiteRules::isValidPair($appetite, $tolerance)) {
                $rejected[] = $category;
                continue;
            }
            $before = $current[$category] ?? null;
            if (
                $before !== null
                && (string) $before['appetite_level'] === $appetite
                && (string) $before['tolerance_level'] === $tolerance
                && (string) $before['statement'] === $statement
            ) {
                continue;
            }
            $now = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
            $DB->updateOrInsert(self::getTable(), [
                'category'          => $category,
                'appetite_level'    => $appetite,
                'tolerance_level'   => $tolerance,
                'statement'         => $statement,
                'users_id_approval' => (int) Session::getLoginUserID(),
                'date_approval'     => $now,
                'date_mod'          => $now,
            ], ['category' => $category]);
            $changes[$category] = ['appetite' => $appetite, 'tolerance' => $tolerance];
        }
        self::clearCache();
        if ($changes !== []) {
            AuditLog::record('config_change', self::class, 0, 0, 'success', ['risk_appetite' => $changes]);
        }

        return $rejected;
    }

    /**
     * Risques ouverts au-dessus de l'appétence de leur catégorie, les plus graves d'abord. Le
     * registre est global : l'appelant a le droit des registres sensibles.
     *
     * @return list<array<string, mixed>>
     */
    public static function risksAboveAppetite(): array
    {
        global $DB;

        $settings = self::all();
        if ($settings === []) {
            return [];
        }
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => Risk::getTable(),
                'WHERE' => ['status' => ['<>', 'closed'], 'category' => array_keys($settings)],
                'ORDER' => ['computed_score DESC', 'title'],
            ]) as $row
        ) {
            $position = self::positionFor((string) $row['category'], (string) $row['risk_level']);
            if (!RiskAppetiteRules::isAboveAppetite($position)) {
                continue;
            }
            $rows[] = $row + [
                'position'       => $position,
                'needs_decision' => RiskAppetiteRules::needsDecision(
                    $position,
                    (string) $row['treatment'],
                    (string) $row['status']
                ),
            ];
        }
        usort($rows, static fn (array $a, array $b): int
            => ($b['position'] === RiskAppetiteRules::ABOVE_TOLERANCE)
                <=> ($a['position'] === RiskAppetiteRules::ABOVE_TOLERANCE));

        return $rows;
    }

    /**
     * Nombre de risques ouverts au-dessus de l'appétence (ou de la tolérance seulement).
     */
    public static function countAbove(bool $toleranceOnly): int
    {
        return count(array_filter(
            self::risksAboveAppetite(),
            static fn (array $row): bool => !$toleranceOnly || $row['position'] === RiskAppetiteRules::ABOVE_TOLERANCE
        ));
    }
}

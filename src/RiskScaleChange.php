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

use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixDefaults;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScale;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScaleMigration;
use GlpiPlugin\Grcmanager\Services\Risk\RiskScoringService;
use Log;
use Session;
use Throwable;

/**
 * Issue #706 : changement de taille de la matrice des risques (4x4 ou 5x5) sur un registre
 * existant (front/riskscale.php).
 *
 * L'administrateur valide la correspondance des anciennes valeurs vers les nouvelles après en avoir
 * vu l'effet (aperçu calculé par RiskScaleMigration, le même calcul que l'application). Les risques
 * et les risques fournisseurs sont convertis dans une transaction, avec la nouvelle grille par
 * défaut ; chaque risque modifié garde dans son historique l'ancienne et la nouvelle valeur de
 * chaque champ, plus un message qui explique la conversion. La conversion ne passe pas par
 * Risk::update() : elle ne doit ni signer une acceptation au nom de l'administrateur, ni envoyer de
 * notification, ni être bloquée par une règle de saisie sans rapport avec l'échelle.
 */
final class RiskScaleChange
{
    public static function getPageURL(bool $full = true): string
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/riskscale.php';
    }

    /**
     * @return array<int, string>
     */
    public static function sizeLabels(): array
    {
        // Issue #1163 : chaque axe a de 3 à 5 niveaux, indépendamment ; formes rangées par nombre
        // de niveaux de probabilité puis d'impact.
        $labels = [];
        foreach ([3, 4, 5] as $probability) {
            foreach ([3, 4, 5] as $impact) {
                $size = RiskScale::shape($probability, $impact);
                $labels[$size] = match ($size) {
                    4       => __('4 x 4: four levels of probability and of impact', 'grcmanager'),
                    5       => __('5 x 5: five levels of probability and of impact', 'grcmanager'),
                    default => sprintf(
                        __('%1$d x %2$d: %1$d levels of probability, %2$d levels of impact', 'grcmanager'),
                        $probability,
                        $impact
                    ),
                };
            }
        }

        return $labels;
    }

    /**
     * Nom court d'une forme : niveaux de probabilité x niveaux d'impact.
     */
    public static function sizeName(int $size): string
    {
        return sprintf(
            __('%1$d x %2$d', 'grcmanager'),
            RiskScale::probabilityLevels($size),
            RiskScale::impactLevels($size)
        );
    }

    public static function scoring(int $size): RiskScoringService
    {
        return new RiskScoringService(RiskMatrixDefaults::forSize($size), $size);
    }

    /**
     * Données de l'écran : valeurs actuelles et proposées, nombre de risques qui utilisent chaque
     * valeur actuelle, effet de la correspondance sur les niveaux.
     *
     * @param array{probability: array<string, string>, impact: array<string, string>} $mapping
     * @return array<string, mixed>
     */
    public static function preview(int $to, array $mapping): array
    {
        $from = RiskMatrixConfig::size();
        $uses = ['probability' => [], 'impact' => []];
        $summary = null;
        foreach (self::registers() as [$table, $withResidual]) {
            $rows = self::rows($table, $withResidual);
            foreach ($rows as $row) {
                foreach (['probability', 'impact'] as $axis) {
                    $values = array_unique(array_filter([
                        (string) ($row[$axis] ?? ''),
                        (string) ($row['residual_' . $axis] ?? ''),
                    ]));
                    foreach ($values as $value) {
                        $uses[$axis][$value] = ($uses[$axis][$value] ?? 0) + 1;
                    }
                }
            }
            $part = RiskScaleMigration::summarize($rows, $mapping, self::scoring($to), $withResidual);
            $summary = $summary === null ? $part : RiskScaleMigration::merge($summary, $part);
        }

        $axes = [];
        foreach (['probability', 'impact'] as $axis) {
            $oldLabels = $axis === 'probability' ? Risk::getProbabilities($from) : Risk::getImpacts($from);
            $axes[$axis] = [
                'title'   => $axis === 'probability' ? __('Probability', 'grcmanager') : __('Impact', 'grcmanager'),
                'targets' => $axis === 'probability' ? Risk::getProbabilities($to) : Risk::getImpacts($to),
                'rows'    => [],
            ];
            foreach ($oldLabels as $key => $label) {
                $axes[$axis]['rows'][] = [
                    'key'    => $key,
                    'label'  => $label,
                    'uses'   => $uses[$axis][$key] ?? 0,
                    'target' => $mapping[$axis][$key],
                ];
            }
        }

        return [
            'from'    => $from,
            'to'      => $to,
            'axes'    => $axes,
            'summary' => $summary,
            'matrix'  => RiskMatrixDefaults::forSize($to),
            'levels'  => Risk::getRiskLevels(),
            'target_probabilities' => Risk::getProbabilities($to),
            'target_impacts'       => Risk::getImpacts($to),
        ];
    }

    /**
     * Applique la taille et la correspondance. Rien n'est écrit si la taille est déjà celle demandée.
     *
     * @param array{probability: array<string, string>, impact: array<string, string>} $mapping
     * @return int|null nombre de risques modifiés, null si rien n'a été fait
     */
    public static function apply(int $to, array $mapping): ?int
    {
        global $DB;

        $from = RiskMatrixConfig::size();
        $to = RiskScale::normalizeSize($to);
        if ($from === $to) {
            return null;
        }
        $scoring = self::scoring($to);
        $message = sprintf(
            __(
                'Risk matrix changed from %1$s to %2$s: values converted with the correspondence validated '
                    . 'by the administrator',
                'grcmanager'
            ),
            self::sizeName($from),
            self::sizeName($to)
        );
        $changed = 0;
        $now = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        $DB->beginTransaction();
        try {
            RiskMatrixConfig::saveScale($to, RiskMatrixDefaults::forSize($to));
            foreach (self::registers() as [$table, $withResidual, $itemtype]) {
                foreach (self::rows($table, $withResidual) as $row) {
                    $changes = RiskScaleMigration::convertRow($row, $mapping, $scoring, $withResidual);
                    if ($changes === []) {
                        continue;
                    }
                    $DB->update($table, $changes + ['date_mod' => $now], ['id' => (int) $row['id']]);
                    $item = new $itemtype();
                    $item->fields = array_merge($row, $changes);
                    Log::constructHistory($item, array_intersect_key($row, $changes), $changes);
                    Log::history(
                        (int) $row['id'],
                        $itemtype,
                        [0, '', $message],
                        '',
                        Log::HISTORY_LOG_SIMPLE_MESSAGE
                    );
                    $changed++;
                }
            }
            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            RiskMatrixConfig::reset();
            throw $e;
        }
        AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'risk_matrix_size' => ['from' => $from, 'to' => $to],
            'mapping'          => $mapping,
            'risks_changed'    => $changed,
        ]);

        return $changed;
    }

    /**
     * Registres concernés : table, avec ou sans risque résiduel, type d'objet (historique).
     *
     * @return list<array{0: string, 1: bool, 2: class-string<\CommonDBTM>}>
     */
    private static function registers(): array
    {
        return [
            [Risk::getTable(), true, Risk::class],
            [SupplierRisk::getTable(), false, SupplierRisk::class],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(string $table, bool $withResidual): array
    {
        global $DB;

        $fields = ['id', 'probability', 'impact', 'risk_level', 'computed_score'];
        if ($withResidual) {
            array_push($fields, 'residual_probability', 'residual_impact', 'residual_level');
            if ($DB->fieldExists($table, 'residual_score')) {
                $fields[] = 'residual_score';
            }
        }
        $rows = [];
        foreach ($DB->request(['SELECT' => $fields, 'FROM' => $table, 'ORDER' => 'id']) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }
}

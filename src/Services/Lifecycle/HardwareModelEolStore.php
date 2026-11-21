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

namespace GlpiPlugin\Grcmanager\Services\Lifecycle;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Install\HardwareEndOfLifeTables;

/**
 * Issue #782, lot 2 : dates de fin de vie déclarées par le constructeur, saisies à la main par
 * modèle de matériel (facultatif). Les tables de modèles de GLPI ne sont jamais modifiées : les
 * dates vivent dans une table du plugin, clé « classe du modèle + identifiant ».
 *
 * NOTE : dépend du $DB de GLPI, hors de l'analyse en PHP pur (phpstan.neon.dist).
 */
final class HardwareModelEolStore
{
    /** @var array<string, string>|null « ComputerModel:12 » => « 2027-06-30 ». */
    private static ?array $dates = null;

    public static function reset(): void
    {
        self::$dates = null;
    }

    public static function key(string $modelItemtype, int $modelId): string
    {
        return $modelItemtype . ':' . $modelId;
    }

    /**
     * Classes de modèle des types de matériel suivis (ComputerModel, MonitorModel...).
     *
     * @return list<class-string<CommonDBTM>>
     */
    public static function modelClasses(): array
    {
        $classes = [];
        foreach (HardwareWarrantyInventory::hardwareTypes() as $itemtype) {
            $model = (new $itemtype())->getModelClass();
            if (is_string($model) && is_a($model, CommonDBTM::class, true) && !in_array($model, $classes, true)) {
                $classes[] = $model;
            }
        }

        return $classes;
    }

    /**
     * @return array<string, string>
     */
    public static function dates(): array
    {
        global $DB;

        if (self::$dates === null) {
            self::$dates = [];
            if ($DB->tableExists(HardwareEndOfLifeTables::MODELS)) {
                foreach ($DB->request(['FROM' => HardwareEndOfLifeTables::MODELS]) as $row) {
                    $date = WarrantyRules::date((string) ($row['end_of_life_date'] ?? ''));
                    if ($date !== null) {
                        self::$dates[self::key((string) $row['itemtype'], (int) $row['items_id'])] = $date;
                    }
                }
            }
        }

        return self::$dates;
    }

    public static function dateFor(string $modelItemtype, int $modelId): ?string
    {
        return $modelId > 0 ? (self::dates()[self::key($modelItemtype, $modelId)] ?? null) : null;
    }

    /**
     * Enregistre (ou efface, date vide) la fin de vie d'un modèle. Renvoie false si la saisie est
     * invalide (classe de modèle non suivie, identifiant ou date invalides) ; true si rien ne change.
     */
    public static function save(string $modelItemtype, int $modelId, string $date, int $userId): bool
    {
        global $DB;

        $date = trim($date);
        $valid = WarrantyRules::date($date);
        if (
            $modelId <= 0
            || !in_array($modelItemtype, self::modelClasses(), true)
            || ($date !== '' && $valid === null)
        ) {
            return false;
        }
        $where = ['itemtype' => $modelItemtype, 'items_id' => $modelId];
        if ($valid === null) {
            $DB->delete(HardwareEndOfLifeTables::MODELS, $where);
        } elseif (countElementsInTable(HardwareEndOfLifeTables::MODELS, $where) > 0) {
            $DB->update(
                HardwareEndOfLifeTables::MODELS,
                ['end_of_life_date' => $valid, 'users_id' => $userId, 'date_mod' => date('Y-m-d H:i:s')],
                $where
            );
        } else {
            $DB->insert(HardwareEndOfLifeTables::MODELS, $where + [
                'end_of_life_date' => $valid,
                'users_id'         => $userId,
                'date_mod'         => date('Y-m-d H:i:s'),
            ]);
        }
        self::reset();

        return true;
    }
}

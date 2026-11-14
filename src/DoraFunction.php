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

use GlpiPlugin\Grcmanager\Compatibility\Base\DoraFunctionBase;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterRules;
use GlpiPlugin\Grcmanager\Services\Dora\RegisterTemplates;
use Log;

/**
 * Issue #540 : fonction du registre d'informations DORA (modèle B_06.01), portée par un processus
 * métier du plugin. Nom, RTO, RPO, date de la BIA et impact d'une interruption viennent du
 * processus et de sa BIA ; seuls l'activité agréée et l'évaluation « critique ou importante »
 * sont propres à DORA. Historisé sur le processus.
 */
class DoraFunction extends DoraFunctionBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /** Activité à déclarer pour une fonction sans activité agréée (B_06.01.0020). */
    public const SUPPORT_FUNCTIONS = 'support functions';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_dorafunctions';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('DORA function', 'DORA functions', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-briefcase';
    }

    /** Identifiant de la fonction (B_06.01.0010) : F suivi du numéro du processus. */
    public static function identifier(int $processId): string
    {
        return 'F' . $processId;
    }

    /**
     * @return array<string, mixed>
     */
    public static function forProcess(int $processId): array
    {
        $item = new self();

        return $processId > 0 && $item->getFromDBByCrit(['plugin_grcmanager_businessprocesses_id' => $processId])
            ? $item->fields : [];
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function saveFor(int $processId, array $input): bool
    {
        $clean = [
            'licensed_activity'   => mb_substr(trim((string) ($input['licensed_activity'] ?? '')), 0, 255),
            'criticality'         => RegisterRules::option($input['criticality'] ?? 0, RegisterTemplates::CRITICALITY),
            'criticality_reasons' => mb_substr(trim((string) ($input['criticality_reasons'] ?? '')), 0, 300),
            'assessment_date'     => RegisterRules::date($input['assessment_date'] ?? '') ?? 'NULL',
        ];
        $item = new self();
        if ($item->getFromDBByCrit(['plugin_grcmanager_businessprocesses_id' => $processId])) {
            return (bool) $item->update(['id' => $item->getID()] + $clean);
        }

        return (bool) $item->add(['plugin_grcmanager_businessprocesses_id' => $processId] + $clean);
    }

    public function post_addItem()
    {
        $this->logOnProcess();
    }

    public function post_updateItem($history = true)
    {
        if ($this->updates !== []) {
            $this->logOnProcess();
        }
    }

    private function logOnProcess(): void
    {
        DoraRegister::resetCache();
        Log::history(
            (int) $this->fields['plugin_grcmanager_businessprocesses_id'],
            BusinessProcess::class,
            [0, '', __('DORA register: function information updated', 'grcmanager')],
            0,
            Log::HISTORY_LOG_SIMPLE_MESSAGE
        );
    }
}

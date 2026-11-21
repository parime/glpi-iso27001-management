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

namespace GlpiPlugin\Grcmanager\Services\ServerManagement;

use GlpiPlugin\Grcmanager\Services\Access\AccessReviewLogic;
use GlpiPlugin\Grcmanager\Services\Access\AccessReviewSuggestionRules;

/**
 * Issue #843, lot 2 : suggestion pour un compte local d'un contrôleur repris dans une campagne de
 * revue des accès (A.5.18, A.8.2). Un compte d'un contrôleur donne la main sur le serveur, même
 * éteint : tout compte d'administration est à examiner, un compte désactivé à retirer du
 * contrôleur, un compte livré par le constructeur à remplacer par un compte nominatif.
 *
 * Rien n'est modifié sur le contrôleur : le relecteur décide, le plugin note.
 */
final class BmcAccountReviewRules
{
    public const R_DEFAULT = 'bmc_default';
    public const R_DISABLED = 'bmc_disabled';
    public const R_LOCKED = 'bmc_locked';
    public const R_ADMIN = 'bmc_admin';
    public const R_NO_SIGNAL = 'bmc_no_signal';

    /** Décisions possibles sur un compte de contrôleur (pas de « modifier » : un rôle se change sur place). */
    public const DECISIONS = [AccessReviewLogic::DECISION_KEEP, AccessReviewLogic::DECISION_REMOVE];

    /**
     * @return array{suggestion: string, reasons: list<string>}
     */
    public static function suggest(string $username, string $role, int $enabled, int $locked): array
    {
        $reasons = [];
        $remove = false;
        $examine = false;
        if (BmcRules::isDefaultAccount($username) && $enabled !== 0) {
            $reasons[] = self::R_DEFAULT;
            $remove = true;
        }
        if ($enabled === 0) {
            $reasons[] = self::R_DISABLED;
            $remove = true;
        }
        if ($locked === 1) {
            $reasons[] = self::R_LOCKED;
            $examine = true;
        }
        if (BmcRules::isAdminRole($role)) {
            $reasons[] = self::R_ADMIN;
            $examine = true;
        }
        if ($reasons === []) {
            $reasons[] = self::R_NO_SIGNAL;
        }

        return [
            'suggestion' => $remove
                ? AccessReviewSuggestionRules::REMOVE
                : ($examine ? AccessReviewSuggestionRules::EXAMINE : AccessReviewSuggestionRules::KEEP),
            'reasons'    => $reasons,
        ];
    }

    public static function isDecision(string $decision): bool
    {
        return in_array($decision, self::DECISIONS, true);
    }
}

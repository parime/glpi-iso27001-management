<?php

/**
 * -------------------------------------------------------------------------
 * GLPI GRC Manager plugin for GLPI
 * Copyright (C) 2026 Vincent GUILLOTTE
 * https://github.com/parime/glpi-iso27001-management
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

namespace GlpiPlugin\Grcmanager\Services\Policy;

/**
 * Issue #283 : la politique « Charte informatique » est la référence de version des campagnes de
 * charte d'assetsign. Règle pure (sans GLPI) : quand faut-il proposer une nouvelle campagne ?
 */
final class CharterSignature
{
    /**
     * Une version approuvée après la dernière campagne d'une entité (ou sans campagne) appelle une
     * nouvelle signature. Brouillon ou archivée : rien à proposer.
     */
    public static function needsNewCampaign(string $status, ?string $approvalDate, ?string $lastCampaignDate): bool
    {
        if ($status !== PolicyLifecycle::STATUS_APPROVED || $approvalDate === null || $approvalDate === '') {
            return false;
        }
        if ($lastCampaignDate === null || $lastCampaignDate === '') {
            return true;
        }

        return substr($approvalDate, 0, 10) > substr($lastCampaignDate, 0, 10);
    }
}

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

namespace GlpiPlugin\Grcmanager\Services\Control;

use Glpi\DBAL\QueryExpression;

/**
 * Partie dépendante de GLPI (global $DB, Plugin, Session) de l'issue #109, voir
 * AssetsignEvidence pour la logique et le périmètre. Exclue de PHPStan comme les autres services
 * lisant $DB (voir phpstan.neon.dist).
 */
final class AssetsignEvidenceProvider
{
    /** assetsign installé, actif et sa table présente (une désinstallation partielle ne casse rien). */
    public static function isAvailable(): bool
    {
        global $DB;

        return \Plugin::isPluginActive(AssetsignEvidence::PLUGIN_KEY) && $DB->tableExists(AssetsignEvidence::TABLE);
    }

    /**
     * Compteurs agrégés sur les entités actives de l'utilisateur, ou null si assetsign est absent.
     *
     * @return array<int, array{signed: int, pending: int, expired: int}>|null
     */
    public static function fetchSummary(): ?array
    {
        global $DB;

        if (!self::isAvailable()) {
            return null;
        }

        $rows = $DB->request([
            'SELECT'  => ['type', 'status', new QueryExpression('COUNT(*) AS cpt')],
            'FROM'    => AssetsignEvidence::TABLE,
            'WHERE'   => [
                'is_deleted' => 0,
                'type'       => [AssetsignEvidence::TYPE_HANDOVER, AssetsignEvidence::TYPE_RETURN],
            ] + \getEntitiesRestrictCriteria(AssetsignEvidence::TABLE, '', '', true),
            'GROUPBY' => ['type', 'status'],
        ]);

        return AssetsignEvidence::summarize($rows);
    }

    /**
     * Issue assetsign #204 : taux de signature de la charte informatique par entité (campagne de
     * charte la plus récente de chacune des entités actives de l'utilisateur), ou null si
     * assetsign est absent ou antérieur aux campagnes de charte.
     *
     * @return list<array{entities_id: int, campaign: string, date: string|null, signed: int, total: int, rate: int}>|null
     */
    public static function fetchCharterSummary(): ?array
    {
        global $DB;

        if (
            !self::isAvailable()
            || !$DB->tableExists(AssetsignEvidence::ATTESTATIONS_TABLE)
            || !$DB->fieldExists(AssetsignEvidence::CAMPAIGNS_TABLE, 'type')
        ) {
            return null;
        }

        $campaigns = AssetsignEvidence::CAMPAIGNS_TABLE;
        $attestations = AssetsignEvidence::ATTESTATIONS_TABLE;
        $rows = $DB->request([
            'SELECT'    => [
                "$campaigns.entities_id",
                "$campaigns.name",
                "$campaigns.date_creation",
                new QueryExpression('COUNT(' . $DB->quoteName("$attestations.id") . ') AS total'),
                new QueryExpression(
                    'SUM(CASE WHEN ' . $DB->quoteName("$attestations.status") . ' = '
                    . AssetsignEvidence::ATTESTATION_SIGNED . ' THEN 1 ELSE 0 END) AS signed'
                ),
            ],
            'FROM'      => $campaigns,
            'LEFT JOIN' => [
                $attestations => ['FKEY' => [$attestations => 'plugin_assetsign_campaigns_id', $campaigns => 'id']],
            ],
            'WHERE'     => ["$campaigns.type" => AssetsignEvidence::CAMPAIGN_TYPE_CHARTER]
                + \getEntitiesRestrictCriteria($campaigns, '', '', true),
            'GROUPBY'   => ["$campaigns.id", "$campaigns.entities_id", "$campaigns.name", "$campaigns.date_creation"],
            'ORDER'     => ["$campaigns.date_creation DESC", "$campaigns.id DESC"],
        ]);

        return AssetsignEvidence::summarizeCharter($rows);
    }

    /** Liste des campagnes assetsign, uniquement si l'utilisateur peut la lire. */
    public static function campaignsUrl(): ?string
    {
        global $CFG_GLPI;

        if (!\Session::haveRight(AssetsignEvidence::RIGHTNAME, READ)) {
            return null;
        }

        return $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/campaign.php';
    }

    /** Lien vers la liste assetsign filtrée sur un type, uniquement si l'utilisateur peut la lire. */
    public static function listUrl(int $type): ?string
    {
        global $CFG_GLPI;

        if (!\Session::haveRight(AssetsignEvidence::RIGHTNAME, READ)) {
            return null;
        }

        // Option de recherche 6 = colonne `type` de la fiche assetsign (Assetsign::rawSearchOptions()).
        return $CFG_GLPI['root_doc'] . '/plugins/assetsign/front/assetsign.php?' . http_build_query([
            'criteria' => [['field' => 6, 'searchtype' => 'equals', 'value' => $type]],
        ]);
    }
}

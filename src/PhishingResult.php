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
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Install\PhishingTables;
use GlpiPlugin\Grcmanager\Services\Phishing\PhishingRules as Rules;
use Html;
use Preference;
use Session;
use User;

/**
 * Issue #604 : résultats d'une campagne de simulation d'hameçonnage, importés d'un fichier CSV et
 * rapprochés des utilisateurs GLPI par adresse électronique (ou identifiant de connexion).
 *
 * - Onglet « Résultats » de la campagne : taux agrégés pour qui lit la campagne ; le détail par
 *   personne et les lignes non rapprochées exigent en plus le droit des registres sensibles.
 * - Onglet « Mes simulations d'hameçonnage » des préférences : chacun voit ses propres résultats,
 *   sans droit sur le plugin.
 *
 * Seuls les utilisateurs de l'entité de la campagne (ou de ses sous-entités si elle est récursive)
 * sont rapprochés. Rien n'est envoyé aux personnes : la formation de rattrapage est seulement
 * proposée (formulaire de formation pré-rempli, enregistré par la personne qui l'ouvre).
 */
class PhishingResult extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return _n('Phishing simulation result', 'Phishing simulation results', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-fish-hook';
    }

    public static function getImportURL(int $campaignId = 0): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/phishingimport.php'
            . ($campaignId > 0 ? '?id=' . $campaignId : '');
    }

    /** Lecture de la campagne (droit et entité) et droit des registres sensibles en lecture. */
    public static function canSeeDetail(PhishingCampaign $campaign): bool
    {
        return !$campaign->isNewItem() && $campaign->can($campaign->getID(), READ)
            && Session::haveRight(Installer::SENSITIVE_RIGHT, READ);
    }

    /** Modification de la campagne (droit et entité) et droit des registres sensibles en modification. */
    public static function canImport(PhishingCampaign $campaign): bool
    {
        return !$campaign->isNewItem() && $campaign->can($campaign->getID(), UPDATE)
            && Session::haveRight(Installer::SENSITIVE_RIGHT, UPDATE);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Preference) {
            // Onglet montré seulement à qui a des résultats : rien à voir pour les autres.
            $mine = count(self::myResults((int) Session::getLoginUserID()));

            return $mine > 0
                ? self::createTabEntry(__('My phishing simulations', 'grcmanager'), $mine, null, self::getIcon())
                : '';
        }
        if (!$item instanceof PhishingCampaign || $item->isNewItem() || !PhishingCampaign::canView()) {
            return '';
        }

        return self::createTabEntry(__('Results', 'grcmanager'), 0, null, 'ti ti-chart-bar');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if ($item instanceof Preference) {
            if (Session::getLoginUserID() === false) {
                return false;
            }
            TemplateRenderer::getInstance()->display('@grcmanager/my_phishing_tab.html.twig', [
                'rows' => self::myResults((int) Session::getLoginUserID()),
            ]);

            return true;
        }
        if (!$item instanceof PhishingCampaign || !$item->can($item->getID(), READ)) {
            return false;
        }
        $canDetail = self::canSeeDetail($item);
        $remedial = $canDetail ? count(self::remedialUserIds($item->getID())) : 0;

        TemplateRenderer::getInstance()->display('@grcmanager/phishing_results_tab.html.twig', [
            'campaign'     => $item->fields,
            'summary'      => self::summaryFor($item),
            'rows'         => $canDetail ? self::detailRows($item->getID()) : [],
            'unmatched'    => $canDetail ? self::unmatchedRows($item->getID()) : [],
            'can_import'   => self::canImport($item),
            'import_url'   => self::getImportURL($item->getID()),
            'example_url'  => self::getImportURL() . '?example=1',
            'anonymize_url' => PhishingCampaign::getFormURL(),
            'max_mb'       => Rules::MAX_BYTES / 1048576,
            'remedial'     => $remedial,
            'remedial_url' => $remedial > 0 && Training::canCreate()
                ? Training::getFormURL() . '?phishing_of=' . $item->getID()
                : '',
        ]);

        return true;
    }

    /**
     * Compteurs et taux de la campagne, masqués sur trop peu de personnes à qui ne voit pas le détail.
     *
     * @return array<string, mixed>
     */
    public static function summaryFor(PhishingCampaign $campaign): array
    {
        $counts = PhishingCampaign::countsOf($campaign->fields);
        $canDetail = self::canSeeDetail($campaign);

        return [
            'counts'      => $counts,
            'rates'       => Rules::rates($counts),
            'hidden'      => !$canDetail && !Rules::isAnonymousEnough($counts['sent']),
            'can_detail'  => $canDetail,
            'min'         => Rules::MIN_ANONYMOUS,
            'unmatched'   => (int) ($campaign->fields['nb_unmatched'] ?? 0),
            'imported'    => !empty($campaign->fields['date_import']),
            'import_file' => (string) ($campaign->fields['import_file'] ?? ''),
            'date_import' => !empty($campaign->fields['date_import'])
                ? Html::convDateTime((string) $campaign->fields['date_import']) : '',
            'anonymized'  => !empty($campaign->fields['date_anonymized'])
                ? Html::convDateTime((string) $campaign->fields['date_anonymized']) : '',
        ];
    }

    /**
     * Utilisateur GLPI d'une adresse ou d'un identifiant, parmi les utilisateurs actifs qui ont un
     * profil dans le périmètre de la campagne ; 0 si aucun ou plusieurs.
     */
    public static function matchUser(string $identifier, int $entityId, bool $recursive): int
    {
        if ($identifier === '') {
            return 0;
        }
        $candidates = [];
        if (Rules::isEmail($identifier)) {
            $candidates = array_map('intval', User::getUsersIdByEmails($identifier, ['glpi_users.is_deleted' => 0]));
        } else {
            $user = new User();
            if ($user->getFromDBbyName($identifier) && empty($user->fields['is_deleted'])) {
                $candidates = [(int) $user->getID()];
            }
        }
        $inPerimeter = array_values(array_filter(
            array_unique($candidates),
            static fn (int $id): bool => EntityPerimeter::userReachesEntity($id, $entityId, $recursive)
        ));

        return count($inPerimeter) === 1 ? $inPerimeter[0] : 0;
    }

    /**
     * Remplace les résultats de la campagne par ceux du fichier. Une personne présente sur plusieurs
     * lignes est comptée une fois (étapes cumulées). L'adresse n'est gardée que pour une ligne non
     * rapprochée, à corriger ; une ligne rapprochée ne garde que l'utilisateur.
     *
     * @param list<list<string>> $rows
     * @param array<string, int> $mapping
     * @return array{total: int, matched: int, unmatched: int, ignored: int}
     */
    public static function import(PhishingCampaign $campaign, array $rows, array $mapping, string $fileName): array
    {
        global $DB;

        $campaignId = $campaign->getID();
        $entityId = (int) $campaign->fields['entities_id'];
        $recursive = !empty($campaign->fields['is_recursive']);
        $byUser = [];
        $unmatched = [];
        $ignored = 0;
        foreach ($rows as $index => $row) {
            $result = Rules::readRow($row, $mapping);
            if ($result['identifier'] === '') {
                $ignored++;
                continue;
            }
            $usersId = self::matchUser($result['identifier'], $entityId, $recursive);
            $result['line'] = $index + 2;
            if ($usersId === 0) {
                $unmatched[] = $result;
                continue;
            }
            if (isset($byUser[$usersId])) {
                foreach (Rules::STEPS as $step) {
                    $byUser[$usersId][$step] = $byUser[$usersId][$step] || $result[$step];
                }
                continue;
            }
            $byUser[$usersId] = $result;
        }

        $now = date('Y-m-d H:i:s');
        $DB->delete(PhishingTables::RESULTS, [PhishingCampaign::FK => $campaignId]);
        $insert = static function (int $usersId, string $identifier, array $result) use ($DB, $campaignId, $now): void {
            $DB->insert(PhishingTables::RESULTS, [
                PhishingCampaign::FK => $campaignId,
                'users_id'           => $usersId,
                'identifier'         => $identifier,
                'line'               => (int) $result['line'],
                'is_sent'            => (int) $result['sent'],
                'is_opened'          => (int) $result['opened'],
                'is_clicked'         => (int) $result['clicked'],
                'is_submitted'       => (int) $result['submitted'],
                'is_reported'        => (int) $result['reported'],
                'date_creation'      => $now,
            ]);
        };
        foreach ($byUser as $usersId => $result) {
            $insert($usersId, '', $result);
        }
        foreach ($unmatched as $result) {
            $insert(0, Rules::safeIdentifier($result['identifier']), $result);
        }

        $counts = Rules::counts(array_merge(array_values($byUser), $unmatched));
        $DB->update(PhishingCampaign::getTable(), [
            'nb_sent'         => $counts['sent'],
            'nb_opened'       => $counts['opened'],
            'nb_clicked'      => $counts['clicked'],
            'nb_submitted'    => $counts['submitted'],
            'nb_reported'     => $counts['reported'],
            'nb_unmatched'    => count($unmatched),
            'import_file'     => mb_substr(basename($fileName), 0, 255),
            'date_import'     => $now,
            'date_anonymized' => null,
            'date_mod'        => $now,
        ], ['id' => $campaignId]);
        \Log::history($campaignId, PhishingCampaign::class, [0, '', sprintf(
            __('Results imported: %1$d people matched, %2$d lines not matched', 'grcmanager'),
            count($byUser),
            count($unmatched)
        )], '', \Log::HISTORY_LOG_SIMPLE_MESSAGE);

        return [
            'total'     => count($rows),
            'matched'   => count($byUser),
            'unmatched' => count($unmatched),
            'ignored'   => $ignored,
        ];
    }

    /**
     * Supprime les résultats individuels ; les compteurs (et donc les taux) de la campagne restent.
     */
    public static function anonymize(PhishingCampaign $campaign): void
    {
        global $DB;

        $now = date('Y-m-d H:i:s');
        $DB->delete(PhishingTables::RESULTS, [PhishingCampaign::FK => $campaign->getID()]);
        $DB->update(PhishingCampaign::getTable(), [
            'date_anonymized' => $now, 'nb_unmatched' => 0, 'date_mod' => $now,
        ], ['id' => $campaign->getID()]);
        \Log::history($campaign->getID(), PhishingCampaign::class, [0, '', __(
            'Individual results deleted, rates kept',
            'grcmanager'
        )], '', \Log::HISTORY_LOG_SIMPLE_MESSAGE);
    }

    /**
     * Résultats rapprochés, par personne (à n'afficher qu'avec canSeeDetail()).
     *
     * @return list<array<string, mixed>>
     */
    public static function detailRows(int $campaignId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => PhishingTables::RESULTS,
                'WHERE' => [PhishingCampaign::FK => $campaignId, 'users_id' => ['>', 0]],
            ]) as $row
        ) {
            $rows[] = ['name' => getUserName((int) $row['users_id']), 'users_id' => (int) $row['users_id']]
                + self::steps($row);
        }
        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return $rows;
    }

    /**
     * Lignes du fichier sans utilisateur GLPI (adresse inconnue, ambiguë ou hors de l'entité).
     *
     * @return list<array{line: int, identifier: string}>
     */
    public static function unmatchedRows(int $campaignId): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => PhishingTables::RESULTS,
                'WHERE' => [PhishingCampaign::FK => $campaignId, 'users_id' => 0],
                'ORDER' => 'line',
            ]) as $row
        ) {
            $rows[] = ['line' => (int) $row['line'], 'identifier' => (string) $row['identifier']];
        }

        return $rows;
    }

    /**
     * Personnes qui ont cliqué ou saisi des données : proposées pour une formation de rattrapage.
     *
     * @return list<int>
     */
    public static function remedialUserIds(int $campaignId): array
    {
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => PhishingTables::RESULTS,
                'WHERE'  => [
                    PhishingCampaign::FK => $campaignId,
                    'users_id'           => ['>', 0],
                    'OR'                 => ['is_clicked' => 1, 'is_submitted' => 1],
                ],
            ]) as $row
        ) {
            $ids[] = (int) $row['users_id'];
        }

        return $ids;
    }

    /**
     * Résultats de la personne connectée, toutes entités (ce sont ses propres données).
     *
     * @return list<array<string, mixed>>
     */
    public static function myResults(int $usersId): array
    {
        global $DB;

        if ($usersId <= 0 || !$DB->tableExists(PhishingTables::RESULTS)) {
            return [];
        }
        $rows = [];
        foreach (
            $DB->request([
                'SELECT'     => ['r.*', 'c.name', 'c.campaign_date', 'c.scenario'],
                'FROM'       => PhishingTables::RESULTS . ' AS r',
                'INNER JOIN' => [
                    PhishingTables::CAMPAIGNS . ' AS c' => [
                        'FKEY' => ['r' => PhishingCampaign::FK, 'c' => 'id'],
                    ],
                ],
                'WHERE'      => ['r.users_id' => $usersId],
                'ORDER'      => ['c.campaign_date DESC', 'c.id DESC'],
            ]) as $row
        ) {
            $rows[] = [
                'name'     => (string) $row['name'],
                'date'     => $row['campaign_date'] !== null ? Html::convDate((string) $row['campaign_date']) : '',
                'scenario' => (string) ($row['scenario'] ?? ''),
            ] + self::steps($row);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{sent: bool, opened: bool, clicked: bool, submitted: bool, reported: bool}
     */
    private static function steps(array $row): array
    {
        $steps = [];
        foreach (Rules::STEPS as $step) {
            $steps[$step] = !empty($row['is_' . $step]);
        }

        return $steps;
    }
}

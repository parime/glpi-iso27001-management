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

namespace GlpiPlugin\Grcmanager;

use CommonDBTM;
use GlpiPlugin\Grcmanager\Install\EvidenceRecurrenceTables;
use GlpiPlugin\Grcmanager\Services\Evidence\EvidenceRecurrenceRules as Rules;
use Holiday;
use NotificationEvent;

/**
 * Issue #886 : rythme de renouvellement d'une preuve (toutes les N semaines, dernier jour ouvré du
 * mois...), en plus de sa date de validité fixe (issue #145). Une ligne par validité de preuve
 * (Evidence), saisie depuis le même onglet « Validités des preuves » et soumise aux mêmes droits.
 *
 * La dernière collecte est la date saisie, sinon la dernière modification du document (un nouveau
 * fichier déposé dans le document vaut collecte). Une preuve non renouvelée à temps est signalée
 * (onglet, tableau de bord, contrôles de cohérence, dossier de preuves) et son responsable est
 * prévenu une fois par échéance par la notification « Preuve à renouveler » ; aucune preuve, aucun
 * statut ne change automatiquement.
 */
class EvidenceRecurrence extends CommonDBTM
{
    /** @var list<array{begin: string, end: string, perpetual: bool}>|null */
    private static ?array $holidays = null;

    public static function getTable($classname = null)
    {
        return EvidenceRecurrenceTables::RECURRENCES;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Evidence rhythm', 'Evidence rhythms', $nb, 'grcmanager');
    }

    /**
     * @return array<string, string> unité => libellé
     */
    public static function unitOptions(): array
    {
        return [
            Rules::UNIT_NONE        => __('No recurring renewal', 'grcmanager'),
            Rules::UNIT_DAY         => __('days', 'grcmanager'),
            Rules::UNIT_WEEK        => __('weeks', 'grcmanager'),
            Rules::UNIT_MONTH       => __('months', 'grcmanager'),
            Rules::UNIT_YEAR        => __('years', 'grcmanager'),
            Rules::UNIT_MONTH_END   => __('months, last business day', 'grcmanager'),
            Rules::UNIT_QUARTER_END => __('quarters, last business day', 'grcmanager'),
        ];
    }

    /**
     * @return array{0: string, 1: string} classe du badge, libellé
     */
    public static function statusInfo(string $status): array
    {
        return match ($status) {
            Rules::STATUS_STALE    => ['bg-danger-subtle text-danger-emphasis', __('Not renewed in time', 'grcmanager')],
            Rules::STATUS_DUE_SOON => ['bg-warning-subtle text-warning-emphasis', __('Renewal due soon', 'grcmanager')],
            Rules::STATUS_FRESH    => ['bg-success-subtle text-success-emphasis', __('Up to date', 'grcmanager')],
            default                => ['bg-secondary-subtle text-secondary-emphasis', ''],
        };
    }

    /**
     * Périodes de fermeture déclarées dans GLPI (intitulés des calendriers), lues une fois.
     *
     * @return list<array{begin: string, end: string, perpetual: bool}>
     */
    public static function holidays(): array
    {
        global $DB;

        if (self::$holidays === null) {
            self::$holidays = [];
            foreach ($DB->request(['FROM' => Holiday::getTable()]) as $row) {
                self::$holidays[] = [
                    'begin'     => (string) ($row['begin_date'] ?? ''),
                    'end'       => (string) ($row['end_date'] ?? ''),
                    'perpetual' => (bool) ($row['is_perpetual'] ?? false),
                ];
            }
        }

        return self::$holidays;
    }

    /**
     * Rythmes des validités données, par identifiant de validité.
     *
     * @param list<int> $evidenceIds
     * @return array<int, array<string, mixed>>
     */
    public static function forEvidences(array $evidenceIds): array
    {
        global $DB;

        $evidenceIds = array_values(array_filter($evidenceIds, static fn (int $id): bool => $id > 0));
        if ($evidenceIds === []) {
            return [];
        }
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['plugin_grcmanager_evidences_id' => $evidenceIds],
            ]) as $row
        ) {
            $rows[(int) $row['plugin_grcmanager_evidences_id']] = $row;
        }

        return $rows;
    }

    /**
     * État de fraîcheur d'un rythme : unité, nombre, collecte retenue, prochaine échéance, statut.
     *
     * @param array<string, mixed>|null $row ligne du rythme
     * @return array{unit: string, count: int, last_collected: string, reference: string,
     *     next_due: ?string, status: string}
     */
    public static function describe(?array $row, ?string $documentDate, string $today): array
    {
        $unit = (string) ($row['period_unit'] ?? '');
        $count = Rules::normalizeCount((int) ($row['period_count'] ?? 1));
        $lastCollected = (string) ($row['last_collected'] ?? '');
        $reference = Rules::isDate($lastCollected) ? $lastCollected : substr((string) $documentDate, 0, 10);
        $reference = Rules::isDate($reference) ? $reference : '';
        $nextDue = Rules::nextDue($reference !== '' ? $reference : null, $unit, $count, $today, self::holidays());

        return [
            'unit'           => Rules::isUnit($unit) ? $unit : '',
            'count'          => $count,
            'last_collected' => Rules::isDate($lastCollected) ? $lastCollected : '',
            'reference'      => $reference,
            'next_due'       => $nextDue,
            'status'         => Rules::status($nextDue, $unit, $count, $today),
        ];
    }

    /**
     * Enregistre (ou retire, sans unité) le rythme d'une validité depuis l'onglet de l'élément.
     *
     * @param array<string, mixed> $input period_unit, period_count, last_collected
     */
    public static function saveFor(int $evidencesId, array $input): void
    {
        if ($evidencesId <= 0) {
            return;
        }
        $unit = (string) ($input['period_unit'] ?? '');
        $existing = new self();
        $exists = $existing->getFromDBByCrit(['plugin_grcmanager_evidences_id' => $evidencesId]);
        if (!Rules::isUnit($unit)) {
            if ($exists) {
                $existing->delete(['id' => $existing->getID()], true);
            }
            return;
        }
        $lastCollected = trim((string) ($input['last_collected'] ?? ''));
        $values = [
            'period_unit'    => $unit,
            'period_count'   => Rules::normalizeCount((int) ($input['period_count'] ?? 1)),
            'last_collected' => Rules::isDate($lastCollected) ? $lastCollected : 'NULL',
        ];
        if ($exists) {
            $existing->update($values + ['id' => $existing->getID()]);
        } else {
            (new self())->add($values + ['plugin_grcmanager_evidences_id' => $evidencesId]);
        }
    }

    public static function purgeFor(int $evidencesId): void
    {
        (new self())->deleteByCriteria(['plugin_grcmanager_evidences_id' => $evidencesId], true);
    }

    /**
     * Rythmes des documents de preuve encore actifs, avec l'élément porteur et la date du document.
     *
     * @param bool $restrict limiter aux entités de la session (pas pour la tâche planifiée)
     * @return iterable<array<string, mixed>>
     */
    private static function activeRows(bool $restrict): iterable
    {
        global $DB;

        $r = self::getTable();
        $e = Evidence::getTable();
        $where = ['glpi_documents.is_deleted' => 0, ['NOT' => ["$r.period_unit" => '']]];
        if ($restrict) {
            $where += getEntitiesRestrictCriteria($e, '', '', true);
        }

        return $DB->request([
            'SELECT'     => [
                "$r.*",
                "$e.itemtype",
                "$e.items_id",
                "$e.documents_id",
                'glpi_documents.date_mod AS document_date',
            ],
            'FROM'       => $r,
            'INNER JOIN' => [
                $e               => ['FKEY' => [$r => 'plugin_grcmanager_evidences_id', $e => 'id']],
                'glpi_documents' => ['FKEY' => [$e => 'documents_id', 'glpi_documents' => 'id']],
            ],
            'WHERE'      => $where,
        ]);
    }

    /**
     * Preuves récurrentes non renouvelées à temps que la personne peut lire (contrôles de
     * cohérence, tableau de bord).
     *
     * @return array{total: int, stale: list<array{itemtype: string, items_id: int, documents_id: int,
     *     next_due: string}>}
     */
    public static function staleForSession(string $today): array
    {
        $result = ['total' => 0, 'stale' => []];
        if (!Evidence::canView()) {
            return $result;
        }
        $evidence = new Evidence();
        foreach (self::activeRows(true) as $row) {
            $itemtype = (string) $row['itemtype'];
            $itemsId = (int) $row['items_id'];
            $documentsId = (int) $row['documents_id'];
            if (!Evidence::isDocumentLinked($itemtype, $itemsId, $documentsId)) {
                continue;
            }
            $result['total']++;
            $state = self::describe($row, $row['document_date'] ?? null, $today);
            if (
                $state['status'] !== Rules::STATUS_STALE
                || !$evidence->can((int) $row['plugin_grcmanager_evidences_id'], READ)
            ) {
                continue;
            }
            $result['stale'][] = [
                'itemtype'     => $itemtype,
                'items_id'     => $itemsId,
                'documents_id' => $documentsId,
                'next_due'     => (string) $state['next_due'],
            ];
        }

        return $result;
    }

    /**
     * Carte du tableau de bord (EvidenceRecurrenceFeature) : nombre de preuves récurrentes non
     * renouvelées à temps lisibles par la personne, vers les contrôles de cohérence qui les listent.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function staleCard(array $params = []): array
    {
        return [
            'number' => count(self::staleForSession(date('Y-m-d'))['stale']),
            'url'    => ConsistencyCheck::getSearchURL(),
            'label'  => $params['label'] ?? __('Recurring evidence not renewed in time', 'grcmanager'),
            'icon'   => 'ti ti-repeat',
        ];
    }

    /**
     * Rappel au responsable du renouvellement, une fois par échéance, par la notification
     * existante des preuves (Evidence::EXPIRING_EVENT). Aucun ticket, aucune preuve modifiée.
     */
    public static function runReminders(string $today): int
    {
        $sent = 0;
        foreach (self::activeRows(false) as $row) {
            $state = self::describe($row, $row['document_date'] ?? null, $today);
            if (!Rules::reminderDue($state['status'], $state['next_due'], $row['notified_due'] ?? null)) {
                continue;
            }
            $evidence = new Evidence();
            if (
                !$evidence->getFromDB((int) $row['plugin_grcmanager_evidences_id'])
                || $evidence->getParent() === null
                || !Evidence::isDocumentLinked(
                    (string) $row['itemtype'],
                    (int) $row['items_id'],
                    (int) $row['documents_id']
                )
            ) {
                continue;
            }
            NotificationEvent::raiseEvent(Evidence::EXPIRING_EVENT, $evidence, ['next_due' => $state['next_due']]);
            (new self())->update(['id' => (int) $row['id'], 'notified_due' => $state['next_due']]);
            $sent++;
        }

        return $sent;
    }
}

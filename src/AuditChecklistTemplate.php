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

use GlpiPlugin\Grcmanager\Services\Audit\ChecklistRules as Rules;
use Session;

/**
 * Issue #606 : modèles de grille d'audit, réutilisables d'un audit à l'autre (ex. « Audit annuel du
 * contrôle d'accès »). Un modèle garde les exigences et les questions d'une grille, jamais les
 * réponses (audité, preuves, résultat, commentaire, constat).
 *
 * Il appartient à l'entité de l'audit d'où il a été enregistré et sert aussi à ses sous-entités. Une
 * mesure y est retenue par son numéro : appliqué à l'audit d'une entité dotée de sa propre SoA, le
 * modèle reprend la mesure de cette SoA, si elle y est applicable.
 *
 * Droits : enregistrer ou appliquer un modèle, ceux de modifier la grille de l'audit ; supprimer un
 * modèle, le droit de modifier les audits dans l'entité du modèle.
 */
final class AuditChecklistTemplate
{
    public const TABLE = 'glpi_plugin_grcmanager_auditchecklisttemplates';
    public const ITEMS_TABLE = 'glpi_plugin_grcmanager_auditchecklisttemplateitems';

    /**
     * Modèles des entités de la session, avec leur nombre de lignes.
     *
     * @return array<int, string> id => « nom (n lignes) »
     */
    public static function available(): array
    {
        global $DB;

        $counts = [];
        foreach (
            $DB->request([
                'SELECT'  => ['plugin_grcmanager_auditchecklisttemplates_id AS tid', 'COUNT' => 'id AS nb'],
                'FROM'    => self::ITEMS_TABLE,
                'GROUPBY' => ['plugin_grcmanager_auditchecklisttemplates_id'],
            ]) as $row
        ) {
            $counts[(int) $row['tid']] = (int) $row['nb'];
        }
        $templates = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => self::TABLE,
                'WHERE'  => EntityPerimeter::criteria(self::TABLE),
                'ORDER'  => ['name', 'id'],
            ]) as $row
        ) {
            $templates[(int) $row['id']] = sprintf(
                _n('%1$s (%2$d line)', '%1$s (%2$d lines)', $counts[(int) $row['id']] ?? 0, 'grcmanager'),
                (string) $row['name'],
                $counts[(int) $row['id']] ?? 0
            );
        }

        return $templates;
    }

    /**
     * @return array<string, mixed>|null le modèle, s'il est dans les entités de la session
     */
    public static function get(int $templateId): ?array
    {
        global $DB;

        if ($templateId <= 0) {
            return null;
        }
        $row = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => array_merge(['id' => $templateId], EntityPerimeter::criteria(self::TABLE)),
        ])->current();

        return $row === null ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function items(int $templateId): array
    {
        global $DB;

        return array_values(iterator_to_array($DB->request([
            'FROM'  => self::ITEMS_TABLE,
            'WHERE' => ['plugin_grcmanager_auditchecklisttemplates_id' => $templateId],
            'ORDER' => ['rank', 'id'],
        ]), false));
    }

    /**
     * Enregistre les exigences et questions de la grille de l'audit comme nouveau modèle.
     *
     * @return int identifiant du modèle, 0 en cas d'erreur (message affiché)
     */
    public static function saveFromAudit(Audit $audit, mixed $name): int
    {
        global $DB;

        $name = Rules::cleanText($name, Rules::MAX_LABEL_LENGTH);
        $lines = AuditChecklist::getLines($audit->getID());
        if ($name === '') {
            Session::addMessageAfterRedirect(__('Give the template a name.', 'grcmanager'), false, ERROR);

            return 0;
        }
        if ($lines === []) {
            Session::addMessageAfterRedirect(
                __('The checklist is empty: add requirements before saving it as a template.', 'grcmanager'),
                false,
                ERROR
            );

            return 0;
        }
        $entityId = (int) $audit->fields['entities_id'];
        if (countElementsInTable(self::TABLE, ['name' => $name, 'entities_id' => $entityId]) > 0) {
            Session::addMessageAfterRedirect(
                __('A checklist template with this name already exists.', 'grcmanager'),
                false,
                ERROR
            );

            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $DB->insert(self::TABLE, [
            'entities_id'   => $entityId,
            'is_recursive'  => 1,
            'name'          => $name,
            'users_id'      => (int) Session::getLoginUserID(),
            'date_creation' => $now,
            'date_mod'      => $now,
        ]);
        $templateId = (int) $DB->insertId();
        $rank = 0;
        foreach ($lines as $line) {
            $DB->insert(self::ITEMS_TABLE, [
                'plugin_grcmanager_auditchecklisttemplates_id' => $templateId,
                'ref_type'                                     => (string) $line['ref_type'],
                'items_id'                                     => (string) $line['ref_type'] === Rules::TYPE_CUSTOM
                    ? (int) $line['items_id'] : 0,
                'code'                                         => (string) $line['code'],
                'label'                                        => (string) $line['label'],
                'question'                                     => (string) $line['question'],
                'rank'                                         => ++$rank,
            ]);
        }

        return $templateId;
    }

    /**
     * Ajoute à la grille de l'audit les lignes du modèle qui n'y sont pas encore. Une exigence qui
     * n'est plus proposée (mesure non applicable dans la SoA de l'audit, référentiel désactivé) est
     * ignorée.
     *
     * @return array{added: int, skipped: int}
     */
    public static function applyToAudit(int $templateId, Audit $audit): array
    {
        $template = self::get($templateId);
        if ($template === null) {
            return ['added' => 0, 'skipped' => 0];
        }
        $keys = [];
        $questions = [];
        $free = [];
        foreach (self::items($templateId) as $item) {
            $type = (string) $item['ref_type'];
            if ($type === Rules::TYPE_FREE) {
                $free[] = $item;
                continue;
            }
            $key = Rules::refKey($type, (string) $item['code'], (int) $item['items_id']);
            if ($key !== null) {
                $keys[] = $key;
                $questions[$key] = (string) $item['question'];
            }
        }
        $added = AuditChecklist::addRequirements($audit, $keys, $questions);
        $existingFree = [];
        foreach (AuditChecklist::getLines($audit->getID()) as $line) {
            if ((string) $line['ref_type'] === Rules::TYPE_FREE) {
                $existingFree[$line['code'] . "\n" . $line['label']] = true;
            }
        }
        $skipped = count($keys) - $added;
        foreach ($free as $item) {
            if (isset($existingFree[$item['code'] . "\n" . $item['label']])) {
                $skipped++;
                continue;
            }
            if (AuditChecklist::addFreeLine($audit, $item['code'], $item['label'], $item['question'])) {
                $added++;
            }
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    public static function canDelete(int $templateId): bool
    {
        $template = self::get($templateId);

        return $template !== null
            && Session::haveRight(Audit::RIGHTNAME, UPDATE)
            && (!EntityPerimeter::hasSession() || Session::haveAccessToEntity((int) $template['entities_id']));
    }

    public static function delete(int $templateId): bool
    {
        global $DB;

        if (!self::canDelete($templateId)) {
            return false;
        }
        $DB->delete(self::ITEMS_TABLE, ['plugin_grcmanager_auditchecklisttemplates_id' => $templateId]);

        return (bool) $DB->delete(self::TABLE, ['id' => $templateId]);
    }
}

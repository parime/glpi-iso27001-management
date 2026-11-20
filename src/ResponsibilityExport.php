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

use Document;
use Document_Item;
use GlpiPlugin\Grcmanager\Compat\Base\ResponsibilityExportBase;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use Html;
use Session;

/**
 * Issue #612 : export PDF daté de la matrice des responsabilités, archivé comme document de preuve
 * de l'article 5.3 et de la mesure A.5.3.
 *
 * Chaque archivage est une ligne de cette table, dans l'entité active : elle est la source du
 * document (RestrictedDocument), qui ne se lit donc qu'avec le droit du plugin dans cette entité,
 * jamais avec le seul droit Documents ni depuis la SoA d'une autre entité.
 */
class ResponsibilityExport extends ResponsibilityExportBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_responsibilityexports';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Archived responsibility matrix', 'Archived responsibility matrices', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-certificate';
    }

    /**
     * Génère le PDF de l'entité active, l'archive en document et le joint à l'exigence 5.3 et à la
     * mesure A.5.3 de la SoA de l'entité. Renvoie l'identifiant du document, 0 en cas d'échec.
     */
    public static function archive(): int
    {
        global $DB;

        $entityId = (int) Session::getActiveEntity();
        try {
            $pdf = PdfRenderer::renderHtmlToPdf(ResponsibilityActivity::renderPdf());
        } catch (\Throwable $e) {
            return 0;
        }
        $export = new self();
        $exportId = (int) $export->add([
            'entities_id' => $entityId,
            'users_id'    => (int) Session::getLoginUserID(),
        ]);
        if ($exportId <= 0) {
            return 0;
        }
        $fileName = sprintf('matrice-responsabilites-%d-%s.pdf', $exportId, date('Ymd-His'));
        if (file_put_contents(GLPI_TMP_DIR . '/' . $fileName, $pdf) === false) {
            return 0;
        }

        // Document::add() empile des messages techniques sans intérêt pour l'utilisateur.
        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $documentsId = (int) (new Document())->add([
            'name'          => sprintf(
                __('ISMS responsibility matrix - %s', 'grcmanager'),
                Html::convDate(date('Y-m-d'))
            ),
            // Noms des personnes d'une seule entité : pas de récursivité.
            'entities_id'   => $entityId,
            'is_recursive'  => 0,
            '_filename'     => [$fileName],
            '_tag_filename' => [$fileName],
        ]);
        if ($documentsId > 0) {
            RestrictedDocument::register($documentsId, $export);
            $DB->update(self::getTable(), ['documents_id' => $documentsId], ['id' => $exportId]);
            foreach (self::evidenceTargets($entityId) as $itemtype => $itemsId) {
                (new Document_Item())->add([
                    'documents_id' => $documentsId,
                    'itemtype'     => $itemtype,
                    'items_id'     => $itemsId,
                ]);
            }
        }
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;

        return $documentsId;
    }

    /**
     * Exigence 5.3 du SMSI et mesure A.5.3 de la SoA qui s'applique à l'entité, quand elles existent.
     *
     * @return array<class-string, int>
     */
    public static function evidenceTargets(int $entityId): array
    {
        $targets = [];
        $requirement = new IsmsRequirement();
        if ($requirement->getFromDBByCrit(['code' => '5.3'])) {
            $targets[IsmsRequirement::class] = (int) $requirement->getID();
        }
        $controlId = EntityPerimeter::controlIdsByCode($entityId)['A.5.3'] ?? 0;
        if ($controlId > 0) {
            $targets[Control::class] = (int) $controlId;
        }

        return $targets;
    }

    /**
     * Derniers archivages des entités de la session.
     *
     * @return list<array{date: string, user: string, url: string}>
     */
    public static function recent(int $limit = 10): array
    {
        global $DB, $CFG_GLPI;

        if (!$DB->tableExists(self::getTable())) {
            return [];
        }
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['documents_id' => ['>', 0]] + EntityPerimeter::criteria(self::getTable(), false),
                'ORDER' => 'id DESC',
                'LIMIT' => $limit,
            ]) as $row
        ) {
            $rows[] = [
                'date' => (string) Html::convDateTime($row['date_creation']),
                'user' => (string) getUserName((int) $row['users_id']),
                'url'  => $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . (int) $row['documents_id'],
            ];
        }

        return $rows;
    }
}

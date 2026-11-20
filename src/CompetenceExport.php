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

use Document;
use Document_Item;
use GlpiPlugin\Grcmanager\Compat\Base\CompetenceExportBase;
use GlpiPlugin\Grcmanager\Install\CompetenceTables;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use Html;
use Session;

/**
 * Issue #774 : dossier des compétences en PDF (fiches de fonctions et écarts), daté et archivé comme
 * document de preuve de l'exigence 7.2 du SMSI.
 *
 * Comme les matrices des responsabilités archivées : chaque archivage est une ligne de cette table,
 * dans l'entité active, source du document (RestrictedDocument). Le document ne se lit qu'avec le
 * droit du plugin dans cette entité, jamais avec le seul droit Documents.
 */
class CompetenceExport extends CompetenceExportBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return CompetenceTables::EXPORTS;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Archived competence file', 'Archived competence files', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-certificate';
    }

    /** Le dossier cite des personnes : lecture des utilisateurs exigée, comme la matrice. */
    public static function canView(): bool
    {
        return CompetenceMatrix::canView();
    }

    public static function canCreate(): bool
    {
        return CompetenceMatrix::canView() && (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    /**
     * Génère le dossier de l'entité active, l'archive et le joint à l'exigence 7.2. Renvoie
     * l'identifiant du document, 0 en cas d'échec.
     */
    public static function archive(): int
    {
        global $DB;

        $entityId = (int) Session::getActiveEntity();
        try {
            $pdf = PdfRenderer::renderHtmlToPdf(
                CompetenceMatrix::renderPdf(CompetenceMatrix::visibleFunctionItems(), true)
            );
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
        $fileName = sprintf('competences-%d-%s.pdf', $exportId, date('Ymd-His'));
        if (file_put_contents(GLPI_TMP_DIR . '/' . $fileName, $pdf) === false) {
            return 0;
        }

        // Document::add() empile des messages techniques sans intérêt pour l'utilisateur.
        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $documentsId = (int) (new Document())->add([
            'name'          => sprintf(__('ISMS competences - %s', 'grcmanager'), Html::convDate(date('Y-m-d'))),
            // Noms des personnes d'une seule entité : pas de récursivité.
            'entities_id'   => $entityId,
            'is_recursive'  => 0,
            '_filename'     => [$fileName],
            '_tag_filename' => [$fileName],
        ]);
        if ($documentsId > 0) {
            RestrictedDocument::register($documentsId, $export);
            $DB->update(self::getTable(), ['documents_id' => $documentsId], ['id' => $exportId]);
            $requirement = new IsmsRequirement();
            if ($requirement->getFromDBByCrit(['code' => '7.2'])) {
                (new Document_Item())->add([
                    'documents_id' => $documentsId,
                    'itemtype'     => IsmsRequirement::class,
                    'items_id'     => (int) $requirement->getID(),
                ]);
            }
        }
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;

        return $documentsId;
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

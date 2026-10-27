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

use Glpi\Application\View\TemplateRenderer;

/**
 * Issue #140 : historique des téléchargements des documents restreints (registre
 * PluginGrcmanagerRestrictedDocument), preuve d'un accès maîtrisé aux documents d'audit.
 *
 * Le téléchargement passe par front/document.send.php du cœur, après le contrôle du plugin
 * (Hooks::ITEM_CAN). Ce contrôle a lieu avant les autres vérifications de GLPI : l'accès n'est
 * donc enregistré qu'en fin de requête, et seulement si le fichier est réellement parti
 * (en-tête Content-Disposition envoyé).
 */
class PluginGrcmanagerDocumentAccess extends CommonGLPI
{
    public const TABLE = 'glpi_plugin_grcmanager_documentaccesses';

    /** @var array<int, true> */
    private static array $pending = [];

    public static function log(int $documentsId, string $itemtype, int $itemsId, int $usersId): void
    {
        global $DB;

        if ($documentsId <= 0 || $usersId <= 0) {
            return;
        }
        $DB->insert(self::TABLE, [
            'documents_id' => $documentsId,
            'itemtype'     => $itemtype,
            'items_id'     => $itemsId,
            'users_id'     => $usersId,
            'date_access'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * La requête en cours télécharge-t-elle ce document (front/document.send.php?docid=…) ?
     */
    public static function isDownloadOf(int $documentsId): bool
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        return str_ends_with($path, '/front/document.send.php') && (int) ($_GET['docid'] ?? 0) === $documentsId;
    }

    /**
     * Enregistre le téléchargement en fin de requête, si le fichier a bien été envoyé.
     */
    public static function logWhenSent(int $documentsId, string $itemtype, int $itemsId): void
    {
        if (isset(self::$pending[$documentsId])) {
            return;
        }
        self::$pending[$documentsId] = true;
        $usersId = (int) Session::getLoginUserID();
        register_shutdown_function(static function () use ($documentsId, $itemtype, $itemsId, $usersId): void {
            foreach (headers_list() as $header) {
                if (stripos($header, 'Content-Disposition:') === 0) {
                    self::log($documentsId, $itemtype, $itemsId, $usersId);

                    return;
                }
            }
        });
    }

    /**
     * @return list<array{date: string, user: string, document: string, documents_id: int}>
     */
    public static function getAccesses(string $itemtype, int $itemsId, int $limit = 200): array
    {
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'SELECT'    => [
                    self::TABLE . '.documents_id',
                    self::TABLE . '.users_id',
                    self::TABLE . '.date_access',
                    'glpi_documents.name',
                ],
                'FROM'      => self::TABLE,
                'LEFT JOIN' => [
                    'glpi_documents' => ['FKEY' => [self::TABLE => 'documents_id', 'glpi_documents' => 'id']],
                ],
                'WHERE'     => [self::TABLE . '.itemtype' => $itemtype, self::TABLE . '.items_id' => $itemsId],
                'ORDER'     => [self::TABLE . '.date_access DESC', self::TABLE . '.id DESC'],
                'LIMIT'     => $limit,
            ]) as $row
        ) {
            $rows[] = [
                'date'         => (string) Html::convDateTime($row['date_access']),
                'user'         => getUserName((int) $row['users_id']),
                'document'     => (string) ($row['name'] ?? ''),
                'documents_id' => (int) $row['documents_id'],
            ];
        }

        return $rows;
    }

    public static function purgeItem(string $itemtype, int $itemsId): void
    {
        global $DB;

        $DB->delete(self::TABLE, ['itemtype' => $itemtype, 'items_id' => $itemsId]);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            !$item instanceof PluginGrcmanagerAudit || $item->isNewItem() || !PluginGrcmanagerAudit::canView()
            || !PluginGrcmanagerAuditTeam::canAccessDocuments($item->getID())
        ) {
            return '';
        }

        return self::createTabEntry(__('Accès aux documents', 'grcmanager'), 0, null, 'ti ti-history');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (
            !$item instanceof PluginGrcmanagerAudit || !$item->can($item->getID(), READ)
            || !PluginGrcmanagerAuditTeam::canAccessDocuments($item->getID())
        ) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/document_accesses_tab.html.twig', [
            'accesses' => self::getAccesses(PluginGrcmanagerAudit::class, $item->getID()),
        ]);

        return true;
    }
}

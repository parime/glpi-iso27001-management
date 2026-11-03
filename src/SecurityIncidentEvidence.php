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

use CommonGLPI;
use Document;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Incident\EvidenceSeal;
use PluginGrcmanagerSecurityIncident;
use Session;

/**
 * Issue #371 : registre des preuves d'un incident. Chaque document joint y est inscrit avec son
 * empreinte SHA-256 de référence, sa taille, son auteur et sa date ; un document retiré ou dont le
 * fichier est remplacé reste au registre avec son empreinte d'origine. Le registre ne se modifie
 * pas à la main : seules l'inscription automatique et la vérification l'alimentent.
 */
class SecurityIncidentEvidence extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager_securityincident';

    public static function getTable(): string
    {
        return 'glpi_plugin_grcmanager_securityincidentevidences';
    }

    public static function getTypeName($nb = 0)
    {
        return __('Integrity and evidence', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-fingerprint-scan';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/securityincidentevidence.form.php';
    }

    private static function pathOf(Document $document): string
    {
        return GLPI_DOC_DIR . '/' . (string) ($document->fields['filepath'] ?? '');
    }

    /**
     * Inscrit un document joint à un incident (hook ITEM_ADD de Document_Item).
     */
    public static function onDocumentLinked(Document_Item $link): void
    {
        if (($link->fields['itemtype'] ?? '') !== PluginGrcmanagerSecurityIncident::class) {
            return;
        }
        $document = new Document();
        if ($document->getFromDB((int) $link->fields['documents_id'])) {
            self::register((int) $link->fields['items_id'], $document);
        }
    }

    public static function register(int $incidentId, Document $document): bool
    {
        global $DB;

        $seal = EvidenceSeal::seal(self::pathOf($document));
        if ($incidentId <= 0 || $seal === null) {
            return false;
        }
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $inserted = (bool) $DB->insert(self::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'documents_id'  => (int) $document->getID(),
            'file_name'     => mb_substr((string) $document->fields['filename'], 0, 255),
            'sha256'        => $seal['sha256'],
            'size'          => $seal['size'],
            'status'        => 'present',
            'users_id'      => (int) Session::getLoginUserID(),
            'date_creation' => $now,
        ]);
        AuditLog::record('seal', Document::class, (int) $document->getID(), $incidentId, 'success', [
            'file' => (string) $document->fields['filename'],
            'sha256' => $seal['sha256'],
            'size' => $seal['size'],
        ]);

        return $inserted;
    }

    /**
     * Document retiré de l'incident : il reste au registre, marqué retiré.
     */
    public static function onDocumentUnlinked(Document_Item $link): void
    {
        global $DB;

        if (($link->fields['itemtype'] ?? '') !== PluginGrcmanagerSecurityIncident::class) {
            return;
        }
        $DB->update(self::getTable(), [
            'status'       => 'removed',
            'date_removed' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ], [
            'plugin_grcmanager_securityincidents_id' => (int) $link->fields['items_id'],
            'documents_id' => (int) $link->fields['documents_id'],
            'status' => 'present',
        ]);
    }

    /**
     * Fichier d'un document remplacé (hook ITEM_UPDATE de Document) : l'ancienne inscription est
     * gardée, marquée remplacée, et le nouveau fichier est inscrit à son tour.
     */
    public static function onDocumentUpdated(Document $document): void
    {
        global $DB;

        if (array_intersect(['filepath', 'sha1sum'], $document->updates) === []) {
            return;
        }
        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['documents_id' => (int) $document->getID(), 'status' => 'present'],
        ]);
        foreach ($rows as $row) {
            $DB->update(self::getTable(), [
                'status'       => 'replaced',
                'date_removed' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ], ['id' => (int) $row['id']]);
            self::register((int) $row['plugin_grcmanager_securityincidents_id'], $document);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getForIncident(int $incidentId): array
    {
        global $DB;

        return iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_grcmanager_securityincidents_id' => $incidentId],
            'ORDER' => 'id ASC',
        ]), false);
    }

    /**
     * Vérifie les preuves présentes d'un incident ; chaque vérification est tracée.
     *
     * @return array<int, string> Identifiant de l'inscription => ok, modified ou missing.
     */
    public static function verifyIncident(int $incidentId): array
    {
        global $DB;

        $results = [];
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        foreach (self::getForIncident($incidentId) as $row) {
            if ($row['status'] !== 'present') {
                continue;
            }
            $document = new Document();
            $status = $document->getFromDB((int) $row['documents_id'])
                ? EvidenceSeal::check(self::pathOf($document), (string) $row['sha256'])
                : EvidenceSeal::MISSING;
            $results[(int) $row['id']] = $status;
            $DB->update(
                self::getTable(),
                ['last_check' => $now, 'last_result' => $status],
                ['id' => (int) $row['id']]
            );
        }
        $problems = count(array_filter($results, static fn (string $status): bool => $status !== EvidenceSeal::OK));
        AuditLog::record('verify', self::class, 0, $incidentId, 'success', [
            'evidences' => count($results),
            'problems'  => $problems,
        ]);

        return $results;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof PluginGrcmanagerSecurityIncident) || !PluginGrcmanagerSecurityIncident::canView()) {
            return '';
        }
        $count = $item->isNewID($item->getID()) ? 0 : countElementsInTable(self::getTable(), [
            'plugin_grcmanager_securityincidents_id' => $item->getID(),
        ]);

        return self::createTabEntry(self::getTypeName(), $count, $item::class, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof PluginGrcmanagerSecurityIncident)) {
            return false;
        }
        $rows = self::getForIncident((int) $item->getID());
        $users = [];
        foreach ($rows as $row) {
            $users[(int) $row['users_id']] ??= getUserName((int) $row['users_id']);
        }

        TemplateRenderer::getInstance()->display('@grcmanager/tabs/incident_evidence.html.twig', [
            'item'     => $item,
            'rows'     => $rows,
            'users'    => $users,
            'can_edit' => $item->canUpdateItem(),
            'form_url' => self::getFormURL(),
        ]);

        return true;
    }
}

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

use Glpi\DBAL\QuerySubQuery;

/**
 * Documents générés par le plugin (rapports PDF d'audit, de revue des accès, post-incident, de
 * cellule de crise) dont l'audience est celle de l'objet qui les a produits, jamais plus large.
 *
 * Revue de sécurité de la marketplace (2.11.0) : GLPI sert un document à quiconque a le droit
 * Documents dans son entité (récursivité comprise) ou peut lire un élément auquel il est lié.
 * Un rapport d'incident ou d'audit devenait ainsi lisible par un technicien sans aucun droit sur
 * l'incident ou l'audit. Ici :
 * - Hooks::ITEM_CAN refuse tout droit sur le document à qui ne peut pas lire l'objet source
 *   (Document::canViewFile() passe par Document::can()) ;
 * - Hooks::ADD_DEFAULT_WHERE retire ces documents de la liste Gestion > Documents : ils restent
 *   accessibles depuis l'onglet Documents de leur objet, avec les droits de cet objet.
 */
class PluginGrcmanagerRestrictedDocument
{
    public const TABLE = 'glpi_plugin_grcmanager_restricteddocuments';

    /** @var array<int, array{itemtype: string, items_id: int}|null> */
    private static array $cache = [];

    public static function register(int $documentsId, CommonDBTM $source): void
    {
        global $DB;

        if ($documentsId <= 0 || $source->isNewItem()) {
            return;
        }
        $DB->updateOrInsert(
            self::TABLE,
            ['itemtype' => $source->getType(), 'items_id' => $source->getID()],
            ['documents_id' => $documentsId]
        );
        unset(self::$cache[$documentsId]);
    }

    /**
     * @return array{itemtype: string, items_id: int}|null
     */
    public static function sourceOf(int $documentsId): ?array
    {
        global $DB;

        if (!array_key_exists($documentsId, self::$cache)) {
            $row = $DB->request([
                'SELECT' => ['itemtype', 'items_id'],
                'FROM'   => self::TABLE,
                'WHERE'  => ['documents_id' => $documentsId],
            ])->current();
            self::$cache[$documentsId] = $row === null
                ? null
                : ['itemtype' => (string) $row['itemtype'], 'items_id' => (int) $row['items_id']];
        }

        return self::$cache[$documentsId];
    }

    /**
     * L'utilisateur courant peut-il lire l'objet dont provient ce document ? Vrai pour un
     * document qui n'est pas dans le registre.
     */
    public static function canReadSource(int $documentsId): bool
    {
        $source = self::sourceOf($documentsId);
        if ($source === null) {
            return true;
        }
        $itemtype = $source['itemtype'];
        if (!is_a($itemtype, CommonDBTM::class, true)) {
            return false;
        }
        // Type lu dans la table du plugin, écrit par register() avec une classe du plugin.
        // nosemgrep: php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation
        $item = new $itemtype();

        return $item->can($source['items_id'], READ);
    }

    /**
     * Hooks::ITEM_CAN sur Document : aucun droit sur le document sans lecture de sa source.
     */
    public static function itemCan(CommonDBTM $document): void
    {
        if (!$document instanceof Document || $document->isNewItem()) {
            return;
        }
        if (!self::canReadSource($document->getID())) {
            $document->right = 0;
        }
    }

    /**
     * Hooks::ADD_DEFAULT_WHERE : retire les documents du registre de la recherche des documents.
     *
     * @param array{0: string, 1: array<mixed>} $params
     * @return array{0: string, 1: array<mixed>}
     */
    public static function addDefaultWhere(array $params): array
    {
        [$itemtype, $criteria] = $params;
        if ($itemtype === Document::class) {
            $criteria[] = ['NOT' => ['glpi_documents.id' => new QuerySubQuery([
                'SELECT' => 'documents_id',
                'FROM'   => self::TABLE,
            ])]];
        }

        return [$itemtype, $criteria];
    }

    public static function forget(CommonDBTM $document): void
    {
        global $DB;

        $DB->delete(self::TABLE, ['documents_id' => $document->getID()]);
        unset(self::$cache[$document->getID()]);
    }
}

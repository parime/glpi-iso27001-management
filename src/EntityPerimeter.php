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
use Dropdown;
use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\SoaApprovalLogic;
use GlpiPlugin\Grcmanager\Services\Maturity\MaturityScore;
use GlpiPlugin\Grcmanager\Services\Perimeter\PerimeterRules;
use Profile_User;
use Session;

/**
 * Issue #536 : périmètres multiples, un SMSI découpé selon les entités de GLPI.
 *
 * - Risques, mesures de la SoA et versions approuvées de la SoA portent une entité : les droits
 *   d'entité standards de GLPI s'appliquent (listes, fiches, recherches, API, notifications).
 * - Les écrans et exports qui lisent ces tables sans passer par la recherche de GLPI ajoutent
 *   criteria() : les entités actives de la session, sous-entités et objets récursifs compris.
 *   Hors session (tâches planifiées, console), aucune restriction : les destinataires des
 *   notifications sont déjà choisis par entité.
 * - Lot 2 : actions de traitement (entité de leur risque), audits et non-conformités. Les
 *   personnes désignées sur un audit (équipe, demandes de preuves, accès auditeur) doivent avoir un
 *   profil dans son entité (userReachesEntity()).
 * - La SoA commune (93 mesures) est dans l'entité racine, en récursif. Une entité peut tenir sa
 *   propre SoA (createSoa()) ; elle et ses sous-entités suivent alors celle-ci (soaEntity()).
 */
final class EntityPerimeter
{
    /** @var array<int, int>|null id de l'entité => id du parent */
    private static ?array $parents = null;

    /** @var list<int>|null */
    private static ?array $withOwnSoa = null;

    /**
     * Oublie les données mises en cache (entités créées, SoA créée).
     */
    public static function reset(): void
    {
        self::$parents = null;
        self::$withOwnSoa = null;
    }

    public static function hasSession(): bool
    {
        return isset($_SESSION['glpiactiveentities']);
    }

    /**
     * Critère d'entité standard de GLPI, sous forme de liste à fusionner dans un WHERE
     * (array_merge) ; liste vide hors session.
     *
     * @return list<array<mixed>>
     */
    public static function criteria(string $table, bool $recursive = true): array
    {
        if (!self::hasSession()) {
            return [];
        }

        return [getEntitiesRestrictCriteria($table, '', '', $recursive)];
    }

    /**
     * Une personne a-t-elle un profil dans l'entité de l'élément (ou au-dessous, s'il est visible
     * des sous-entités) ? Sert aux personnes désignées sur un audit : un auditeur limité à une
     * entité ne reçoit rien d'une autre.
     */
    public static function userReachesEntity(int $usersId, int $entityId, bool $recursive = false): bool
    {
        if ($usersId <= 0) {
            return false;
        }

        return PerimeterRules::reaches(
            array_map('intval', Profile_User::getUserEntities($usersId, true)),
            $entityId,
            $recursive,
            self::parents()
        );
    }

    /**
     * Même chose pour un élément rattaché à une entité (audit, demande de son audit...).
     */
    public static function userReachesItem(int $usersId, CommonDBTM $item): bool
    {
        return self::userReachesEntity(
            $usersId,
            (int) ($item->fields['entities_id'] ?? 0),
            (bool) ($item->fields['is_recursive'] ?? false)
        );
    }

    /**
     * Entité active de la session (racine hors session).
     */
    public static function activeEntity(): int
    {
        return self::hasSession() ? (int) Session::getActiveEntity() : 0;
    }

    /**
     * @return array<int, int>
     */
    public static function parents(): array
    {
        global $DB;

        if (self::$parents === null) {
            self::$parents = [];
            foreach ($DB->request(['SELECT' => ['id', 'entities_id'], 'FROM' => 'glpi_entities']) as $row) {
                self::$parents[(int) $row['id']] = (int) $row['entities_id'];
            }
        }

        return self::$parents;
    }

    /**
     * Entités qui tiennent leur propre SoA (la racine toujours).
     *
     * @return list<int>
     */
    public static function entitiesWithOwnSoa(): array
    {
        global $DB;

        if (self::$withOwnSoa === null) {
            $entities = [0];
            foreach (
                $DB->request([
                    'SELECT'   => ['entities_id'],
                    'DISTINCT' => true,
                    'FROM'     => Control::getTable(),
                ]) as $row
            ) {
                $entities[] = (int) $row['entities_id'];
            }
            self::$withOwnSoa = array_values(array_unique($entities));
        }

        return self::$withOwnSoa;
    }

    public static function hasOwnSoa(int $entityId): bool
    {
        return $entityId === 0 || in_array($entityId, self::entitiesWithOwnSoa(), true);
    }

    /**
     * Entité dont la SoA s'applique à $entityId (par défaut l'entité active).
     */
    public static function soaEntity(?int $entityId = null): int
    {
        return PerimeterRules::soaEntity(
            PerimeterRules::chain($entityId ?? self::activeEntity(), self::parents()),
            self::entitiesWithOwnSoa()
        );
    }

    /**
     * SoA suivies par les entités actives de la session : à la racine en vue arborescente, toutes ;
     * dans une entité, la sienne seulement.
     *
     * @return list<int>
     */
    public static function activeSoaEntities(): array
    {
        if (!self::hasSession()) {
            return self::entitiesWithOwnSoa();
        }
        $soa = [];
        foreach ((array) $_SESSION['glpiactiveentities'] as $entityId) {
            $soa[] = self::soaEntity((int) $entityId);
        }

        return array_values(array_unique($soa));
    }

    /**
     * Mesures de la SoA qui s'applique à l'entité (par défaut l'entité active).
     *
     * @return array<string, int>
     */
    public static function controlCriteria(?int $entityId = null, string $alias = ''): array
    {
        $field = ($alias !== '' ? $alias : Control::getTable()) . '.entities_id';

        return [$field => self::soaEntity($entityId)];
    }

    /**
     * Code de mesure (A.5.1...) => id, dans la SoA de l'entité (par défaut l'entité active).
     *
     * @return array<string, int>
     */
    public static function controlIdsByCode(?int $entityId = null): array
    {
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'code'],
                'FROM'   => Control::getTable(),
                'WHERE'  => self::controlCriteria($entityId),
            ]) as $row
        ) {
            $ids[(string) $row['code']] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * L'entité active peut-elle se doter de sa propre SoA ? Il faut le droit de créer des mesures,
     * l'accès à l'entité, et qu'elle n'en ait pas déjà une.
     */
    public static function canCreateSoa(int $entityId): bool
    {
        return $entityId > 0
            && !self::hasOwnSoa($entityId)
            && Session::haveRight(Control::$rightname, CREATE)
            && Session::haveAccessToEntity($entityId);
    }

    /**
     * Dote l'entité de sa propre SoA : les 93 mesures de l'Annexe A, avec l'applicabilité et la
     * justification de la SoA qu'elle suivait jusque-là comme point de départ. L'état de mise en
     * œuvre repart de « non démarré » et chaque mesure est à revoir : rien n'est réputé fait dans
     * l'entité. Les sous-entités suivent cette SoA (mesures récursives).
     *
     * @return int nombre de mesures créées
     */
    public static function createSoa(int $entityId): int
    {
        global $DB;

        if (!self::canCreateSoa($entityId)) {
            return 0;
        }

        $inherited = [];
        foreach (
            $DB->request([
                'FROM'  => Control::getTable(),
                'WHERE' => self::controlCriteria($entityId),
            ]) as $row
        ) {
            $inherited[(string) $row['code']] = $row;
        }

        $created = 0;
        $createdIds = [];
        foreach (ControlCatalogDefaults::CONTROLS as $code => $theme) {
            $source = $inherited[$code] ?? [];
            $input = [
                'entities_id'          => $entityId,
                'is_recursive'         => 1,
                'code'                 => $code,
                'theme'                => $theme,
                'applicability'        => (string) ($source['applicability'] ?? 'yes'),
                'justification'        => (string) ($source['justification'] ?? ''),
                'implementation_status' => 'not_started',
                '_grcmanager_new_soa'  => true,
            ];
            // Issue #609 : motifs de sélection et détail des exclusions repris aussi (des motifs
            // jamais renseignés le restent).
            if (($source['selection_reasons'] ?? null) !== null) {
                $input['selection_reasons'] = (string) $source['selection_reasons'];
            }
            foreach (['exclusion_category', 'compensating_measures', 'residual_risk'] as $field) {
                $input[$field] = (string) ($source[$field] ?? '');
            }
            $control = new Control();
            $id = $control->add($input);
            if ($id) {
                $created++;
                $createdIds[$code] = (int) $id;
            }
        }
        // Issue #609 : mesures compensatoires reliées aux mesures de la nouvelle SoA, par code.
        Control::copyCompensations(
            array_map(static fn (array $row): int => (int) $row['id'], $inherited),
            $createdIds
        );
        self::reset();

        return $created;
    }

    /**
     * Instance à plusieurs entités : sans cela, rien des périmètres ne s'affiche.
     */
    public static function isMultiEntity(): bool
    {
        return count(self::parents()) > 1;
    }

    /**
     * Encadré des écrans de la SoA : entité active, SoA qu'elle suit, création de la sienne.
     * Null sur une instance à une seule entité.
     *
     * @return array{entity: string, soa: string, own: bool, inherited: bool, can_create: bool,
     *     form_url: string, csrf_token: string}|null
     */
    public static function soaBanner(): ?array
    {
        if (!self::isMultiEntity()) {
            return null;
        }
        $active = self::activeEntity();
        $soa = self::soaEntity($active);
        $canCreate = self::canCreateSoa($active);

        return [
            'entity'     => Dropdown::getDropdownName('glpi_entities', $active),
            'soa'        => Dropdown::getDropdownName('glpi_entities', $soa),
            'own'        => $soa === $active,
            'inherited'  => $soa !== $active,
            'can_create' => $canCreate,
            'form_url'   => Control::getFormURL(),
            'csrf_token' => $canCreate ? Session::getNewCSRFToken() : '',
        ];
    }

    /**
     * Tableau consolidé (entité active et ses enfants directs, chacun avec ses sous-entités) :
     * SoA suivie, taux de mise en œuvre, dernière version approuvée, risques ouverts par niveau,
     * non-conformités ouvertes par gravité (null sans le droit de les lire).
     * Seules les entités visibles dans la session comptent.
     *
     * @return list<array<string, mixed>>
     */
    public static function consolidation(): array
    {
        global $DB;

        $parents = self::parents();
        $active  = self::activeEntity();
        $visible = array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? [$active]));

        $rows = [];
        $perimeters = [[$active, [$active], true]];
        foreach (PerimeterRules::children($active, $parents) as $child) {
            $subtree = array_values(array_intersect(PerimeterRules::subtree($child, $parents), $visible));
            if ($subtree !== []) {
                $perimeters[] = [$child, $subtree, false];
            }
        }

        foreach ($perimeters as [$entityId, $entities, $isSelf]) {
            $soaEntity = self::soaEntity($entityId);

            $byStatus = [];
            foreach (
                $DB->request([
                    'SELECT'  => ['implementation_status', 'COUNT' => '* AS c'],
                    'FROM'    => Control::getTable(),
                    'WHERE'   => ['entities_id' => $soaEntity, 'applicability' => ['yes', 'partial']],
                    'GROUPBY' => 'implementation_status',
                ]) as $row
            ) {
                $byStatus[(string) $row['implementation_status']] = (int) $row['c'];
            }

            $approved = $DB->request([
                'FROM'  => SoaVersion::getTable(),
                'WHERE' => ['entities_id' => $soaEntity, 'status' => SoaApprovalLogic::VERSION_APPROVED],
                'ORDER' => 'version DESC',
                'LIMIT' => 1,
            ])->current();

            $risks = [];
            if (Risk::canView()) {
                foreach (
                    $DB->request([
                        'SELECT' => ['risk_level', 'status'],
                        'FROM'   => Risk::getTable(),
                        'WHERE'  => ['entities_id' => $entities],
                    ]) as $row
                ) {
                    $risks[] = ['risk_level' => (string) $row['risk_level'], 'status' => (string) $row['status']];
                }
            }

            // Lot 2 : non-conformités ouvertes du périmètre, par gravité.
            $findings = null;
            if (Nonconformity::canView()) {
                $list = [];
                foreach (
                    $DB->request([
                        'SELECT' => ['severity', 'status', 'finding_type'],
                        'FROM'   => Nonconformity::getTable(),
                        'WHERE'  => ['entities_id' => $entities],
                    ]) as $row
                ) {
                    $list[] = [
                        'severity'     => (string) $row['severity'],
                        'status'       => (string) $row['status'],
                        'finding_type' => (string) $row['finding_type'],
                    ];
                }
                $findings = PerimeterRules::nonconformityCounts($list);
            }

            $rows[] = [
                'entities_id'      => $entityId,
                'name'             => Dropdown::getDropdownName('glpi_entities', $entityId),
                'is_self'          => $isSelf,
                'soa_entities_id'  => $soaEntity,
                'soa_name'         => Dropdown::getDropdownName('glpi_entities', $soaEntity),
                'own_soa'          => $soaEntity === $entityId,
                'rate'             => MaturityScore::implementationRate($byStatus),
                'applicable'       => array_sum($byStatus),
                'approved_version' => $approved !== null ? (int) $approved['version'] : 0,
                'approved_date'    => $approved !== null ? (string) ($approved['date_answered'] ?? '') : '',
                'risks'            => PerimeterRules::riskCounts($risks),
                'nonconformities'  => $findings,
            ];
        }

        return $rows;
    }
}

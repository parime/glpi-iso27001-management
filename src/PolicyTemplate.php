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
use Entity;
use GlpiPlugin\Grcmanager\Install\PolicyTemplateTables;
use GlpiPlugin\Grcmanager\Services\Policy\OperatingProcedureRules;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyLifecycle;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyTemplateCatalog as Catalog;
use Session;
use User;

/**
 * Issue #545 : catalogue de modèles de politiques de sécurité prêts à adapter (politique générale
 * A.5.1 et politiques thématiques). Importer un modèle crée toujours une nouvelle politique en
 * brouillon, variables remplacées, reliée section par section aux mesures de la SoA de l'entité
 * active (mécanisme de l'issue #879). Rien n'est approuvé ni remplacé automatiquement.
 *
 * Droits : lecture des politiques pour voir le catalogue, création pour importer ; les liens aux
 * mesures suivent les droits de PolicyCoverage (modifier la politique, lire la mesure).
 */
class PolicyTemplate extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return __('Policy templates', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-template';
    }

    public static function canView(): bool
    {
        return (bool) Policy::canView();
    }

    /** Cast : int sous GLPI 11. */
    public static function canImport(): bool
    {
        return self::canView() && (bool) Policy::canCreate();
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/policytemplate.php';
    }

    /** Sous-page de la rubrique « Conformité » (Menu::sections()). */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(Session::getPluralNumber()),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /** Langue du texte : choisie, sinon celle de l'interface (français ou anglais). */
    public static function language(?string $requested = null): string
    {
        return Catalog::language($requested, (string) ($_SESSION['glpilanguage'] ?? ''));
    }

    /** Nom de l'entité active, proposé pour la variable « organisation ». */
    public static function defaultOrganisation(): string
    {
        $entity = new Entity();

        return $entity->getFromDB((int) Session::getActiveEntity()) ? (string) $entity->fields['name'] : '';
    }

    /** Nom affiché d'un utilisateur, vide s'il n'existe pas. */
    public static function userName(int $usersId): string
    {
        $user = new User();

        return $usersId > 0 && $user->getFromDB($usersId) ? (string) $user->getFriendlyName() : '';
    }

    /**
     * Politiques encore présentes créées depuis chaque modèle : clé => [id => titre].
     *
     * @return array<string, array<int, string>>
     */
    public static function importedPolicies(): array
    {
        global $DB;

        $policies = Policy::getTable();
        $imports = PolicyTemplateTables::IMPORTS;
        $result = [];
        foreach (
            $DB->request([
                'SELECT'     => [$imports . '.template_key', $policies . '.id', $policies . '.title'],
                'FROM'       => $imports,
                'INNER JOIN' => [
                    $policies => ['FKEY' => [$imports => 'plugin_grcmanager_policies_id', $policies => 'id']],
                ],
                'ORDER'      => [$imports . '.id'],
            ]) as $row
        ) {
            $result[(string) $row['template_key']][(int) $row['id']] = (string) $row['title'];
        }

        return $result;
    }

    /** Politique purgée : sa trace d'import disparaît (le modèle peut être réimporté). */
    public static function forgetPolicy(int $policiesId): void
    {
        global $DB;

        $DB->delete(PolicyTemplateTables::IMPORTS, ['plugin_grcmanager_policies_id' => $policiesId]);
    }

    /**
     * Crée une politique en brouillon pour chaque modèle demandé et la relie aux mesures de ses
     * sections. Renvoie les politiques créées et les codes absents de la SoA de l'entité active.
     *
     * @param array<int|string, mixed> $requested clés des modèles
     * @param array{organisation?: string, ciso_users_id?: int, review_months?: mixed} $values
     * @return array{created: list<int>, links: int, unknown: list<string>}
     */
    public static function import(array $requested, string $language, array $values): array
    {
        global $DB;

        $result = ['created' => [], 'links' => 0, 'unknown' => []];
        if (!self::canImport()) {
            return $result;
        }
        $language = self::language($language);
        $data = Catalog::load($language);
        $templates = Catalog::templates($data);
        $cisoId = (int) ($values['ciso_users_id'] ?? 0);
        $fill = [
            'organisation'  => (string) ($values['organisation'] ?? ''),
            'ciso'          => self::userName($cisoId),
            'review_months' => Catalog::reviewMonths($values['review_months'] ?? null),
        ];
        $owner = self::userName($cisoId) !== '' ? $cisoId : (int) Session::getLoginUserID();
        foreach (Catalog::requested($requested) as $key) {
            if (!isset($templates[$key])) {
                continue;
            }
            $template = $templates[$key];
            $policyId = (int) (new Policy())->add([
                'title'       => $template['title'],
                'version'     => '0.1',
                'status'      => PolicyLifecycle::STATUS_DRAFT,
                'category'    => OperatingProcedureRules::CATEGORY_POLICY,
                'description' => Catalog::render($template, $data, $fill),
                'users_id'    => $owner,
            ]);
            if ($policyId <= 0) {
                continue;
            }
            foreach ($template['sections'] as $section) {
                if ($section['controls'] === []) {
                    continue;
                }
                $resolved = PolicyCoverage::resolveControls([], implode(' ', $section['controls']));
                $result['links'] += PolicyCoverage::addLinks([$policyId], $resolved['ids'], $section['title']);
                $result['unknown'] = array_merge($result['unknown'], $resolved['unknown']);
            }
            $DB->insert(PolicyTemplateTables::IMPORTS, [
                'template_key'                  => $key,
                'language'                      => $language,
                'plugin_grcmanager_policies_id' => $policyId,
                'users_id'                      => (int) Session::getLoginUserID(),
                'date_creation'                 => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);
            $result['created'][] = $policyId;
        }
        $result['unknown'] = array_values(array_unique($result['unknown']));

        return $result;
    }
}

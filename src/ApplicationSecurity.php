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

use Appliance;
use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\ApplicationSecurityBase;
use GlpiPlugin\Grcmanager\Install\SecureDevelopmentTables;
use GlpiPlugin\Grcmanager\Services\SecureDevelopment\SecureDevelopmentRules as Rules;
use Session;
use User;

/**
 * Issue #777 : fiche « développement sécurisé » d'une application de GLPI (Appliance), en onglet de
 * l'application. Le registre des applications est celui du cœur : aucune application en double.
 *
 * Une application qui a une fiche fait partie du registre des applications développées ou
 * maintenues en interne (page « Développement sécurisé ») : responsable, dépôt de code, référentiel
 * suivi, revue de code, analyses SAST, DAST et des dépendances, séparation des environnements et sa
 * justification. Preuves des mesures A.8.25, A.8.28, A.8.29 et A.8.31.
 *
 * Droits : droit général du plugin (lecture pour l'onglet, création ou modification pour la fiche),
 * et l'application doit être visible par la personne (droit natif et entité, can()).
 */
class ApplicationSecurity extends ApplicationSecurityBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return SecureDevelopmentTables::APPLICATIONS;
    }

    public static function getTypeName($nb = 0)
    {
        return __('Secure development', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-code';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/applicationsecurity.form.php';
    }

    /**
     * @return array<string, string>
     */
    public static function frameworkLabels(): array
    {
        return [
            Rules::FRAMEWORK_ASVS  => __('OWASP ASVS (Application Security Verification Standard)', 'grcmanager'),
            Rules::FRAMEWORK_TOP10 => __('OWASP Top 10', 'grcmanager'),
            Rules::FRAMEWORK_SAMM  => __('OWASP SAMM (Software Assurance Maturity Model)', 'grcmanager'),
            Rules::FRAMEWORK_ANSSI => __('ANSSI recommendations', 'grcmanager'),
            Rules::FRAMEWORK_OTHER => __('Other (specify)', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function practiceLabels(): array
    {
        return [
            'framework'            => __('Secure development framework', 'grcmanager'),
            'code_review_required' => __('Mandatory code review', 'grcmanager'),
            'sast'                 => __('Static analysis (SAST)', 'grcmanager'),
            'dast'                 => __('Dynamic analysis (DAST)', 'grcmanager'),
            'dependency_scan'      => __('Dependency analysis', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function separationLabels(): array
    {
        return [
            Rules::SEPARATION_YES     => __('Yes', 'grcmanager'),
            Rules::SEPARATION_NO      => __('No', 'grcmanager'),
            Rules::SEPARATION_UNKNOWN => __('Not specified', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, mixed>|null fiche de l'application, null si elle n'en a pas
     */
    public static function getForAppliance(int $appliancesId): ?array
    {
        global $DB;

        if ($appliancesId <= 0 || !$DB->tableExists(self::getTable())) {
            return null;
        }
        $criteria = ['FROM' => self::getTable(), 'WHERE' => ['appliances_id' => $appliancesId], 'LIMIT' => 1];
        foreach ($DB->request($criteria) as $row) {
            return $row;
        }

        return null;
    }

    /**
     * Valeurs reçues du formulaire, toutes filtrées : listes fermées, cases à 0 ou 1, adresse de
     * dépôt http(s) ou ssh, responsable existant. L'application elle-même n'est jamais prise ici.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $owner = (int) ($input['users_id_owner'] ?? 0);
        if ($owner > 0 && !(new User())->getFromDB($owner)) {
            $owner = 0;
        }
        $clean = [
            'users_id_owner'           => max(0, $owner),
            'repository_url'           => Rules::sanitizeRepository($input['repository_url'] ?? ''),
            'framework'                => Rules::sanitizeFramework($input['framework'] ?? ''),
            'framework_detail'         => mb_substr(trim((string) ($input['framework_detail'] ?? '')), 0, 255),
            'tools'                    => trim((string) ($input['tools'] ?? '')),
            'environments_separated'   => Rules::sanitizeSeparation($input['environments_separated'] ?? ''),
            'separation_justification' => trim((string) ($input['separation_justification'] ?? '')),
            'comment'                  => trim((string) ($input['comment'] ?? '')),
        ];
        foreach (Rules::PRACTICES as $practice) {
            $clean[$practice] = Rules::flag($input[$practice] ?? 0);
        }

        return $clean;
    }

    /**
     * Enregistre la fiche de l'application (création ou mise à jour). L'appelant a vérifié les droits.
     *
     * @param array<string, mixed> $input
     * @return string|null message d'erreur, null si enregistré
     */
    public static function saveFor(int $appliancesId, array $input): ?string
    {
        $values = self::sanitize($input);
        if (Rules::justificationMissing($values['environments_separated'], $values['separation_justification'])) {
            return __(
                'Explain why the environments are not separated (accepted risk, compensating measure).',
                'grcmanager'
            );
        }
        $existing = self::getForAppliance($appliancesId);
        $profile = new self();
        $saved = $existing !== null
            ? $profile->update(['id' => (int) $existing['id']] + $values)
            : $profile->add(['appliances_id' => $appliancesId] + $values);

        return $saved ? null : __('The secure development sheet could not be saved.', 'grcmanager');
    }

    /**
     * Hooks::ITEM_PURGE (hook.php) : la fiche d'une application supprimée définitivement disparaît
     * avec elle.
     */
    public static function forgetItem(CommonDBTM $item): void
    {
        global $DB;

        if ($item instanceof Appliance && $DB->tableExists(self::getTable())) {
            $DB->delete(self::getTable(), ['appliances_id' => (int) $item->getID()]);
        }
    }

    /**
     * Onglet sur la fiche d'une application, avec le droit de lecture du plugin.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Appliance || $item->isNewItem() || !Session::haveRight(self::$rightname, READ)) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(1));
    }

    /**
     * ajax/common.tabs.php sert ce contenu sans passer par getTabNameForItem() : les droits sont
     * vérifiés ici aussi (droit du plugin, application lisible avec son entité).
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (
            !$item instanceof Appliance || $item->isNewItem() || !Session::haveRight(self::$rightname, READ)
            || !$item->canViewItem()
        ) {
            return false;
        }
        $appliancesId = (int) $item->getID();
        $profile = self::getForAppliance($appliancesId);
        $canEdit = Session::haveRight(self::$rightname, $profile === null ? CREATE : UPDATE);
        $techOwner = (int) ($item->fields['users_id_tech'] ?? 0);
        $owner = (int) ($profile['users_id_owner'] ?? 0);

        TemplateRenderer::getInstance()->display('@grcmanager/application_security_tab.html.twig', [
            'appliances_id'   => $appliancesId,
            'profile'         => $profile ?? [],
            'has_profile'     => $profile !== null,
            'can_edit'        => $canEdit,
            'action'          => self::getFormURL(),
            'frameworks'      => self::frameworkLabels(),
            'practices'       => self::practiceLabels(),
            'separations'     => self::separationLabels(),
            'missing'         => $profile !== null ? Rules::missingPractices($profile) : [],
            'unjustified'     => $profile !== null && Rules::justificationMissing(
                (string) $profile['environments_separated'],
                (string) ($profile['separation_justification'] ?? '')
            ),
            'repository_link' => $profile !== null && Rules::isWebLink((string) $profile['repository_url']),
            'owner_name'      => $owner > 0 ? getUserName($owner) : '',
            'tech_owner_name' => $techOwner > 0 ? getUserName($techOwner) : '',
            'entities_id'     => (int) $item->fields['entities_id'],
            'register_url'    => SecureDevelopment::getPageURL(),
        ]);

        return true;
    }
}

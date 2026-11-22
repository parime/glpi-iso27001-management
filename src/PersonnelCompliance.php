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
use Config;
use CronTask;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\PersonnelComplianceBase;
use GlpiPlugin\Grcmanager\Services\Access\AccountHygieneRules;
use GlpiPlugin\Grcmanager\Services\People\PersonnelComplianceProvider as Provider;
use GlpiPlugin\Grcmanager\Services\People\PersonnelComplianceRules as Rules;
use Html;
use NotificationEvent;
use Preference;
use Session;
use User;

/**
 * Issue #546 : fiche de conformité sécurité de chaque collaborateur (A.6.1, A.6.2, A.6.3, A.6.5,
 * A.6.6, A.5.18). Rassemble, pour une personne, ce que le plugin suit déjà : formations
 * obligatoires, charte signée (assetsign, si présent), prise de connaissance des politiques, revue
 * des accès, hygiène du compte ; plus deux éléments propres à la fiche, la vérification préalable
 * à l'embauche et l'engagement de confidentialité (statut et date seulement, jamais la pièce).
 *
 * Une ligne de la table par personne, créée seulement quand ces éléments sont saisis ou qu'un
 * responsable est prévenu : la consultation n'écrit rien. Les écarts produisent au plus une
 * notification au responsable hiérarchique (tâche planifiée désactivée par défaut, ou bouton de la
 * fiche) ; rien n'est jamais modifié automatiquement.
 *
 * Droits : chacun voit sa propre fiche (onglet de ses préférences). La fiche d'une autre personne
 * et la liste exigent la lecture du plugin et des utilisateurs, limitées aux comptes habilités dans
 * les entités de la session ; le responsable hiérarchique d'une personne voit aussi sa fiche.
 */
class PersonnelCompliance extends PersonnelComplianceBase
{
    public const RIGHTNAME = 'plugin_grcmanager';
    public const CONFIG_CONTEXT = 'plugin:grcmanager';
    public const DAYS_KEY = 'personnel_compliance_days';
    public const WEIGHTS_KEY = 'personnel_compliance_weights';

    public const GAP_EVENT = 'personnel_compliance_gap';

    /** Une même personne ne fait l'objet que d'une notification par semaine au plus. */
    public const NOTIFY_INTERVAL_DAYS = 7;

    public const PAGE_SIZE = 50;

    public static function getTable($classname = null)
    {
        return Provider::RECORDS_TABLE;
    }

    public static function getTypeName($nb = 0)
    {
        return __('Personnel security compliance', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-user-shield';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/personnelcompliance.php';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/personnelcompliance.form.php';
    }

    public static function getExportURL(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/personnelcompliance.export.php';
    }

    /** Liste globale et fiches des autres : lecture du plugin et des utilisateurs. */
    public static function canViewList(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ) && (bool) Session::haveRight(User::$rightname, READ);
    }

    public static function canView(): bool
    {
        return self::canViewList();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** Sous-page de la rubrique « Revue des accès » (barre d'onglets commune, aucun menu en plus). */
    public static function getMenuContent()
    {
        if (!self::canViewList()) {
            return false;
        }

        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /** Compte actif habilité dans les entités de la session. */
    private static function isVisible(int $usersId): bool
    {
        return $usersId > 0 && Provider::users(['users_id' => [$usersId]]) !== [];
    }

    /** Responsable hiérarchique déclaré sur le compte de la personne. */
    private static function isSupervisorOf(int $usersId): bool
    {
        $me = (int) Session::getLoginUserID();
        $user = new User();

        return $me > 0 && $user->getFromDB($usersId) && (int) $user->fields['users_id_supervisor'] === $me;
    }

    /**
     * @param bool $allowOwn sa propre fiche sans droit (onglet des préférences) ; sur la fiche
     *                       utilisateur, l'onglet exige toujours un droit
     */
    public static function canViewFor(int $usersId, bool $allowOwn = true): bool
    {
        if ($usersId <= 0) {
            return false;
        }
        if ($allowOwn && $usersId === (int) Session::getLoginUserID()) {
            return true;
        }
        if (self::canViewList() && self::isVisible($usersId)) {
            return true;
        }

        return (bool) Session::haveRight(User::$rightname, READ) && self::isSupervisorOf($usersId);
    }

    /** Saisie de la vérification et de l'engagement, et notification : modification du plugin. */
    public static function canEditFor(int $usersId): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, UPDATE)
            && (bool) Session::haveRight(User::$rightname, READ)
            && self::isVisible($usersId);
    }

    // ------------------------------------------------------------------ Réglages.

    public static function days(): int
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::DAYS_KEY]);

        return Rules::normalizeDays($values[self::DAYS_KEY] ?? null);
    }

    /**
     * @return array<string, int>
     */
    public static function weights(): array
    {
        $values = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::WEIGHTS_KEY]);
        $decoded = json_decode((string) ($values[self::WEIGHTS_KEY] ?? ''), true);

        return Rules::normalizeWeights($decoded);
    }

    /**
     * @param array<string, mixed> $weights
     * @return bool vrai si un réglage a changé
     */
    public static function saveSettings(mixed $days, array $weights): bool
    {
        $before = [self::days(), self::weights()];
        $after = [Rules::normalizeDays($days), Rules::normalizeWeights($weights)];
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [
            self::DAYS_KEY    => $after[0],
            self::WEIGHTS_KEY => json_encode($after[1]),
        ]);

        return $before !== $after;
    }

    /**
     * Lignes de réglage de la configuration du plugin.
     *
     * @return array{days: int, min: int, max: int, weights: list<array<string, mixed>>, choices: list<int>}
     */
    public static function configData(): array
    {
        $labels = self::getComponentLabels();
        $weights = self::weights();
        $rows = [];
        foreach (Rules::COMPONENTS as $key) {
            $rows[] = [
                'key'       => $key,
                'label'     => $labels[$key],
                'reference' => Rules::REFERENCES[$key],
                'weight'    => $weights[$key],
            ];
        }

        return [
            'days'    => self::days(),
            'min'     => Rules::MIN_DAYS,
            'max'     => Rules::MAX_DAYS,
            'weights' => $rows,
            'choices' => Rules::WEIGHTS,
        ];
    }

    // ------------------------------------------------------------------ Libellés.

    /**
     * @return array<string, string>
     */
    public static function getComponentLabels(): array
    {
        return [
            Rules::SCREENING     => __('Pre-employment screening', 'grcmanager'),
            Rules::NDA           => __('Confidentiality agreement', 'grcmanager'),
            Rules::TRAINING      => __('Mandatory trainings', 'grcmanager'),
            Rules::CHARTER       => __('IT charter signed', 'grcmanager'),
            Rules::POLICIES      => __('Security policies read', 'grcmanager'),
            Rules::ACCESS_REVIEW => __('Access review', 'grcmanager'),
            Rules::ACCOUNT       => __('Account hygiene', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function getComponentIcons(): array
    {
        return [
            Rules::SCREENING     => 'ti ti-id-badge-2',
            Rules::NDA           => 'ti ti-lock-square',
            Rules::TRAINING      => 'ti ti-school',
            Rules::CHARTER       => 'ti ti-signature',
            Rules::POLICIES      => 'ti ti-file-check',
            Rules::ACCESS_REVIEW => 'ti ti-user-search',
            Rules::ACCOUNT       => 'ti ti-user-cog',
        ];
    }

    /**
     * Libellé, icône et couleurs (badge contrasté) de chaque état.
     *
     * @return array<string, array{label: string, icon: string, color: string}>
     */
    public static function getStatuses(): array
    {
        $state = static fn (string $label, string $icon, string $color): array => [
            'label' => $label,
            'icon'  => $icon,
            'color' => $color,
        ];

        return [
            Rules::OK             => $state(__('Compliant', 'grcmanager'), 'ti ti-circle-check', 'success'),
            Rules::PENDING        => $state(__('To do, within the deadline', 'grcmanager'), 'ti ti-clock', 'warning'),
            Rules::LATE           => $state(__('Gap', 'grcmanager'), 'ti ti-alert-triangle', 'danger'),
            Rules::NOT_TRACKED    => $state(__('Not tracked', 'grcmanager'), 'ti ti-plug-off', 'secondary'),
            Rules::NOT_APPLICABLE => $state(__('Not applicable', 'grcmanager'), 'ti ti-minus', 'secondary'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getScreeningStatuses(): array
    {
        return [
            ''                              => __('Not recorded', 'grcmanager'),
            Rules::SCREENING_DONE           => __('Done', 'grcmanager'),
            Rules::SCREENING_NOT_APPLICABLE => __('Not applicable', 'grcmanager'),
        ];
    }

    private static function date(mixed $value): string
    {
        return is_string($value) && $value !== '' ? (string) Html::convDate($value) : '';
    }

    /**
     * @param list<string> $titles
     */
    private static function titles(array $titles): string
    {
        return implode(', ', array_map(static fn (string $title): string => $title !== '' ? $title : '-', $titles));
    }

    /**
     * Texte du détail d'une composante.
     *
     * @param array{key: string, code: string, params: array<string, mixed>} $component
     */
    public static function detail(array $component): string
    {
        $p = $component['params'];
        if ($component['code'] === 'disabled') {
            return __('Not counted (weight 0 in the configuration)', 'grcmanager');
        }

        return match ($component['key'] . '.' . $component['code']) {
            'screening.done'             => ($p['date'] ?? null) !== null
                ? sprintf(__('Done on %s', 'grcmanager'), self::date($p['date']))
                : __('Done', 'grcmanager'),
            'screening.not_applicable'   => __('Not applicable for this person', 'grcmanager'),
            'screening.missing'          => __('Not recorded', 'grcmanager'),
            'nda.signed'                 => ($p['version'] ?? '') !== ''
                ? sprintf(__('Signed on %1$s (template %2$s)', 'grcmanager'), self::date($p['date']), $p['version'])
                : sprintf(__('Signed on %s', 'grcmanager'), self::date($p['date'])),
            'nda.missing'                => __('No signed agreement recorded', 'grcmanager'),
            'training.none'              => __('No mandatory training assigned', 'grcmanager'),
            'training.pending'           => sprintf(__('To attend: %s', 'grcmanager'), self::titles($p['titles'])),
            'training.renewal_overdue'   => sprintf(
                __('Renewal overdue: %s', 'grcmanager'),
                self::titles($p['titles'])
            ),
            'training.done'              => sprintf(
                _n(
                    '%d mandatory training completed',
                    '%d mandatory trainings completed',
                    (int) $p['count'],
                    'grcmanager'
                ),
                (int) $p['count']
            ),
            'charter.not_tracked'        => __('Not tracked: the assetsign plugin is not active', 'grcmanager'),
            'charter.signed'             => sprintf(
                __('Signed on %1$s (%2$s)', 'grcmanager'),
                self::date($p['date']),
                $p['campaign']
            ),
            'charter.pending'            => sprintf(
                __('Sent for signature, not signed yet (%s)', 'grcmanager'),
                $p['campaign']
            ),
            'charter.none'               => __('No charter campaign has reached this person', 'grcmanager'),
            'policies.none'              => __('No policy campaign concerns this person', 'grcmanager'),
            'policies.done'              => sprintf(
                _n('%d policy acknowledged', '%d policies acknowledged', (int) $p['count'], 'grcmanager'),
                (int) $p['count']
            ),
            'policies.pending'           => sprintf(__('To read: %s', 'grcmanager'), self::titles($p['titles'])),
            'access_review.reviewed'     => sprintf(__('Last reviewed on %s', 'grcmanager'), self::date($p['date'])),
            'access_review.campaign_open' => __('In an open access review campaign', 'grcmanager'),
            'access_review.campaign_late' => __(
                'The access review campaign deadline has passed without a decision',
                'grcmanager'
            ),
            'access_review.outdated'     => sprintf(
                __('Last reviewed on %s, more than 12 months ago', 'grcmanager'),
                self::date($p['date'])
            ),
            'access_review.never'        => __('Never reviewed', 'grcmanager'),
            'account.healthy'            => __('No account issue', 'grcmanager'),
            'account.checks'             => implode(', ', array_map(
                static fn (string $check): string => AccountHygiene::getCheckLabel(
                    $check,
                    AccountHygieneRules::DEFAULT_INACTIVITY_DAYS
                ),
                $p['checks']
            )),
            default                      => '',
        };
    }

    // ------------------------------------------------------------------ Calcul.

    /**
     * Fiches évaluées d'un ensemble de comptes.
     *
     * @param array{entities_id?: int, groups_id?: int, users_id?: list<int>} $filters
     * @return list<array<string, mixed>>
     */
    public static function evaluateUsers(array $filters = [], bool $restrict = true): array
    {
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $today = substr($now, 0, 10);
        $users = Provider::users($filters, $restrict);
        $facts = Provider::facts($users, $now);
        $days = self::days();
        $weights = self::weights();

        $rows = [];
        foreach ($users as $id => $user) {
            $rows[] = [
                'id'          => $id,
                'name'        => (string) $user['name'],
                'fullname'    => formatUserName(
                    $id,
                    (string) $user['name'],
                    (string) $user['realname'],
                    (string) $user['firstname']
                ),
                'arrival'     => $facts[$id]['arrival'],
                'supervisor'  => (int) $user['users_id_supervisor'],
                'entities_id' => (int) $user['entities_id'],
            ] + Rules::evaluate($facts[$id], $today, $days, $weights);
        }

        return $rows;
    }

    /**
     * Filtres de la liste et des exports, lus dans la requête (valeurs fermées ou entières).
     *
     * @param array<string, mixed> $input
     * @return array{entities_id: int, groups_id: int, status: string, component: string, page: int}
     */
    public static function filters(array $input): array
    {
        $status = (string) ($input['status'] ?? '');
        $component = (string) ($input['component'] ?? '');

        return [
            'entities_id' => isset($input['entities_id']) && $input['entities_id'] !== ''
                ? (int) $input['entities_id']
                : -1,
            'groups_id'   => max(0, (int) ($input['groups_id'] ?? 0)),
            'status'      => in_array($status, [Rules::OK, Rules::PENDING, Rules::LATE], true) ? $status : '',
            'component'   => in_array($component, Rules::COMPONENTS, true) ? $component : '',
            'page'        => max(0, (int) ($input['page'] ?? 0)),
        ];
    }

    /**
     * Fiches visibles correspondant aux filtres (état global, composante en écart).
     *
     * @param array{entities_id: int, groups_id: int, status: string, component: string} $filters
     * @return list<array<string, mixed>>
     */
    public static function filteredRows(array $filters): array
    {
        $entity = $filters['entities_id'];
        if ($entity >= 0 && !in_array($entity, array_map('intval', $_SESSION['glpiactiveentities'] ?? []), true)) {
            return [];
        }
        $rows = self::evaluateUsers([
            'entities_id' => $entity,
            'groups_id'   => $filters['groups_id'],
        ]);

        return array_values(array_filter($rows, static function (array $row) use ($filters): bool {
            if ($filters['status'] !== '' && $row['status'] !== $filters['status']) {
                return false;
            }
            if ($filters['component'] === '') {
                return true;
            }
            foreach ($row['components'] as $component) {
                if ($component['key'] === $filters['component'] && $component['status'] === Rules::LATE) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Ligne affichable d'une fiche (libellés, badges, détails).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decorate(array $row): array
    {
        $labels = self::getComponentLabels();
        $icons = self::getComponentIcons();
        $statuses = self::getStatuses();
        $components = [];
        foreach ($row['components'] as $component) {
            $components[] = $component + [
                'label'        => $labels[$component['key']],
                'icon'         => $icons[$component['key']],
                'reference'    => Rules::REFERENCES[$component['key']],
                'state'        => $statuses[$component['status']],
                'detail'       => self::detail($component),
                'deadline_text' => self::date($component['deadline']),
            ];
        }

        return [
            'components'   => $components,
            'state'        => $statuses[$row['status']],
            'url'          => User::getFormURLWithID((int) $row['id']),
            'arrival_text' => self::date($row['arrival']),
        ] + $row;
    }

    // ------------------------------------------------------------------ Onglets.

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Preference) {
            return self::createTabEntry(__('My security compliance', 'grcmanager'), 0, null, self::getIcon());
        }
        if (!$item instanceof User || $item->isNewItem() || !self::canViewFor($item->getID(), false)) {
            return '';
        }

        return self::createTabEntry(__('Security compliance', 'grcmanager'), 0, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if ($item instanceof Preference) {
            self::showFiche((int) Session::getLoginUserID(), true);

            return true;
        }
        if (!$item instanceof User || $item->isNewItem() || !self::canViewFor($item->getID(), false)) {
            return false;
        }
        self::showFiche($item->getID(), $item->getID() === (int) Session::getLoginUserID());

        return true;
    }

    /**
     * Fiche d'une personne. Sa propre fiche est toujours en lecture seule.
     */
    public static function showFiche(int $usersId, bool $own): void
    {
        $rows = self::evaluateUsers(['users_id' => [$usersId]], false);
        if ($rows === []) {
            TemplateRenderer::getInstance()->display('@grcmanager/personnel_compliance_tab.html.twig', [
                'row' => null,
            ]);

            return;
        }
        $row = self::decorate($rows[0]);
        $record = Provider::records([$usersId])[$usersId] ?? [];
        $canEdit = !$own && self::canEditFor($usersId);

        TemplateRenderer::getInstance()->display('@grcmanager/personnel_compliance_tab.html.twig', [
            'row'           => $row,
            'own'           => $own,
            'days'          => self::days(),
            'can_edit'      => $canEdit,
            'record'        => [
                'screening_status' => (string) ($record['screening_status'] ?? ''),
                'screening_date'   => (string) ($record['screening_date'] ?? ''),
                'nda_date'         => (string) ($record['nda_date'] ?? ''),
                'nda_version'      => (string) ($record['nda_version'] ?? ''),
                'date_notified'    => (string) ($record['date_notified'] ?? ''),
            ],
            'screening_statuses' => self::getScreeningStatuses(),
            'supervisor'    => $row['supervisor'] > 0 ? getUserName($row['supervisor']) : '',
            'can_notify'    => $canEdit && $row['supervisor'] > 0 && $row['late'] > 0,
            'form_url'      => self::getFormURL(),
            'export_url'    => self::getExportURL(),
            'list_url'      => self::canViewList() ? self::getSearchURL() : '',
        ]);
    }

    // ------------------------------------------------------------------ Liste globale.

    /**
     * Synthèse, agrégation par groupe et lignes d'une page de la liste.
     *
     * @param array{entities_id: int, groups_id: int, status: string, component: string, page: int} $filters
     */
    public static function showList(array $filters): void
    {
        $all = self::evaluateUsers([
            'entities_id' => $filters['entities_id'] >= 0
                && in_array($filters['entities_id'], array_map('intval', $_SESSION['glpiactiveentities'] ?? []), true)
                ? $filters['entities_id'] : -1,
        ]);
        $rows = self::filteredRows($filters);
        $pages = max(1, (int) ceil(count($rows) / self::PAGE_SIZE));
        $page = min($filters['page'], $pages - 1);

        $byComponent = [];
        foreach (Rules::COMPONENTS as $key) {
            $byComponent[$key] = 0;
        }
        foreach ($rows as $row) {
            foreach ($row['components'] as $component) {
                if ($component['status'] === Rules::LATE) {
                    $byComponent[$component['key']]++;
                }
            }
        }
        $labels = self::getComponentLabels();
        $gaps = [];
        foreach ($byComponent as $key => $count) {
            $gaps[] = ['key' => $key, 'label' => $labels[$key], 'count' => $count];
        }

        $query = array_filter([
            'entities_id' => $filters['entities_id'] >= 0 ? $filters['entities_id'] : null,
            'groups_id'   => $filters['groups_id'] ?: null,
            'status'      => $filters['status'] ?: null,
            'component'   => $filters['component'] ?: null,
        ], static fn ($value): bool => $value !== null);

        TemplateRenderer::getInstance()->display('@grcmanager/personnel_compliance_list.html.twig', [
            'summary'     => Rules::staffRate($rows),
            'gaps'        => $gaps,
            'groups'      => self::groupAggregation($all),
            'rows'        => array_map(
                [self::class, 'decorate'],
                array_slice($rows, $page * self::PAGE_SIZE, self::PAGE_SIZE)
            ),
            'filters'     => $filters,
            'page'        => $page,
            'pages'       => $pages,
            'total'       => count($rows),
            'query'       => $query,
            'entities'    => self::entityChoices(),
            'statuses'    => self::getStatuses(),
            'components'  => $labels,
            'days'        => self::days(),
            'action'      => self::getSearchURL(),
            'export_url'  => self::getExportURL(),
            'charter_tracked' => Provider::charterAvailable(),
        ]);
    }

    /**
     * Entités actives de la session, pour le filtre.
     *
     * @return array<int, string>
     */
    private static function entityChoices(): array
    {
        $choices = [];
        foreach (array_map('intval', $_SESSION['glpiactiveentities'] ?? []) as $id) {
            $choices[$id] = (string) Dropdown::getDropdownName('glpi_entities', $id);
        }
        asort($choices);

        return $choices;
    }

    /**
     * Issue #546 (veille) : taux de conformité et nombre d'écarts par groupe GLPI (service).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: int, name: string, people: int, late: int, rate: ?int}>
     */
    public static function groupAggregation(array $rows): array
    {
        $byId = array_column($rows, null, 'id');
        $groups = [];
        foreach (Provider::groups(array_keys($byId)) as $usersId => $groupIds) {
            foreach ($groupIds as $groupId) {
                $groups[$groupId][] = ['status' => (string) $byId[$usersId]['status']];
            }
        }
        $result = [];
        foreach ($groups as $groupId => $people) {
            $rate = Rules::staffRate($people);
            $result[] = [
                'id'     => $groupId,
                'name'   => (string) Dropdown::getDropdownName('glpi_groups', $groupId),
                'people' => $rate['people'],
                'late'   => $rate['late'],
                'rate'   => $rate['rate'],
            ];
        }
        usort($result, static fn (array $a, array $b): int => [$b['late'], $a['name']] <=> [$a['late'], $b['name']]);

        return $result;
    }

    // ------------------------------------------------------------------ Exports.

    /**
     * Lignes de l'export : fiches filtrées de la liste, ou la fiche d'une personne.
     *
     * @param array{entities_id: int, groups_id: int, status: string, component: string, page: int} $filters
     * @return list<array<string, mixed>>
     */
    public static function exportRows(array $filters, int $usersId = 0): array
    {
        $rows = $usersId > 0
            ? self::evaluateUsers(['users_id' => [$usersId]], false)
            : self::filteredRows($filters);

        return array_map([self::class, 'decorate'], $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function renderPdf(array $rows): string
    {
        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/personnel_compliance_report.html.twig', [
            'report_title'       => self::getTypeName(),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'generated_by'       => getUserName((int) Session::getLoginUserID()),
            'rows'               => $rows,
            'summary'            => Rules::staffRate($rows),
            'days'               => self::days(),
            'charter_tracked'    => Provider::charterAvailable(),
        ]);
    }

    /**
     * En-tête et lignes du CSV : une ligne par personne et par composante.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<list<string>>
     */
    public static function csvLines(array $rows): array
    {
        $lines = [[
            __('User', 'grcmanager'), __('Login', 'grcmanager'), __('Arrival', 'grcmanager'),
            __('Overall status', 'grcmanager'), __('Score', 'grcmanager'), __('Component', 'grcmanager'),
            __('Reference', 'grcmanager'), __('Status', 'grcmanager'), __('Detail', 'grcmanager'),
            __('Due date', 'grcmanager'), __('Weight', 'grcmanager'),
        ]];
        foreach ($rows as $row) {
            foreach ($row['components'] as $component) {
                $lines[] = array_map([self::class, 'csvCell'], [
                    $row['fullname'], $row['name'], $row['arrival_text'], $row['state']['label'],
                    $row['score'] === null ? '' : $row['score'] . ' %', $component['label'], $component['reference'],
                    $component['state']['label'], $component['detail'], $component['deadline_text'],
                    (string) $component['weight'],
                ]);
            }
        }

        return $lines;
    }

    /** Neutralise les formules dans un tableur (cellule commençant par =, +, -, @). */
    public static function csvCell(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'" . $text : $text;
    }

    // ------------------------------------------------------------------ Saisie.

    /**
     * Vérification préalable et engagement de confidentialité d'une personne (statut et dates
     * seulement : aucune pièce ni commentaire, la pièce reste chez les RH).
     *
     * @param array<string, mixed> $input
     */
    public static function saveRecord(int $usersId, array $input): bool
    {
        if (!self::canEditFor($usersId)) {
            return false;
        }
        $status = (string) ($input['screening_status'] ?? '');
        $fields = [
            'screening_status' => array_key_exists($status, self::getScreeningStatuses()) ? $status : '',
            'screening_date'   => self::dateInput($input['screening_date'] ?? null),
            'nda_date'         => self::dateInput($input['nda_date'] ?? null),
            'nda_version'      => mb_substr(trim((string) ($input['nda_version'] ?? '')), 0, 64),
        ];
        if ($fields['screening_status'] !== Rules::SCREENING_DONE) {
            $fields['screening_date'] = null;
        }
        if ($fields['nda_date'] === null) {
            $fields['nda_version'] = '';
        }

        $record = new self();
        if ($record->getFromDBByCrit(['users_id' => $usersId])) {
            return $record->update(['id' => $record->getID()] + $fields);
        }

        return $record->add(['users_id' => $usersId] + $fields) !== false;
    }

    private static function dateInput(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false ? $value : null;
    }

    /**
     * Prévient le responsable hiérarchique des écarts d'une personne (notification seulement).
     *
     * @param array<string, mixed> $row fiche évaluée
     */
    private static function notify(array $row): bool
    {
        if ((int) $row['supervisor'] <= 0 || (int) $row['late'] === 0) {
            return false;
        }
        $record = new self();
        if (!$record->getFromDBByCrit(['users_id' => $row['id']])) {
            $id = $record->add(['users_id' => $row['id']]);
            if ($id === false || !$record->getFromDB((int) $id)) {
                return false;
            }
        }
        $gaps = [];
        foreach (self::decorate($row)['components'] as $component) {
            if ($component['status'] === Rules::LATE) {
                $gaps[] = sprintf(__('%1$s: %2$s'), $component['label'], $component['detail']);
            }
        }
        $sent = NotificationEvent::raiseEvent(self::GAP_EVENT, $record, [
            'entities_id' => (int) $row['entities_id'],
            'fullname'    => $row['fullname'],
            'gaps'        => $gaps,
            'supervisor'  => (int) $row['supervisor'],
        ]);
        $record->update(['id' => $record->getID(), 'date_notified' => date('Y-m-d H:i:s')]);

        return (bool) $sent;
    }

    /** Bouton de la fiche : prévenir le responsable maintenant. */
    public static function notifyManager(int $usersId): bool
    {
        if (!self::canEditFor($usersId)) {
            return false;
        }
        $rows = self::evaluateUsers(['users_id' => [$usersId]], false);

        return $rows !== [] && self::notify($rows[0]);
    }

    /**
     * @return array<string, string>
     */
    public static function cronInfo(string $name): array
    {
        return $name === 'personnelcompliancereminder'
            ? ['description' => __('Personnel security compliance: notify managers of gaps', 'grcmanager')]
            : [];
    }

    /**
     * Tâche planifiée, désactivée à l'installation : prévient chaque responsable des écarts de ses
     * collaborateurs, au plus une fois par semaine et par personne. Rien d'autre n'est modifié.
     */
    public static function cronPersonnelcompliancereminder(CronTask $task): int
    {
        $limit = date('Y-m-d H:i:s', strtotime('-' . self::NOTIFY_INTERVAL_DAYS . ' days'));
        $rows = array_filter(
            self::evaluateUsers([], false),
            static fn (array $row): bool => (int) $row['late'] > 0 && (int) $row['supervisor'] > 0
        );
        $records = Provider::records(array_values(array_column($rows, 'id')));
        $notified = 0;
        foreach ($rows as $row) {
            $last = (string) ($records[$row['id']]['date_notified'] ?? '');
            if ($last !== '' && $last > $limit) {
                continue;
            }
            if (self::notify($row)) {
                $notified++;
            }
        }
        $task->addVolume($notified);
        $task->log(sprintf(
            __('%d manager notification(s) raised for personnel compliance gaps.', 'grcmanager'),
            $notified
        ));

        return $notified > 0 ? 1 : 0;
    }

    /**
     * Carte du tableau de bord : taux de conformité du personnel des entités de la session.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function dashboardCard(array $params = []): array
    {
        $rate = self::canViewList() ? Rules::staffRate(self::evaluateUsers())['rate'] : null;

        return [
            'number' => $rate ?? 0,
            'url'    => self::getSearchURL(),
            'label'  => $params['label'] ?? __('Personnel security compliance (%)', 'grcmanager'),
            'icon'   => self::getIcon(),
        ];
    }
}

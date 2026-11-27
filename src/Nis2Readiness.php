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
use Document_Item;
use GlpiPlugin\Grcmanager\Config\Block\RecyfBlock;
use GlpiPlugin\Grcmanager\Config\SettingsLink;
use GlpiPlugin\Grcmanager\Install\SupplierQuestionnaireTables;
use GlpiPlugin\Grcmanager\Navigation\Section\RegulationSection;
use GlpiPlugin\Grcmanager\Navigation\Sections;
use GlpiPlugin\Grcmanager\Services\Acn\AcnRules;
use GlpiPlugin\Grcmanager\Services\Context\OfficialContactRules;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Dashboard\SecurityIncidentCardProvider;
use GlpiPlugin\Grcmanager\Services\Incident\RegulatoryDeadlines;
use GlpiPlugin\Grcmanager\Services\Readiness\CertificationReadinessRules as Base;
use GlpiPlugin\Grcmanager\Services\Readiness\Nis2ReadinessRules as Rules;
use GlpiPlugin\Grcmanager\Services\Recyf\RecyfCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Recyf\RecyfRules;
use PluginGrcmanagerSecurityIncident;
use Session;

/**
 * Issue #1158 : parcours de mise en conformité NIS2 (directive (UE) 2022/2555), à côté de la
 * préparation à la certification ISO 27001 (rubrique Pilotage). Même moteur et mêmes composants :
 * états, score et prochaine étape de CertificationReadinessRules, étapes ISO 27001 reprises telles
 * quelles quand NIS2 demande la même chose (CertificationReadiness::stepEvaluations()).
 *
 * - Chaque critère cite l'article de la directive, renvoie vers l'écran GRC qui permet d'y répondre
 *   (bouton direct) et donne son chemin dans le menu.
 * - Suivi du ReCyF de l'ANSSI (NIS2 France) quand il est activé : mesures non couvertes, mesures
 *   mises en œuvre sans preuve jointe ; revues récurrentes (droits, comptes à privilèges, tests de
 *   restauration et de continuité).
 * - Lecture seule : rien n'est modifié ni enregistré. Les données sont lues dans les entités de la
 *   session, avec les droits de chaque module (sinon le critère est « hors de vos droits »).
 * - Visible seulement quand le référentiel NIS2 est activé dans la configuration du plugin.
 *
 * Sources : https://eur-lex.europa.eu/eli/dir/2022/2555/oj (directive), ReCyF de l'ANSSI
 * (RecyfCatalogDefaults::SOURCE_DOCUMENT) et https://messervices.cyber.gouv.fr/nis2 (inscription).
 */
class Nis2Readiness extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return __('NIS2 compliance path', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-route';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/nis2readiness.php';
    }

    public static function isFrameworkEnabled(): bool
    {
        return FrameworkConfig::isEnabled(FrameworkSelection::NIS2);
    }

    public static function canView(): bool
    {
        return self::isFrameworkEnabled() && (bool) Session::haveRight(self::RIGHTNAME, READ);
    }

    /** Sous-page de la rubrique « Pilotage » (Nis2ReadinessFeature), absente sans NIS2. */
    public static function getMenuContent()
    {
        if (!self::isFrameworkEnabled() || !self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    // ------------------------------------------------------------------ Libellés.

    /**
     * @return array<string, array{label: string, summary: string, icon: string}>
     */
    public static function getGroups(): array
    {
        return [
            'scope'      => [
                'label'   => __('Scope and registration', 'grcmanager'),
                'summary' => __(
                    'Know whether you are an essential or important entity, and make yourself known '
                        . 'to the authority.',
                    'grcmanager'
                ),
                'icon'    => 'ti-id-badge-2',
            ],
            'governance' => [
                'label'   => __('Governance', 'grcmanager'),
                'summary' => __(
                    'Management approves the security measures, oversees them and is trained.',
                    'grcmanager'
                ),
                'icon'    => 'ti-building-bank',
            ],
            'measures'   => [
                'label'   => __('Cybersecurity risk-management measures', 'grcmanager'),
                'summary' => __(
                    'The measures listed by the directive: risks, continuity, suppliers, checks, '
                        . 'access, MFA.',
                    'grcmanager'
                ),
                'icon'    => 'ti-shield-lock',
            ],
            'reporting'  => [
                'label'   => __('Incident reporting', 'grcmanager'),
                'summary' => __(
                    'Handle incidents and notify significant ones within 24 hours, 72 hours and one '
                        . 'month.',
                    'grcmanager'
                ),
                'icon'    => 'ti-alarm',
            ],
            'follow_up'  => [
                'label'   => __('Day-to-day compliance', 'grcmanager'),
                'summary' => __(
                    'Measures not covered yet, missing evidence and recurring actions to carry out.',
                    'grcmanager'
                ),
                'icon'    => 'ti-repeat',
            ],
        ];
    }

    /**
     * Titre et attendu de chaque critère, avec nos mots (pas le texte de la directive).
     *
     * @return array<string, array{title: string, expected: string, icon: string}>
     */
    public static function getCriterionTexts(): array
    {
        return [
            'category'       => [
                'title'    => __('Set your NIS2 entity category', 'grcmanager'),
                'expected' => __(
                    'Record for each GLPI entity concerned whether it is an essential or an important'
                        . ' entity: the ReCyF measures that apply depend on it.',
                    'grcmanager'
                ),
                'icon'     => 'ti-category',
            ],
            'registration'   => [
                'title'    => __('Register with the competent authority', 'grcmanager'),
                'expected' => __(
                    'Provide your information to the national authority (in France, on the NIS2 '
                        . 'services of the ANSSI), then record the authority or the CSIRT as an official '
                        . 'contact and check its details every year.',
                    'grcmanager'
                ),
                'icon'     => 'ti-building-community',
            ],
            'governance'     => [
                'title'    => __('Have management approve and oversee the measures', 'grcmanager'),
                'expected' => __(
                    'Have the security policies approved by management, keep their review up to date,'
                        . ' and name who is accountable for each security activity.',
                    'grcmanager'
                ),
                'icon'     => 'ti-file-certificate',
            ],
            'training'       => [
                'title'    => __('Train management and staff', 'grcmanager'),
                'expected' => __(
                    'Deliver cybersecurity training, including to the members of management, and '
                        . 'basic cyber hygiene awareness to staff.',
                    'grcmanager'
                ),
                'icon'     => 'ti-school',
            ],
            'risk'           => [
                'title'    => __('Analyse and treat the risks', 'grcmanager'),
                'expected' => __(
                    'Keep a risk register with owners and review dates, choose a treatment for each '
                        . 'significant risk and follow its actions.',
                    'grcmanager'
                ),
                'icon'     => 'ti-alert-triangle',
            ],
            'continuity'     => [
                'title'    => __('Prepare business continuity and crisis management', 'grcmanager'),
                'expected' => __(
                    'Have continuity and recovery plans, backups whose restoration is tested, and run'
                        . ' at least one crisis or continuity exercise a year.',
                    'grcmanager'
                ),
                'icon'     => 'ti-urgent',
            ],
            'supply_chain'   => [
                'title'    => __('Secure the supply chain', 'grcmanager'),
                'expected' => __(
                    'List the suppliers that matter for your security and evaluate each of them at '
                        . 'least once a year.',
                    'grcmanager'
                ),
                'icon'     => 'ti-truck-delivery',
            ],
            'effectiveness'  => [
                'title'    => __('Assess the effectiveness of the measures', 'grcmanager'),
                'expected' => __(
                    'Carry out audits of your measures and treat the nonconformities they find until '
                        . 'they are closed.',
                    'grcmanager'
                ),
                'icon'     => 'ti-clipboard-check',
            ],
            'access'         => [
                'title'    => __('Review the access rights', 'grcmanager'),
                'expected' => __(
                    'Run an access review campaign at least once a year and close it once every '
                        . 'account has been reviewed.',
                    'grcmanager'
                ),
                'icon'     => 'ti-user-check',
            ],
            'mfa'            => [
                'title'    => __('Use multi-factor authentication', 'grcmanager'),
                'expected' => __(
                    'Import the multi-factor authentication status of the accounts and protect every '
                        . 'account, administrators first.',
                    'grcmanager'
                ),
                'icon'     => 'ti-key',
            ],
            'incidents'      => [
                'title'    => __('Notify significant incidents on time', 'grcmanager'),
                'expected' => __(
                    'Mark the security incidents subject to NIS2, send the early warning within 24 '
                        . 'hours, the notification within 72 hours and the final report within one month, '
                        . 'and record the CSIRT to warn.',
                    'grcmanager'
                ),
                'icon'     => 'ti-alarm',
            ],
            'recyf_coverage' => [
                'title'    => __('Cover the ReCyF measures', 'grcmanager'),
                'expected' => __(
                    'For each ReCyF measure that applies to your category, say whether it applies and'
                        . ' set its implementation status, an owner and a due date.',
                    'grcmanager'
                ),
                'icon'     => 'ti-list-check',
            ],
            'recyf_evidence' => [
                'title'    => __('Attach the evidence of the ReCyF measures', 'grcmanager'),
                'expected' => __(
                    'Attach at least one document to each measure marked as implemented, so that you '
                        . 'can show it to the authority.',
                    'grcmanager'
                ),
                'icon'     => 'ti-paperclip',
            ],
            'recurring'      => [
                'title'    => __('Carry out the recurring actions', 'grcmanager'),
                'expected' => __(
                    'Enable the periodic reviews of access rights and privileged accounts, the backup'
                        . ' restore test and the continuity test, and treat their tickets on time.',
                    'grcmanager'
                ),
                'icon'     => 'ti-calendar-repeat',
            ],
        ];
    }

    // ------------------------------------------------------------------ Calcul.

    /**
     * Données de la page : critères, groupes et synthèse, au format de la préparation ISO 27001.
     *
     * @return array{steps: list<array<string, mixed>>, phases: list<array<string, mixed>>,
     *     summary: array<string, mixed>, recyf_enabled: bool}
     */
    public static function report(?string $today = null): array
    {
        $today ??= substr((string) (Session::getCurrentTime() ?? date('Y-m-d H:i:s')), 0, 10);
        $recyf = RecyfRequirement::isFrameworkEnabled();
        $evaluations = self::evaluate($today, Rules::criteria($recyf));
        $texts = self::getCriterionTexts();
        $styles = CertificationReadiness::getStatusStyles();
        $statuses = array_map(static fn (array $e): string => $e['status'], $evaluations);
        $next = Base::nextStep($statuses);

        $steps = [];
        $number = 0;
        foreach ($evaluations as $key => $evaluation) {
            $number++;
            $steps[] = [
                'key'      => $key,
                'number'   => $number,
                'phase'    => Rules::CRITERIA[$key]['group'],
                'articles' => Rules::CRITERIA[$key]['articles'],
                'title'    => $texts[$key]['title'],
                'expected' => $texts[$key]['expected'],
                'icon'     => $texts[$key]['icon'],
                'status'   => $evaluation['status'],
                'style'    => $styles[$evaluation['status']],
                'facts'    => $evaluation['facts'],
                'links'    => $evaluation['links'],
                'next'     => $key === $next,
            ];
        }

        $phases = [];
        foreach (self::getGroups() as $group => $label) {
            $groupSteps = array_values(array_filter($steps, static fn (array $s): bool => $s['phase'] === $group));
            if ($groupSteps === []) {
                continue;
            }
            $phases[] = $label + [
                'key'    => $group,
                'steps'  => $groupSteps,
                'counts' => Base::counts(array_column($groupSteps, 'status')),
            ];
        }

        $score = Base::score(array_values($statuses));
        $counts = Base::counts($statuses);
        $nextStep = null;
        foreach ($steps as $step) {
            if ($step['next']) {
                $nextStep = $step;
            }
        }

        return [
            'steps'         => $steps,
            'phases'        => $phases,
            'recyf_enabled' => $recyf,
            'summary'       => [
                'score'     => $score,
                'color'     => Base::color($score),
                'counts'    => $counts,
                'total'     => count($steps),
                'evaluated' => count($steps) - $counts[Base::STATUS_LOCKED],
                'next'      => $nextStep,
            ],
        ];
    }

    /**
     * @param list<string> $criteria critères retenus, dans l'ordre
     * @return array<string, array{status: string, facts: list<string>, links: list<array<string, string>>}>
     */
    private static function evaluate(string $today, array $criteria): array
    {
        $iso = CertificationReadiness::stepEvaluations($today);
        $since = date('Y-m-d', (int) strtotime($today . ' -' . Base::RECENT_MONTHS . ' months'));
        $recyf = in_array('recyf_coverage', $criteria, true) ? self::recyfFigures() : null;
        $authorities = self::authorityContacts($today);

        $result = [];
        foreach ($criteria as $key) {
            $result[$key] = match ($key) {
                'category'       => self::categoryCriterion(),
                'registration'   => self::registrationCriterion($authorities),
                'governance'     => self::isoCriterion($iso, ['policy', 'roles'], [
                    self::link(Policy::class, Policy::getSearchURL()),
                    self::link(ResponsibilityActivity::class, ResponsibilityActivity::getSearchURL()),
                ]),
                'training'       => self::isoCriterion($iso, ['awareness'], [
                    self::link(Training::class, Training::getSearchURL()),
                ]),
                'risk'           => self::isoCriterion($iso, ['risk_assessment', 'risk_treatment'], [
                    self::link(Risk::class, Risk::getSearchURL()),
                ]),
                'continuity'     => self::isoCriterion($iso, ['exercises'], [
                    self::link(CrisisExercise::class, CrisisExercise::getSearchURL()),
                    self::link(ContinuityPlan::class, ContinuityPlan::getSearchURL()),
                    self::link(BackupCoverage::class, BackupCoverage::getSearchURL()),
                ]),
                'supply_chain'   => self::supplyChainCriterion($since),
                'effectiveness'  => self::isoCriterion($iso, ['internal_audit', 'nonconformities'], [
                    self::link(Audit::class, Audit::getSearchURL()),
                    self::link(Nonconformity::class, Nonconformity::getSearchURL()),
                ]),
                'access'         => self::accessCriterion($since),
                'mfa'            => self::mfaCriterion(),
                'incidents'      => self::incidentsCriterion($authorities),
                'recyf_coverage' => self::recyfCoverageCriterion($recyf),
                'recyf_evidence' => self::recyfEvidenceCriterion($recyf),
                'recurring'      => self::recurringCriterion($today),
                default          => self::locked(),
            };
        }

        return $result;
    }

    /**
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function locked(): array
    {
        return ['status' => Base::STATUS_LOCKED, 'facts' => [], 'links' => []];
    }

    /**
     * Bouton vers l'écran de $page (classe ou clé de sous-page), avec son chemin dans le menu ;
     * null si la personne ne peut pas l'ouvrir.
     *
     * @return array{label: string, url: string, icon: string, path: string}|null
     */
    private static function link(string $page, string $url, string $label = ''): ?array
    {
        if (class_exists($page) && method_exists($page, 'canView') && !$page::canView()) {
            return null;
        }
        $title = self::menuTitle($page);

        return [
            'label' => $label !== '' ? $label : $title,
            'url'   => $url,
            'icon'  => 'ti-arrow-right',
            'path'  => self::menuPath($page),
        ];
    }

    /** Titre d'une page dans le menu : son entrée de menu, sinon son nom. */
    private static function menuTitle(string $page): string
    {
        if (class_exists($page)) {
            $menu = method_exists($page, 'getMenuContent') ? $page::getMenuContent() : null;
            if (is_array($menu) && !empty($menu['title'])) {
                return (string) $menu['title'];
            }

            return (string) $page::getTypeName(Session::getPluralNumber());
        }

        return (string) (Sections::page($page)['title'] ?? $page);
    }

    /**
     * Chemin d'une page dans le menu de GLPI : secteur, rubrique, puis la page si elle n'est pas
     * l'entrée de la rubrique (ex. « GRC & Conformité > Pilotage > Préparation »).
     */
    public static function menuPath(string $page): string
    {
        $place = Sections::locate($page);
        if ($place === null) {
            return Menu::getMenuName();
        }
        $sector = match ($place['sector']) {
            'helpdesk'   => __('Assistance'),
            'assets'     => __('Assets'),
            'management' => __('Management'),
            'tools'      => __('Tools'),
            default      => Menu::getMenuName(),
        };
        $section = Sections::sectionOf($place['parent']);
        $sectionTitle = $section !== null ? $section::title() : '';
        $parts = [$sector, $sectionTitle !== '' ? $sectionTitle : self::menuTitle($place['parent'])];
        if ($place['parent'] !== $page) {
            $parts[] = self::menuTitle($page);
        }

        return implode(' > ', $parts);
    }

    /**
     * Critère repris des étapes de la préparation ISO 27001, avec les écrans NIS2 à ouvrir.
     *
     * @param array<string, array{status: string, facts: list<string>, links: list<array<string, string>>}> $iso
     * @param list<string> $keys
     * @param list<array<string, string>|null> $links
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function isoCriterion(array $iso, array $keys, array $links): array
    {
        $statuses = [];
        $facts = [];
        foreach ($keys as $key) {
            $statuses[] = (string) ($iso[$key]['status'] ?? Base::STATUS_LOCKED);
            foreach ($iso[$key]['facts'] ?? [] as $fact) {
                $facts[] = $fact;
            }
        }
        $status = Rules::combine($statuses);

        return [
            'status' => $status,
            'facts'  => $status === Base::STATUS_LOCKED ? [] : $facts,
            'links'  => array_values(array_filter($links)),
        ];
    }

    /**
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function categoryCriterion(): array
    {
        if (!RecyfRequirement::canView()) {
            return self::locked();
        }
        $registers = RecyfRequirement::availableRegisters();
        $categories = RecyfRequirement::getCategories();
        $facts = [];
        foreach ($registers as $register) {
            $facts[] = sprintf('%1$s : %2$s', $register['name'], $categories[$register['category']] ?? '');
        }
        if ($facts === []) {
            $facts[] = __('No entity has a NIS2 category yet.', 'grcmanager');
        }
        $settings = SettingsLink::data(RecyfBlock::anchors()[0]);
        // La catégorie se règle dans la configuration : ce bouton d'abord, pour qui peut la modifier.
        $links = [];
        if (Config::canUpdate()) {
            $links[] = [
                'label' => __('Set the category', 'grcmanager'),
                'url'   => $settings['url'],
                'icon'  => 'ti-settings',
                'path'  => $settings['path'],
            ];
        }
        $links[] = self::link(RegulationSection::RECYF, RecyfRequirement::getSearchURL());

        return [
            'status' => Rules::category(count($registers)),
            'facts'  => $facts,
            'links'  => array_values(array_filter($links)),
        ];
    }

    /**
     * Contacts officiels « autorité » (CSIRT, autorité de contrôle ou régulateur sectoriel) des
     * entités de la session, et ceux vérifiés depuis moins de 12 mois ; null sans le droit.
     *
     * @return array{total: int, verified: int}|null
     */
    private static function authorityContacts(string $today): ?array
    {
        if (!OfficialContact::canView()) {
            return null;
        }
        $rows = OfficialContact::visibleContacts([
            'contact_category' => ['csirt', 'supervisory_authority', 'sector_regulator'],
        ]);
        $verified = 0;
        foreach ($rows as $row) {
            $date = isset($row['contact_verified_date']) ? (string) $row['contact_verified_date'] : null;
            if (OfficialContactRules::verificationStatus($date, $today) === OfficialContactRules::STATUS_OK) {
                $verified++;
            }
        }

        return ['total' => count($rows), 'verified' => $verified];
    }

    /**
     * @param array{total: int, verified: int}|null $authorities
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function registrationCriterion(?array $authorities): array
    {
        if ($authorities === null) {
            return self::locked();
        }
        $links = [self::link(OfficialContact::class, OfficialContact::getRegisterURL())];
        if (RecyfRequirement::isFrameworkEnabled()) {
            $links[] = [
                'label' => __('NIS2 services of the ANSSI', 'grcmanager'),
                'url'   => RecyfCatalogDefaults::SOURCE_PAGE,
                'icon'  => 'ti-external-link',
                'path'  => '',
            ];
        }

        return [
            'status' => Rules::registration($authorities['total'], $authorities['verified']),
            'facts'  => [
                sprintf(
                    __('Authorities and CSIRT recorded as official contacts: %d', 'grcmanager'),
                    $authorities['total']
                ),
                sprintf(__('Checked in the last 12 months: %d', 'grcmanager'), $authorities['verified']),
            ],
            'links'  => array_values(array_filter($links)),
        ];
    }

    /**
     * Critère des entités de la session pour un module qui en a une (comme la préparation ISO 27001).
     *
     * @param class-string<\CommonDBTM> $itemtype
     * @return list<array<mixed>>
     */
    private static function entityCriteria(string $itemtype): array
    {
        $item = new $itemtype();

        return $item->isEntityAssign()
            ? EntityPerimeter::criteria($itemtype::getTable(), $item->maybeRecursive())
            : [];
    }

    /**
     * Nombre de lignes d'un module dans les entités de la session.
     *
     * @param class-string<\CommonDBTM> $itemtype
     * @param array<mixed> $where
     */
    private static function countOf(string $itemtype, array $where = []): int
    {
        global $DB;

        return (int) ($DB->request([
            'COUNT' => 'c',
            'FROM'  => $itemtype::getTable(),
            'WHERE' => array_merge($where, self::entityCriteria($itemtype)),
        ])->current()['c'] ?? 0);
    }

    /**
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function supplyChainCriterion(string $since): array
    {
        global $DB;

        if (!SupplierRisk::canView()) {
            return self::locked();
        }
        $risks = self::countOf(SupplierRisk::class, ['NOT' => ['status' => 'closed']]);
        $table = SupplierQuestionnaireTables::EVALUATIONS;
        $evaluated = (int) ($DB->request([
            'SELECT' => ['COUNT DISTINCT' => SupplierQuestionnaireTables::RISK_FK . ' AS c'],
            'FROM'   => $table,
            'WHERE'  => array_merge(
                ['status' => 'completed', 'date_completed' => ['>=', $since]],
                EntityPerimeter::criteria($table, true)
            ),
        ])->current()['c'] ?? 0);

        return [
            'status' => Rules::supplyChain($risks, $evaluated),
            'facts'  => [
                sprintf(__('Suppliers followed in the supplier risks: %d', 'grcmanager'), $risks),
                sprintf(__('Suppliers evaluated in the last 12 months: %d', 'grcmanager'), $evaluated),
            ],
            'links'  => array_values(array_filter([self::link(SupplierRisk::class, SupplierRisk::getSearchURL())])),
        ];
    }

    /**
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function accessCriterion(string $since): array
    {
        if (!AccessReview::canView()) {
            return self::locked();
        }
        $campaigns = self::countOf(AccessReview::class, ['NOT' => ['status' => 'draft']]);
        $closed = self::countOf(AccessReview::class, ['status' => 'closed', 'date_close' => ['>=', $since]]);

        return [
            'status' => Rules::access($closed, $campaigns),
            'facts'  => [
                sprintf(__('Access review campaigns: %d', 'grcmanager'), $campaigns),
                sprintf(__('Closed in the last 12 months: %d', 'grcmanager'), $closed),
            ],
            'links'  => array_values(array_filter([self::link(AccessReview::class, AccessReview::getSearchURL())])),
        ];
    }

    /**
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function mfaCriterion(): array
    {
        if (!MfaCoverage::canView()) {
            return self::locked();
        }
        $summary = MfaCoverage::summary();
        $rate = $summary['all']['rate'] ?? null;
        $rate = $rate !== null ? (int) $rate : null;
        $fact = MfaCoverage::readinessFact();

        return [
            'status' => Rules::mfa($rate, (int) $summary['admins_without_mfa']),
            'facts'  => [$fact ?? __('No account imported yet.', 'grcmanager')],
            'links'  => array_values(array_filter([self::link(MfaCoverage::class, MfaCoverage::getSearchURL())])),
        ];
    }

    /**
     * @param array{total: int, verified: int}|null $authorities
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function incidentsCriterion(?array $authorities): array
    {
        if (!PluginGrcmanagerSecurityIncident::canView()) {
            return self::locked();
        }
        $incidents = SecurityIncidentCardProvider::regulatedOpenIncidents();
        $nis2 = array_values(array_filter($incidents, static fn (array $i): bool => !empty($i['nis2_applicable'])));
        $overdue = 0;
        $dueSoon = 0;
        foreach (SecurityIncidentCardProvider::pendingNotifications($nis2) as $row) {
            if (!str_starts_with($row['key'], 'nis2_')) {
                continue;
            }
            $overdue += $row['status'] === RegulatoryDeadlines::STATUS_OVERDUE ? 1 : 0;
            $dueSoon += $row['status'] === RegulatoryDeadlines::STATUS_DUE_SOON ? 1 : 0;
        }
        $known = $authorities !== null && $authorities['total'] > 0;
        $facts = [
            sprintf(__('Open incidents subject to NIS2: %d', 'grcmanager'), count($nis2)),
            sprintf(__('NIS2 notifications overdue: %d', 'grcmanager'), $overdue),
            sprintf(__('NIS2 notifications due soon: %d', 'grcmanager'), $dueSoon),
        ];
        if (!$known) {
            $facts[] = __('No CSIRT or authority recorded as an official contact.', 'grcmanager');
        }

        return [
            'status' => Rules::incidents($overdue, $dueSoon, $known),
            'facts'  => $facts,
            'links'  => array_values(array_filter([
                self::link(PluginGrcmanagerSecurityIncident::class, PluginGrcmanagerSecurityIncident::getSearchURL()),
                self::link(OfficialContact::class, OfficialContact::getRegisterURL()),
            ])),
        ];
    }

    /**
     * Mesures ReCyF des registres accessibles : applicables, mises en œuvre, et mises en œuvre sans
     * document joint ; null sans le droit.
     *
     * @return array{applicable: int, done: int, without_evidence: int, overdue: int, unassigned: int}|null
     */
    private static function recyfFigures(): ?array
    {
        global $DB;

        if (!RecyfRequirement::canView()) {
            return null;
        }
        $today = date('Y-m-d');
        $figures = ['applicable' => 0, 'done' => 0, 'without_evidence' => 0, 'overdue' => 0, 'unassigned' => 0];
        $doneIds = [];
        foreach (array_keys(RecyfRequirement::availableRegisters()) as $entityId) {
            $rows = RecyfRequirement::registerRows((int) $entityId);
            $summary = RecyfRules::summary($rows, $today);
            $figures['applicable'] += $summary['applicable'];
            $figures['done'] += $summary['done'];
            $figures['overdue'] += $summary['overdue'];
            $figures['unassigned'] += $summary['unassigned'];
            foreach ($rows as $row) {
                if (AcnRules::isApplicable($row) && AcnRules::isDone($row)) {
                    $doneIds[] = (int) $row['id'];
                }
            }
        }
        if ($doneIds !== []) {
            $withDocument = [];
            foreach (
                $DB->request([
                    'SELECT'   => ['items_id'],
                    'DISTINCT' => true,
                    'FROM'     => Document_Item::getTable(),
                    'WHERE'    => ['itemtype' => RecyfRequirement::class, 'items_id' => $doneIds],
                ]) as $row
            ) {
                $withDocument[] = (int) $row['items_id'];
            }
            $figures['without_evidence'] = count(array_diff($doneIds, $withDocument));
        }

        return $figures;
    }

    /**
     * @param array{applicable: int, done: int, without_evidence: int, overdue: int, unassigned: int}|null $recyf
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function recyfCoverageCriterion(?array $recyf): array
    {
        if ($recyf === null) {
            return self::locked();
        }

        return [
            'status' => Rules::recyfCoverage($recyf['applicable'], $recyf['done']),
            'facts'  => [
                sprintf(
                    __('Applicable measures implemented: %1$d of %2$d', 'grcmanager'),
                    $recyf['done'],
                    $recyf['applicable']
                ),
                sprintf(
                    __('Measures not covered yet: %d', 'grcmanager'),
                    max(0, $recyf['applicable'] - $recyf['done'])
                ),
                sprintf(__('Measures past their due date: %d', 'grcmanager'), $recyf['overdue']),
                sprintf(__('Measures without an owner: %d', 'grcmanager'), $recyf['unassigned']),
            ],
            'links'  => array_values(array_filter([
                self::link(RegulationSection::RECYF, RecyfRequirement::getSearchURL()),
            ])),
        ];
    }

    /**
     * @param array{applicable: int, done: int, without_evidence: int, overdue: int, unassigned: int}|null $recyf
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function recyfEvidenceCriterion(?array $recyf): array
    {
        if ($recyf === null) {
            return self::locked();
        }

        return [
            'status' => Rules::recyfEvidence($recyf['done'], $recyf['without_evidence']),
            'facts'  => [
                sprintf(
                    __('Implemented measures without any document attached: %d', 'grcmanager'),
                    $recyf['without_evidence']
                ),
            ],
            'links'  => array_values(array_filter([
                self::link(RegulationSection::RECYF, RecyfRequirement::getSearchURL()),
            ])),
        ];
    }

    /**
     * @return array{status: string, facts: list<string>, links: list<array<string, string>>}
     */
    private static function recurringCriterion(string $today): array
    {
        if (!PeriodicReview::canView()) {
            return self::locked();
        }
        $active = 0;
        $overdue = 0;
        $facts = [];
        foreach (Rules::RECURRING_REVIEWS as $key) {
            $review = PeriodicReview::getByKey($key);
            $label = PeriodicReview::getLabel($key);
            if ($review === null || !$review->isActive()) {
                $facts[] = sprintf(__('%s: not enabled', 'grcmanager'), $label);
                continue;
            }
            $active++;
            if ($review->isOverdue($today)) {
                $overdue++;
                $facts[] = sprintf(__('%s: overdue', 'grcmanager'), $label);
            } else {
                $facts[] = sprintf(__('%s: up to date', 'grcmanager'), $label);
            }
        }

        return [
            'status' => Rules::recurring(count(Rules::RECURRING_REVIEWS), $active, $overdue),
            'facts'  => $facts,
            'links'  => array_values(array_filter([
                self::link(PeriodicReview::class, PeriodicReview::getSearchURL()),
            ])),
        ];
    }
}

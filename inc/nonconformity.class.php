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

use GlpiPlugin\Grcmanager\Compatibility\Base\NonconformityBase;
use GlpiPlugin\Grcmanager\Services\Capa\CapaRequirementService;
use GlpiPlugin\Grcmanager\Services\Capa\CveCapaRules;
use GlpiPlugin\Grcmanager\Services\Capa\OverdueCapaService;
use GlpiPlugin\Grcmanager\Services\Cve\InventoryCveMatcher;
use GlpiPlugin\Grcmanager\Services\Cve\NvdCveEnrichmentService;
use GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;

/**
 * A finding (non-conformity or observation) raised during an internal audit
 * (PluginGrcmanagerAudit), carrying the full corrective/preventive action (CAPA) workflow ISO
 * 27001 clause 10.2 requires: root cause, corrective action, preventive action, a responsible
 * owner, a due date, and a closure verification date once the action is confirmed effective.
 *
 * `finding_type` and `severity` are two independent axes, matching ISO 19011 audit vocabulary
 * (issue #27, résout TECH_DEBT.md Sprint 4) : `finding_type` distinguishes a genuine non-conformity
 * (a real gap against a requirement, CAPA mandatory to close/verify, see
 * CapaRequirementService::isCapaMandatory()) from a lesser observation/remark (CAPA optional,
 * still trackable through the exact same workflow), while `severity` (minor/major/critical) still
 * grades either one — a single overlapping ordinal scale was kept for these two axes in the first
 * version, this is what issue #27 resolves.
 */
class PluginGrcmanagerNonconformity extends NonconformityBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /**
     * GLPI notification event name (see inc/notificationtargetnonconformity.class.php and
     * src/Services/Capa/OverdueCapaService.php), same single-source-of-truth pattern as
     * PluginGrcmanagerRisk::REVIEW_DUE_EVENT.
     */
    public const CAPA_OVERDUE_EVENT = 'capa_overdue';

    /**
     * A non-conformity counts as "closed" (no longer overdue-eligible) once it reaches either of
     * these statuses, shared here so the dashboard card, the overdue Cron and the form validation
     * below never drift apart on the definition.
     *
     * @var array<int, string>
     */
    public const CLOSED_STATUSES = ['closed', 'verified'];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_nonconformities';
    }

    /**
     * Le titre tient lieu de nom : sans cela, l'en-tête de la fiche et les listes déroulantes
     * affichaient « N/A » (même correctif que PluginGrcmanagerPolicy::getNameField()).
     */
    public static function getNameField()
    {
        return 'title';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Non-conformité', 'Non-conformités', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-alert-hexagon';
    }

    /**
     * @return array<string, string>
     */
    public static function getSeverities(): array
    {
        return [
            'minor'    => __('Mineure', 'grcmanager'),
            'major'    => __('Majeure', 'grcmanager'),
            'critical' => __('Critique', 'grcmanager'),
        ];
    }

    /**
     * ISO 19011 vocabulary (issue #27, see class docblock): a genuine non-conformity vs. a lesser
     * observation/remark, independent from the `severity` axis.
     *
     * @return array<string, string>
     */
    public static function getFindingTypes(): array
    {
        return [
            // Reuses the existing entity type name translation rather than a second, colliding
            // gettext entry for the exact same singular French string "Non-conformité" (already
            // registered as a plural-form msgid via getTypeName()/_n()).
            'nonconformity' => self::getTypeName(1),
            'observation'   => __('Observation / remarque', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            'open'        => __('Ouverte', 'grcmanager'),
            'in_progress' => __('En traitement', 'grcmanager'),
            'closed'      => __('Clôturée', 'grcmanager'),
            'verified'    => __('Vérifiée', 'grcmanager'),
        ];
    }

    /**
     * Enforces ISO 27001 clause 10.2 in practice: a genuine non-conformity (`finding_type` =
     * 'nonconformity', see CapaRequirementService) cannot be marked closed or verified without a
     * documented corrective action ; a mere observation is exempt from this (issue #27), a
     * corrective action stays optional for it even at closure/verification. Reaching "verified"
     * without an explicit closure verification date auto-stamps it with today rather than silently
     * leaving it blank (same "auto-populate a real date rather than force an extra manual step"
     * choice as PluginGrcmanagerAudit's own `actual_date`).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        return $this->validateAndNormalize($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        return $this->validateAndNormalize($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function validateAndNormalize(array $input)
    {
        $status            = (string) ($input['status'] ?? ($this->fields['status'] ?? 'open'));
        $findingType       = (string) (
            $input['finding_type'] ?? ($this->fields['finding_type'] ?? 'nonconformity')
        );
        $correctiveAction  = trim((string) (
            $input['corrective_action'] ?? ($this->fields['corrective_action'] ?? '')
        ));

        $capaMandatory = CapaRequirementService::isCapaMandatory($findingType);

        if ($capaMandatory && in_array($status, self::CLOSED_STATUSES, true) && $correctiveAction === '') {
            Session::addMessageAfterRedirect(
                __(
                    'Une action corrective est obligatoire pour clôturer ou vérifier une non-conformité.',
                    'grcmanager'
                ),
                false,
                ERROR
            );

            return false;
        }

        // Issue #163 : origine incident/CVE, jamais une valeur libre.
        if (isset($input['plugin_grcmanager_securityincidents_id'])) {
            $input['plugin_grcmanager_securityincidents_id'] = max(
                0,
                (int) $input['plugin_grcmanager_securityincidents_id']
            );
        }
        if (isset($input['cve_id'])) {
            $cve = strtoupper(trim((string) $input['cve_id']));
            $input['cve_id'] = preg_match('/^CVE-\d{4}-\d{4,}$/', $cve) === 1 ? $cve : '';
        }

        $verificationDate = $input['closure_verification_date']
            ?? ($this->fields['closure_verification_date'] ?? null);

        if ($status === 'verified' && empty($verificationDate)) {
            $input['closure_verification_date'] = date('Y-m-d');
        }

        return $input;
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(1),
        ];

        $tab[] = [
            'id'       => 1,
            'table'    => $this->getTable(),
            'field'    => 'title',
            'name'     => __('Titre', 'grcmanager'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
        ];

        // id 13: fresh id (max pre-existing id in this list was 12), issue #27, never reuse an id
        // already assigned above/below, saved searches and glpi_displaypreferences persist them.
        $tab[] = [
            'id'       => 13,
            'table'    => $this->getTable(),
            'field'    => 'finding_type',
            'name'     => __('Type de constat', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 2,
            'table'    => $this->getTable(),
            'field'    => 'severity',
            'name'     => __('Sévérité', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'status',
            'name'     => __('Statut', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => __('Responsable', 'grcmanager'),
            'datatype' => 'dropdown',
            'right'    => 'all',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'due_date',
            'name'     => __('Échéance', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'closure_verification_date',
            'name'     => __('Date de vérification de clôture', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'plugin_grcmanager_audits_id',
            'name'     => PluginGrcmanagerAudit::getTypeName(1),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 8,
            'table'    => $this->getTable(),
            'field'    => 'description',
            'name'     => __('Description', 'grcmanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 9,
            'table'    => $this->getTable(),
            'field'    => 'root_cause',
            'name'     => __('Cause racine', 'grcmanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 10,
            'table'    => $this->getTable(),
            'field'    => 'corrective_action',
            'name'     => __('Action corrective', 'grcmanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'       => 11,
            'table'    => $this->getTable(),
            'field'    => 'preventive_action',
            'name'     => __('Action préventive', 'grcmanager'),
            'datatype' => 'text',
        ];

        // Row link, same "a list with no way back to showForm() is not self-explanatory" lesson
        // already applied throughout this plugin family.
        $tab[] = [
            'id'       => 12,
            'table'    => $this->getTable(),
            'field'    => 'id',
            'name'     => __('ID'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        switch ($field) {
            case 'finding_type':
                return self::findingTypeBadge($values[$field] ?? null);

            case 'severity':
                return self::severityBadge($values[$field] ?? null);

            case 'status':
                return self::statusBadge($values[$field] ?? null);

            case 'plugin_grcmanager_audits_id':
                return self::auditLink((int) ($values[$field] ?? 0));
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Real `<select>` filter widgets for severity/status, same lesson as
     * PluginGrcmanagerRisk::getSpecificValueToSelect(). `plugin_grcmanager_audits_id` is left to
     * the parent's default (free-text search on the raw numeric ID): looking it up through a real
     * GLPI dropdown join was judged not worth the added complexity for a first version, see
     * TECH_DEBT.md Sprint 4.
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        $options['display'] = false;
        $options['name']    = $name;
        $options['value']   = $values[$field] ?? '';

        switch ($field) {
            case 'finding_type':
                return Dropdown::showFromArray($name, self::getFindingTypes(), $options);

            case 'severity':
                return Dropdown::showFromArray($name, self::getSeverities(), $options);

            case 'status':
                return Dropdown::showFromArray($name, self::getStatuses(), $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    private static function findingTypeBadge(?string $value): string
    {
        $map = [
            'nonconformity' => ['bg-red-lt', 'ti-alert-hexagon', self::getTypeName(1)],
            'observation'   => ['bg-azure-lt', 'ti-notes', __('Observation / remarque', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }

    private static function severityBadge(?string $value): string
    {
        $map = [
            'minor'    => ['bg-yellow-lt', 'ti-alert-circle', __('Mineure', 'grcmanager')],
            'major'    => ['bg-orange-lt', 'ti-alert-triangle', __('Majeure', 'grcmanager')],
            'critical' => ['bg-red-lt', 'ti-alert-octagon', __('Critique', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }

    private static function statusBadge(?string $value): string
    {
        $map = [
            'open'        => ['bg-red-lt', 'ti-flag', __('Ouverte', 'grcmanager')],
            'in_progress' => ['bg-blue-lt', 'ti-tool', __('En traitement', 'grcmanager')],
            'closed'      => ['bg-dark-lt', 'ti-check', __('Clôturée', 'grcmanager')],
            'verified'    => ['bg-green-lt', 'ti-shield-check', __('Vérifiée', 'grcmanager')],
        ];

        [$class, $icon, $label] = $map[$value] ?? ['bg-secondary-lt', 'ti-help', (string) $value];

        return Badge::html($class, $icon, $label);
    }

    private static function auditLink(int $auditId): string
    {
        if ($auditId <= 0) {
            return '';
        }

        $title = self::getAuditTitle($auditId);

        if ($title === '') {
            return '';
        }

        return '<a href="' . htmlescape(PluginGrcmanagerAudit::getFormURLWithID($auditId)) . '">'
            . htmlescape($title) . '</a>';
    }

    /**
     * Direct-lookup helper, same pattern as PluginGrcmanagerControl::getLinkedRisks() (a real
     * CommonDBTM::getFromDB() would work too, but this avoids instantiating a whole object just to
     * read one column).
     */
    public static function getAuditTitle(int $auditId): string
    {
        global $DB;

        $rows = $DB->request([
            'SELECT' => ['title'],
            'FROM'   => PluginGrcmanagerAudit::getTable(),
            'WHERE'  => ['id' => $auditId],
        ]);

        foreach ($rows as $row) {
            return (string) $row['title'];
        }

        return '';
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        // Issue #140 : preuves du constat (captures, extraits, rapport de vérification).
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(Document_Item::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    /**
     * Issue #140 : onglet « Constats » de la fiche d'un audit.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof PluginGrcmanagerAudit || $item->isNewItem() || !self::canView()) {
            return '';
        }
        $count = countElementsInTable(self::getTable(), ['plugin_grcmanager_audits_id' => $item->getID()]);

        return self::createTabEntry(__('Constats', 'grcmanager'), $count, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof PluginGrcmanagerAudit) {
            return false;
        }
        $findings = PluginGrcmanagerAudit::getFindings($item->getID());
        foreach ($findings as &$finding) {
            $finding['url'] = self::getFormURLWithID($finding['id']);
        }
        unset($finding);
        TemplateRenderer::getInstance()->display('@grcmanager/audit_findings_tab.html.twig', [
            'findings' => $findings,
            'add_url'  => self::canCreate() ? self::getFormURL() . '?audit=' . $item->getID() : '',
        ]);

        return true;
    }

    public function showForm($ID, array $options = []): bool
    {
        global $DB;

        $this->initForm($ID, $options);
        // Issue #163 : « Proposer un plan d'action » depuis l'onglet CVE d'un incident pré-remplit
        // une nouvelle fiche, relue avant tout enregistrement.
        if ($this->isNewItem() && (int) ($options['from_incident'] ?? 0) > 0) {
            $this->fields = self::presetFromCve(
                (int) $options['from_incident'],
                (string) ($options['cve'] ?? '')
            ) + $this->fields;
        }
        // Issue #140 : « Ajouter un constat » depuis l'onglet Constats d'un audit.
        if ($this->isNewItem() && (int) ($options['audit'] ?? 0) > 0) {
            $this->fields['plugin_grcmanager_audits_id'] = (int) $options['audit'];
        }

        $auditTitles = [0 => Dropdown::EMPTY_VALUE];
        foreach ($DB->request(['SELECT' => ['id', 'title'], 'FROM' => PluginGrcmanagerAudit::getTable()]) as $row) {
            $auditTitles[(int) $row['id']] = $row['title'];
        }
        $incident = null;
        $incidentId = (int) ($this->fields['plugin_grcmanager_securityincidents_id'] ?? 0);
        $source = new PluginGrcmanagerSecurityIncident();
        if ($incidentId > 0 && $source->getFromDB($incidentId) && $source->canViewItem()) {
            $incident = [
                'id'   => $incidentId,
                'name' => (string) $source->fields['name'],
                'url'  => PluginGrcmanagerSecurityIncident::getFormURLWithID($incidentId),
            ];
        }

        // Issue #27 : le hint reflete la meme regle que CapaRequirementService::isCapaMandatory()
        // cote serveur, jamais un second endroit ou cette regle pourrait diverger.
        $findingType = (string) ($this->fields['finding_type'] ?? 'nonconformity');
        TemplateRenderer::getInstance()->display('@grcmanager/nonconformity_form.html.twig', [
            'item'            => $this,
            'params'          => $options,
            'incident'        => $incident,
            'finding_types'   => self::getFindingTypes(),
            'severities'      => self::getSeverities(),
            'statuses'        => self::getStatuses(),
            'audits'          => $auditTitles,
            'audit_label'     => PluginGrcmanagerAudit::getTypeName(1),
            'corrective_hint' => CapaRequirementService::isCapaMandatory($findingType)
                ? __('Obligatoire pour clôturer ou vérifier cette non-conformité.', 'grcmanager')
                : __('Optionnelle pour une observation : peut néanmoins être renseignée si besoin.', 'grcmanager'),
        ]);

        return true;
    }

    /**
     * Issue #163 : non-conformité déjà ouverte pour cette CVE de cet incident (0 sinon).
     */
    public static function findForIncidentCve(int $incidentId, string $cveId): int
    {
        $found = (new self())->find([
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'cve_id'                                 => $cveId,
        ], ['id DESC'], 1);

        return $found === [] ? 0 : (int) array_key_first($found);
    }

    /**
     * Issue #163 : plan d'action proposé pour une CVE d'un incident, à partir de NVD (correctifs,
     * avis de l'éditeur), du catalogue CISA KEV et des actifs de l'inventaire qui la portent.
     * Vide si l'incident n'est pas visible ou si la CVE ne lui est pas rattachée.
     *
     * @return array<string, mixed>
     */
    public static function presetFromCve(int $incidentId, string $cveId): array
    {
        $incident = new PluginGrcmanagerSecurityIncident();
        if (
            !$incident->getFromDB($incidentId) || !$incident->canViewItem()
            || countElementsInTable(PluginGrcmanagerSecurityIncidentCve::getTable(), [
                'plugin_grcmanager_securityincidents_id' => $incidentId,
                'cve_id'                                 => $cveId,
            ]) === 0
        ) {
            return [];
        }
        $enrichment = NvdCveEnrichmentService::getForCve($cveId);
        $signal = ParkVulnerabilitySync::signalsFor([$cveId])[$cveId] ?? null;
        $cvss = $enrichment['cvss_score'] ?? null;
        $isKev = (bool) ($signal['is_kev'] ?? false);
        $kevDue = (string) ($signal['kev_due'] ?? '');
        $severity = CveCapaRules::severity($cvss, $isKev);
        $links = CveCapaRules::groupLinks($enrichment['patch_links'] ?? []);
        $assets = $enrichment !== null && $enrichment['affected_cpes'] !== []
            ? InventoryCveMatcher::findMatchingAssets($enrichment['affected_cpes'])
            : [];

        $corrective = [];
        foreach ($links['patch'] as $url) {
            $corrective[] = sprintf(__('Appliquer le correctif de l\'éditeur : %s', 'grcmanager'), $url);
        }
        if ($links['patch'] === []) {
            $corrective[] = __(
                'Rechercher et appliquer le correctif de l\'éditeur (aucun lien de correctif publié dans NVD '
                    . 'pour l\'instant).',
                'grcmanager'
            );
        }
        foreach ($links['advisory'] as $url) {
            $corrective[] = sprintf(__('Suivre l\'avis de sécurité de l\'éditeur : %s', 'grcmanager'), $url);
        }
        if ($assets !== []) {
            $names = array_values(array_unique(array_column($assets, 'item_name')));
            $shown = array_slice($names, 0, 20);
            $corrective[] = sprintf(
                __('Mettre à jour les actifs concernés (%1$d) : %2$s', 'grcmanager'),
                count($names),
                implode(', ', $shown) . (count($names) > 20 ? ' (…)' : '')
            );
        } else {
            $corrective[] = __(
                'Identifier les actifs concernés (aucun actif correspondant trouvé dans l\'inventaire).',
                'grcmanager'
            );
        }
        if ($isKev && $kevDue !== '') {
            $corrective[] = sprintf(
                __(
                    'Vulnérabilité activement exploitée : échéance de correction fixée par la CISA au %s.',
                    'grcmanager'
                ),
                Html::convDate($kevDue)
            );
        }
        $corrective[] = __(
            'Contrôler l\'efficacité : vérifier que la vulnérabilité n\'apparaît plus sur les actifs (onglet CVE de '
                . 'l\'incident ou « Vulnérabilités du parc »).',
            'grcmanager'
        );

        $description = sprintf(
            __('Plan d\'action proposé pour %1$s, relevée sur l\'incident de sécurité « %2$s ».', 'grcmanager'),
            $cveId,
            $incident->fields['name']
        );
        if ($cvss !== null) {
            $description .= ' CVSS ' . $cvss . '.';
        }
        if (($enrichment['description'] ?? '') !== '') {
            $description .= "\n\n" . mb_substr((string) $enrichment['description'], 0, 600);
        }

        return [
            'title'                                  => mb_substr(sprintf(
                __('Corriger %1$s (incident « %2$s »)', 'grcmanager'),
                $cveId,
                $incident->fields['name']
            ), 0, 255),
            'finding_type'                           => 'nonconformity',
            'severity'                               => $severity,
            'status'                                 => 'open',
            'due_date'                               => CveCapaRules::dueDate($severity, $kevDue, date('Y-m-d')),
            'description'                            => $description,
            'corrective_action'                      => implode("\n", array_map(
                static fn (string $line, int $i): string => ($i + 1) . '. ' . $line,
                $corrective,
                array_keys($corrective)
            )),
            'preventive_action'                      => implode("\n", [
                '- ' . __(
                    'Surveiller le logiciel concerné dans « Logiciels à surveiller » pour être alerté des '
                        . 'prochaines vulnérabilités.',
                    'grcmanager'
                ),
                '- ' . __('Intégrer ce logiciel au cycle régulier d\'application des correctifs.', 'grcmanager'),
            ]),
            'plugin_grcmanager_securityincidents_id' => $incidentId,
            'cve_id'                                 => $cveId,
        ];
    }

    /**
     * GLPI Cron entry point, same structure as PluginGrcmanagerRisk::cronReviewreminder().
     * Notifies the responsible owner of every non-conformity whose due date has passed and that
     * is not yet closed/verified (see OverdueCapaService, inc/notificationtargetnonconformity.class.php).
     *
     * @return int 0 if no CAPA was overdue, 1 otherwise
     */
    public static function cronOverduecapa(CronTask $task): int
    {
        $result = (new OverdueCapaService())->notify();

        $task->addVolume($result->getNotified());
        $task->log(sprintf(
            '%d action(s) corrective(s)/préventive(s) en retard, %d notification(s) déclenchée(s).',
            $result->getDue(),
            $result->getNotified()
        ));

        return $result->getDue() > 0 ? 1 : 0;
    }
}

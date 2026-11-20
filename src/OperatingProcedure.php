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
use Config;
use Document;
use Dropdown;
use Document_Item;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Policy\OperatingProcedureRules as Rules;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalConfig;
use Html;
use Session;

/**
 * Issue #775 : procédures d'exploitation documentées (ISO 27001, A.5.37).
 *
 * Pas d'objet ni de table à part : une procédure est une politique de la bibliothèque (Policy) de
 * catégorie « procédure d'exploitation », avec son domaine. Elle garde donc tout ce que la
 * bibliothèque offre déjà : version, responsable, date d'approbation, circuit d'approbation,
 * prochaine revue et son rappel, documents joints, prise de connaissance par un public (groupes ou
 * profils qui exécutent la procédure). Cette classe n'en est que la vue :
 * - le registre et sa couverture par domaine (domaines attendus réglables) ;
 * - le registre en PDF, à la demande ou archivé sur la mesure A.5.37 ;
 * - la preuve affichée sur la mesure A.5.37 de la SoA.
 *
 * Droits : ceux de la bibliothèque des politiques (droit général du plugin), partout. Les
 * politiques sont globales (pas d'entité) ; seule la mesure A.5.37 est celle de la SoA suivie.
 */
class OperatingProcedure extends CommonGLPI
{
    public const CONTROL_CODE = 'A.5.37';
    public const CONFIG_KEY = 'operating_procedure_domains';

    public static function getTypeName($nb = 0)
    {
        return _n('Operating procedure', 'Operating procedures', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    public static function canView(): bool
    {
        return Policy::canView();
    }

    public static function getRegisterURL(bool $full = true): string
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/operatingprocedure.php';
    }

    public static function getExportURL(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/operatingprocedure.export.php';
    }

    /**
     * Sous-page de la rubrique « Conformité » (Menu::sections()), avec les droits des politiques.
     *
     * @return array<string, mixed>|false
     */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(Session::getPluralNumber()),
            'page'  => self::getRegisterURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    // ------------------------------------------------------------------
    // Domaines attendus (réglables dans la configuration du plugin)
    // ------------------------------------------------------------------

    /**
     * Libellés des domaines proposés par défaut.
     *
     * @return array<string, string>
     */
    public static function defaultDomainLabels(): array
    {
        return [
            'backup'              => __('Backup', 'grcmanager'),
            'restore'             => __('Restore', 'grcmanager'),
            'monitoring'          => __('Monitoring', 'grcmanager'),
            'logging'             => __('Logs', 'grcmanager'),
            'updates'             => __('Updates', 'grcmanager'),
            'deployment'          => __('Deployment', 'grcmanager'),
            'access_management'   => __('Access management', 'grcmanager'),
            'incident_management' => __('Incident management', 'grcmanager'),
            'change_management'   => __('Changes', 'grcmanager'),
            'maintenance'         => __('Maintenance', 'grcmanager'),
        ];
    }

    /** Libellé d'un domaine : traduit pour un domaine par défaut, tel quel sinon. */
    public static function domainLabel(string $domain): string
    {
        return self::defaultDomainLabels()[$domain] ?? $domain;
    }

    /**
     * Domaines attendus, dans l'ordre réglé ; la liste par défaut tant que rien n'est réglé.
     *
     * @return list<string>
     */
    public static function domains(): array
    {
        $values = Config::getConfigurationValues(PolicyApprovalConfig::CONTEXT, [self::CONFIG_KEY]);
        $raw = $values[self::CONFIG_KEY] ?? null;
        if ($raw === null || $raw === '') {
            return Rules::DEFAULT_DOMAINS;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? Rules::sanitizeDomains($decoded) : Rules::DEFAULT_DOMAINS;
    }

    /**
     * @param list<string> $domains
     */
    public static function saveDomains(array $domains): void
    {
        $domains = Rules::sanitizeDomains($domains);
        Config::setConfigurationValues(PolicyApprovalConfig::CONTEXT, [
            self::CONFIG_KEY => (string) json_encode($domains),
        ]);
        AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'changes' => [self::CONFIG_KEY => $domains],
        ]);
    }

    /**
     * Choix du domaine sur la fiche d'une procédure : domaines attendus, plus le domaine actuel s'il
     * n'est plus attendu (il n'est jamais perdu en silence).
     *
     * @return array<string, string>
     */
    public static function domainChoices(string $current = ''): array
    {
        $choices = ['' => Dropdown::EMPTY_VALUE];
        foreach (self::domains() as $domain) {
            $choices[$domain] = self::domainLabel($domain);
        }
        if ($current !== '' && !isset($choices[$current])) {
            $choices[$current] = self::domainLabel($current);
        }

        return $choices;
    }

    /**
     * Données du réglage pour le formulaire de configuration.
     *
     * @return array{defaults: list<array{key: string, label: string, checked: bool}>, others: string}
     */
    public static function configForm(): array
    {
        $domains = self::domains();
        $defaults = [];
        foreach (self::defaultDomainLabels() as $key => $label) {
            $defaults[] = ['key' => $key, 'label' => $label, 'checked' => in_array($key, $domains, true)];
        }

        return ['defaults' => $defaults, 'others' => implode("\n", Rules::customDomains($domains))];
    }

    // ------------------------------------------------------------------
    // Registre et couverture
    // ------------------------------------------------------------------

    /**
     * Procédures d'exploitation de la bibliothèque, par domaine puis titre.
     *
     * @return list<array<string, mixed>>
     */
    public static function procedures(): array
    {
        global $DB;

        if (!self::canView() || !$DB->fieldExists(Policy::getTable(), 'category')) {
            return [];
        }
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => Policy::getTable(),
                'WHERE' => ['category' => Rules::CATEGORY_PROCEDURE],
                'ORDER' => ['procedure_domain', 'title'],
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Ligne affichable d'une procédure (registre, PDF).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row, string $today): array
    {
        $policyId = (int) $row['id'];
        $campaign = PolicyAcknowledgement::getCurrentCampaign($policyId);
        $progress = $campaign === null ? null : PolicyAcknowledgement::summarize($campaign)['progress'];
        $statuses = Policy::getStatuses();

        return [
            'id'            => $policyId,
            'url'           => Policy::getFormURLWithID($policyId),
            'title'         => (string) $row['title'],
            'domain'        => self::domainLabel((string) $row['procedure_domain']),
            'version'       => (string) $row['version'],
            'policy_status' => $statuses[(string) $row['status']] ?? (string) $row['status'],
            'status'        => Rules::procedureStatus(
                (string) $row['status'],
                $row['next_review_date'] ?? null,
                $today
            ),
            'approval_date' => (string) ($row['approval_date'] ?? ''),
            'next_review'   => (string) ($row['next_review_date'] ?? ''),
            'owner'         => (int) $row['users_id'] > 0 ? (string) getUserName((int) $row['users_id']) : '',
            'ack_version'   => $campaign === null ? '' : (string) $campaign['version'],
            'ack_rate'      => $progress === null ? null : $progress['rate'],
            'ack_done'      => $progress === null ? 0 : $progress['done'],
            'ack_total'     => $progress === null ? 0 : $progress['total'],
        ];
    }

    /**
     * Couverture des domaines attendus, avec les libellés et le lien d'ajout d'une procédure.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{domains: list<array<string, mixed>>, summary: array<string, int>}
     */
    public static function coverage(array $rows, string $today): array
    {
        $coverage = Rules::coverage(self::domains(), array_map(static fn (array $row): array => [
            'procedure_domain' => (string) $row['procedure_domain'],
            'status'           => (string) $row['status'],
            'next_review_date' => $row['next_review_date'] ?? null,
        ], $rows), $today);
        foreach ($coverage['domains'] as &$domain) {
            $domain['label'] = self::domainLabel($domain['domain']);
            $domain['add_url'] = self::addURL($domain['domain']);
        }
        unset($domain);

        return $coverage;
    }

    /** Fiche d'une nouvelle procédure, catégorie et domaine déjà choisis. */
    public static function addURL(string $domain = ''): string
    {
        return Policy::getFormURL() . '?' . http_build_query([
            'category'         => Rules::CATEGORY_PROCEDURE,
            'procedure_domain' => $domain,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function presentAll(array $rows, string $today): array
    {
        return array_map(static fn (array $row): array => self::present($row, $today), $rows);
    }

    /** Page du registre : couverture par domaine, boutons, liste des procédures. */
    public static function showRegister(): void
    {
        global $CFG_GLPI;

        $today = date('Y-m-d');
        $rows = self::procedures();
        TemplateRenderer::getInstance()->display('@grcmanager/operating_procedure_register.html.twig', [
            'coverage'    => self::coverage($rows, $today),
            'procedures'  => self::presentAll($rows, $today),
            'can_create'  => Policy::canCreate(),
            'can_archive' => Policy::canUpdate(),
            'add_url'     => self::addURL(),
            'export_url'  => self::getExportURL(),
            'register_url' => self::getRegisterURL(),
            'config_url'  => $CFG_GLPI['root_doc']
                . '/plugins/grcmanager/front/config.php#grc-operating-procedures-config',
            'can_config'  => Session::haveRight(Install\Installer::RIGHT_NAME, UPDATE),
            'control'     => self::CONTROL_CODE . ' ' . Control::getControlTitle(self::CONTROL_CODE),
        ]);
    }

    /** Registre en PDF (HTML pour PdfRenderer). */
    public static function reportHtml(): string
    {
        $today = date('Y-m-d');
        $rows = self::procedures();

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/operating_procedures_report.html.twig', [
            'report_title'       => __('Register of operating procedures', 'grcmanager'),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'control'            => self::CONTROL_CODE . ' ' . Control::getControlTitle(self::CONTROL_CODE),
            'coverage'           => self::coverage($rows, $today),
            'procedures'         => self::presentAll($rows, $today),
        ]);
    }

    /**
     * Archive le registre en PDF comme preuve sur la mesure A.5.37 de la SoA suivie par l'entité
     * active.
     *
     * @return int id du document, 0 en cas d'échec
     */
    public static function archive(): int
    {
        $fileName = sprintf('procedures-exploitation-%s.pdf', date('Ymd-His'));
        $pdf = PdfRenderer::renderHtmlToPdf(self::reportHtml());
        if (file_put_contents(GLPI_TMP_DIR . '/' . $fileName, $pdf) === false) {
            return 0;
        }
        // Document::add() empile des messages techniques sans intérêt pour l'utilisateur.
        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $documentsId = (int) (new Document())->add([
            'name'          => sprintf(__('Register of operating procedures - %s', 'grcmanager'), date('Y-m-d')),
            'entities_id'   => EntityPerimeter::soaEntity(),
            'is_recursive'  => 0,
            '_filename'     => [$fileName],
            '_tag_filename' => [$fileName],
        ]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
        if ($documentsId <= 0) {
            return 0;
        }
        foreach (self::controlIds() as $controlId) {
            (new Document_Item())->add([
                'documents_id' => $documentsId,
                'itemtype'     => Control::class,
                'items_id'     => $controlId,
            ]);
        }

        return $documentsId;
    }

    /**
     * Mesure A.5.37 de la SoA suivie par l'entité active.
     *
     * @return list<int>
     */
    public static function controlIds(): array
    {
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Control::getTable(),
                'WHERE'  => ['code' => self::CONTROL_CODE] + EntityPerimeter::controlCriteria(),
            ]) as $control
        ) {
            $ids[] = (int) $control['id'];
        }

        return $ids;
    }

    // ------------------------------------------------------------------
    // Preuve de la mesure A.5.37
    // ------------------------------------------------------------------

    /**
     * Preuve affichée sur la fiche de la mesure A.5.37 : couverture des domaines attendus. Null pour
     * les autres mesures ou sans droit de lire les politiques.
     *
     * @return array<string, mixed>|null
     */
    public static function evidence(string $code): ?array
    {
        if ($code !== self::CONTROL_CODE || !self::canView()) {
            return null;
        }
        $coverage = self::coverage(self::procedures(), date('Y-m-d'));

        return $coverage['summary'] + [
            'url'        => self::getRegisterURL(),
            'export_url' => self::getExportURL(),
        ];
    }

    /** Code de la mesure qui reçoit les preuves (approbation, prise de connaissance) d'une politique. */
    public static function controlCodeFor(Policy $policy): string
    {
        return ($policy->fields['category'] ?? '') === Rules::CATEGORY_PROCEDURE
            ? self::CONTROL_CODE
            : PolicyVersion::CONTROL_CODE;
    }

    /** Code et intitulé de cette mesure, pour les PDF de preuve. */
    public static function controlLabelFor(Policy $policy): string
    {
        $code = self::controlCodeFor($policy);

        return $code . ' ' . Control::getControlTitle($code);
    }
}

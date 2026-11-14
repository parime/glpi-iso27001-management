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
use CommonGLPI;
use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\ProcessingActivityBase;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Privacy\ProcessingActivityRules as Rules;
use Html;
use Log;
use Session;

/**
 * Issue #375 : registre des activités de traitement (RGPD, article 30). Une fiche par traitement :
 * finalité, base légale, catégories de personnes et de données, données sensibles, destinataires,
 * transferts hors UE, durées de conservation, mesures de sécurité (texte et mesures de la SoA),
 * rôle de l'organisme (responsable ou sous-traitant), liens vers les actifs, applications et
 * fournisseurs GLPI.
 *
 * Donnée sensible : droit dédié (Installer::GDPR_RIGHT, attribué au seul Super-Admin à
 * l'installation), fiches rattachées à une entité (contrôle d'entité du cœur), historique GLPI et
 * journal d'audit du plugin. Un élément lié n'est nommé, ajouté ou retiré que par qui peut le lire.
 */
class ProcessingActivity extends ProcessingActivityBase
{
    public const RIGHTNAME = 'plugin_grcmanager_gdpr';

    public const ITEMS_TABLE = 'glpi_plugin_grcmanager_processingactivities_items';

    /**
     * Incident de sécurité (violation de données) : relié depuis l'incident, jamais choisi dans une
     * liste sur la fiche, pour ne pas montrer à un DPO des incidents que le cloisonnement lui cache.
     */
    public const INCIDENT_ITEMTYPE = 'PluginGrcmanagerSecurityIncident';

    /** Contact du DPO : réglage du plugin, nettoyé avec son contexte à la désinstallation. */
    private const DPO_CONFIG_KEY = 'gdpr_dpo_contact';

    private const DPO_MAX_LENGTH = 255;

    /** Champ caché du formulaire : les listes à choix multiples ont été soumises (même vides). */
    public const LISTS_MARKER = '_processing_lists_defined';

    public const CONTROLS_FIELD = 'linked_controls';

    /** @var array<string, list<string>> champ JSON => catalogue */
    private const LIST_FIELDS = [
        'data_subjects'   => Rules::DATA_SUBJECTS,
        'data_categories' => Rules::DATA_CATEGORIES,
        'sensitive_data'  => Rules::SENSITIVE_DATA,
    ];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_processingactivities';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Processing activity', 'Record of processing activities', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-user-shield';
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(Dpia::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    /**
     * @return array<string, string>
     */
    public static function getRoles(): array
    {
        return [
            'controller'       => __('Controller', 'grcmanager'),
            'joint_controller' => __('Joint controller', 'grcmanager'),
            'processor'        => __('Processor (on behalf of a controller)', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getLegalBases(): array
    {
        return [
            'consent'              => __('Consent (art. 6.1.a)', 'grcmanager'),
            'contract'             => __('Performance of a contract (art. 6.1.b)', 'grcmanager'),
            'legal_obligation'     => __('Legal obligation (art. 6.1.c)', 'grcmanager'),
            'vital_interests'      => __('Vital interests (art. 6.1.d)', 'grcmanager'),
            'public_task'          => __('Public interest task (art. 6.1.e)', 'grcmanager'),
            'legitimate_interests' => __('Legitimate interests (art. 6.1.f)', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            'draft'    => __('Draft', 'grcmanager'),
            'active'   => __('In use', 'grcmanager'),
            'archived' => __('Ended', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getDataSubjects(): array
    {
        return [
            'employees'         => __('Employees', 'grcmanager'),
            'candidates'        => __('Job applicants', 'grcmanager'),
            'customers'         => __('Customers', 'grcmanager'),
            'prospects'         => __('Prospects', 'grcmanager'),
            'supplier_contacts' => __('Supplier and partner contacts', 'grcmanager'),
            'website_visitors'  => __('Website or application users', 'grcmanager'),
            'patients'          => __('Patients', 'grcmanager'),
            'students'          => __('Students or trainees', 'grcmanager'),
            'minors'            => __('Minors', 'grcmanager'),
            'other'             => __('Other', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getDataCategories(): array
    {
        return [
            'identity'          => __('Identity and contact details', 'grcmanager'),
            'personal_life'     => __('Personal life (habits, family situation...)', 'grcmanager'),
            'professional_life' => __('Professional life (resume, career, training...)', 'grcmanager'),
            'financial'         => __('Economic and financial information', 'grcmanager'),
            'connection'        => __('Connection data (logs, IP addresses...)', 'grcmanager'),
            'location'          => __('Location data', 'grcmanager'),
            'images_voice'      => __('Images, video or voice', 'grcmanager'),
            'other'             => __('Other', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getSensitiveData(): array
    {
        return [
            'racial_ethnic' => __('Racial or ethnic origin', 'grcmanager'),
            'political'     => __('Political opinions', 'grcmanager'),
            'religious'     => __('Religious or philosophical beliefs', 'grcmanager'),
            'trade_union'   => __('Trade union membership', 'grcmanager'),
            'genetic'       => __('Genetic data', 'grcmanager'),
            'biometric'     => __('Biometric data used to identify a person', 'grcmanager'),
            'health'        => __('Health data', 'grcmanager'),
            'sex_life'      => __('Sex life or sexual orientation', 'grcmanager'),
            'criminal'      => __('Criminal convictions and offences (art. 10)', 'grcmanager'),
            'national_id'   => __('National identification number', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getSafeguards(): array
    {
        return [
            'adequacy'   => __('Adequacy decision', 'grcmanager'),
            'scc'        => __('Standard contractual clauses', 'grcmanager'),
            'bcr'        => __('Binding corporate rules', 'grcmanager'),
            'derogation' => __('Derogation for a specific situation (art. 49)', 'grcmanager'),
            'other'      => __('Other', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getDpiaStatuses(): array
    {
        return [
            'not_assessed' => __('Not assessed', 'grcmanager'),
            'not_required' => __('Not required', 'grcmanager'),
            'required'     => __('Required, to be carried out', 'grcmanager'),
            'done'         => __('Carried out', 'grcmanager'),
        ];
    }

    public static function getCheckLabel(string $check): string
    {
        return match ($check) {
            Rules::CHECK_NO_PURPOSE                 => __('No purpose', 'grcmanager'),
            Rules::CHECK_NO_LEGAL_BASIS             => __('No legal basis', 'grcmanager'),
            Rules::CHECK_NO_DATA_SUBJECTS           => __('No category of data subjects', 'grcmanager'),
            Rules::CHECK_NO_DATA_CATEGORIES         => __('No category of personal data', 'grcmanager'),
            Rules::CHECK_NO_RETENTION               => __('No retention period', 'grcmanager'),
            Rules::CHECK_NO_SECURITY                => __('No security measure', 'grcmanager'),
            Rules::CHECK_NO_CONTROLLER              => __('Controller not named', 'grcmanager'),
            Rules::CHECK_TRANSFER_WITHOUT_SAFEGUARD => __('Transfer outside the EU without safeguard', 'grcmanager'),
            Rules::CHECK_SENSITIVE_WITHOUT_DPIA     => __('Sensitive data: DPIA decision missing', 'grcmanager'),
            Rules::CHECK_DPIA_PENDING               => __('DPIA to be carried out', 'grcmanager'),
            Rules::CHECK_REVIEW_OVERDUE             => __('Not reviewed for more than a year', 'grcmanager'),
            default                                 => $check,
        };
    }

    /**
     * Éléments GLPI qu'une fiche peut citer : applications, logiciels, bases de données,
     * fournisseurs (sous-traitants, destinataires), puis les actifs du registre des risques.
     *
     * @return list<string>
     */
    public static function getLinkableItemtypes(): array
    {
        $types = array_values(array_unique(array_merge(
            ['Appliance', 'Software', 'DatabaseInstance', 'Supplier'],
            Risk::getLinkableItemtypes()
        )));

        return array_values(array_filter($types, static fn (string $type): bool => class_exists($type)));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        if (trim((string) ($input['name'] ?? '')) === '') {
            Session::addMessageAfterRedirect(
                htmlescape(__('Give the processing activity a name.', 'grcmanager')),
                false,
                ERROR
            );
            return false;
        }
        // Sans entité dans la saisie : l'entité active, celle que check() a contrôlée, jamais la racine.
        $input += [
            'entities_id' => Session::getActiveEntity(),
            'role'        => Rules::DEFAULT_ROLE,
            'status'      => Rules::DEFAULT_STATUS,
            'dpia_status' => Rules::DEFAULT_DPIA_STATUS,
        ];
        // Listes toujours enregistrées (JSON vide plutôt que NULL).
        foreach (array_keys(self::LIST_FIELDS) as $field) {
            $input[$field] ??= [];
        }

        return $this->normalizeInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect(
                htmlescape(__('Give the processing activity a name.', 'grcmanager')),
                false,
                ERROR
            );
            return false;
        }
        // Jamais déplacée vers une entité que la personne ne voit pas.
        if (array_key_exists('entities_id', $input) && !Session::haveAccessToEntity((int) $input['entities_id'])) {
            unset($input['entities_id']);
        }

        return $this->normalizeInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeInput(array $input): array
    {
        $enums = [
            'role'                => Rules::normalizeRole(...),
            'status'              => Rules::normalizeStatus(...),
            'legal_basis'         => Rules::normalizeLegalBasis(...),
            'transfer_safeguards' => Rules::normalizeSafeguard(...),
            'dpia_status'         => Rules::normalizeDpiaStatus(...),
        ];
        foreach ($enums as $field => $normalize) {
            if (array_key_exists($field, $input)) {
                $input[$field] = $normalize($input[$field]);
            }
        }
        // Cases multiples : une liste vide n'envoie rien, d'où le marqueur du formulaire.
        foreach (self::LIST_FIELDS as $field => $catalog) {
            if (array_key_exists($field, $input) || isset($input[self::LISTS_MARKER])) {
                $input[$field] = Rules::encodeList($input[$field] ?? [], $catalog);
            }
        }
        if (array_key_exists('transfers_outside_eu', $input)) {
            $input['transfers_outside_eu'] = (int) (bool) $input['transfers_outside_eu'];
        }
        if (array_key_exists('last_review_date', $input) && trim((string) $input['last_review_date']) === '') {
            $input['last_review_date'] = 'NULL';
        }

        return $input;
    }

    public function post_addItem()
    {
        parent::post_addItem();
        $this->syncLinkedItemsFromInput();
        AuditLog::record('create', self::class, (int) $this->getID());
    }

    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        $this->syncLinkedItemsFromInput();
        if ($this->updates !== []) {
            AuditLog::record('update', self::class, (int) $this->getID(), 0, 'success', [
                'fields' => array_values($this->updates),
            ]);
        }
    }

    public function post_deleteItem()
    {
        parent::post_deleteItem();
        AuditLog::record('delete', self::class, (int) $this->getID());
    }

    public function post_restoreItem()
    {
        parent::post_restoreItem();
        AuditLog::record('restore', self::class, (int) $this->getID());
    }

    public function post_purgeItem()
    {
        global $DB;

        parent::post_purgeItem();
        $DB->delete(self::ITEMS_TABLE, ['plugin_grcmanager_processingactivities_id' => (int) $this->getID()]);
        (new Dpia())->deleteByCriteria([Dpia::ACTIVITY_FK => (int) $this->getID()]);
        // Issue #620 : plus citée par le registre des violations (les violations restent).
        DataBreach::forgetActivity((int) $this->getID());
        AuditLog::record('purge', self::class, (int) $this->getID());
    }

    public static function linkedFieldName(string $itemtype): string
    {
        return 'linked_items_' . str_replace('\\', '_', $itemtype);
    }

    /**
     * L'utilisateur courant peut-il lire cet élément (droit du type, entité) ? Un lien vers un
     * élément qu'il ne voit pas n'est ni créé, ni affiché, ni retiré par lui.
     */
    public static function canSeeLinkedItem(string $itemtype, int $itemsId): bool
    {
        $allowed = array_merge(self::getLinkableItemtypes(), [Control::class, self::INCIDENT_ITEMTYPE]);
        if ($itemsId <= 0 || !in_array($itemtype, $allowed, true)) {
            return false;
        }
        // Type validé par la liste blanche ci-dessus.
        // nosemgrep: php.lang.security.injection.tainted-object-instantiation.tainted-object-instantiation
        $item = new $itemtype();

        return $item instanceof CommonDBTM && $item->can($itemsId, READ);
    }

    /**
     * Le vrai formulaire envoie un marqueur par liste d'éléments (même vide) : seul lui remplace les
     * liens. Seuls les éléments lisibles sont acceptés ; les liens existants vers des éléments
     * invisibles pour la personne restent en place.
     */
    private function syncLinkedItemsFromInput(): void
    {
        global $DB;

        $pairs = [];
        $submitted = [];
        foreach (self::getLinkableItemtypes() as $itemtype) {
            $field = self::linkedFieldName($itemtype);
            if (!isset($this->input['_' . $field . '_defined']) && !isset($this->input[$field])) {
                continue;
            }
            $submitted[$itemtype] = true;
            foreach ((array) ($this->input[$field] ?? []) as $itemsId) {
                $pairs[$itemtype . ':' . (int) $itemsId] = ['itemtype' => $itemtype, 'items_id' => (int) $itemsId];
            }
        }
        // Mesures de la SoA : liste du formulaire, seulement avec le droit général du SMSI.
        if (
            Control::canView()
            && (isset($this->input[self::LISTS_MARKER]) || isset($this->input[self::CONTROLS_FIELD]))
        ) {
            $submitted[Control::class] = true;
            foreach ((array) ($this->input[self::CONTROLS_FIELD] ?? []) as $controlId) {
                $pairs[Control::class . ':' . (int) $controlId] = [
                    'itemtype' => Control::class,
                    'items_id' => (int) $controlId,
                ];
            }
        }
        if ($submitted === []) {
            return;
        }

        $activityId = (int) $this->getID();
        foreach (self::getLinks($activityId) as $existing) {
            $key = $existing['itemtype'] . ':' . $existing['items_id'];
            if (isset($pairs[$key])) {
                unset($pairs[$key]);
                continue;
            }
            $replaced = isset($submitted[$existing['itemtype']]);
            if ($replaced && self::canSeeLinkedItem($existing['itemtype'], $existing['items_id'])) {
                $DB->delete(self::ITEMS_TABLE, [
                    'plugin_grcmanager_processingactivities_id' => $activityId,
                    'itemtype'                                  => $existing['itemtype'],
                    'items_id'                                  => $existing['items_id'],
                ]);
            }
        }
        foreach ($pairs as $pair) {
            if (!self::canSeeLinkedItem($pair['itemtype'], $pair['items_id'])) {
                continue;
            }
            $DB->insert(self::ITEMS_TABLE, [
                'plugin_grcmanager_processingactivities_id' => $activityId,
                'itemtype'                                  => $pair['itemtype'],
                'items_id'                                  => $pair['items_id'],
                'date_creation'                             => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Tous les liens de la fiche, visibles ou non : à filtrer par canSeeLinkedItem() avant affichage.
     *
     * @return list<array{itemtype: string, items_id: int}>
     */
    public static function getLinks(int $activityId): array
    {
        global $DB;

        $links = [];
        if ($activityId <= 0) {
            return $links;
        }
        $criteria = [
            'FROM'  => self::ITEMS_TABLE,
            'WHERE' => ['plugin_grcmanager_processingactivities_id' => $activityId],
            'ORDER' => ['itemtype', 'items_id'],
        ];
        foreach ($DB->request($criteria) as $row) {
            $links[] = ['itemtype' => (string) $row['itemtype'], 'items_id' => (int) $row['items_id']];
        }

        return $links;
    }

    /**
     * Éléments liés que l'utilisateur peut lire, avec leur nom ; le nombre des autres.
     *
     * @return array{visible: list<array{itemtype: string, type: string, name: string, url: string}>, hidden: int}
     */
    public static function getVisibleLinks(int $activityId): array
    {
        $visible = [];
        $hidden = 0;
        foreach (self::getLinks($activityId) as $link) {
            // Élément supprimé définitivement depuis : ni nommé, ni compté comme invisible.
            $item = getItemForItemtype($link['itemtype']);
            if (!$item instanceof CommonDBTM || !$item->getFromDB($link['items_id'])) {
                continue;
            }
            if (!self::canSeeLinkedItem($link['itemtype'], $link['items_id'])) {
                $hidden++;
                continue;
            }
            $name = $item instanceof Control
                ? $item->fields['code'] . ' - ' . Control::getControlTitle((string) $item->fields['code'])
                : (string) $item->getName();
            $visible[] = [
                'itemtype' => $link['itemtype'],
                'type'     => $item::getTypeName(1),
                'name'     => $name,
                'url'      => $item::getFormURLWithID($link['items_id']),
            ];
        }

        return ['visible' => $visible, 'hidden' => $hidden];
    }

    public static function hasLinkedControls(int $activityId): bool
    {
        return countElementsInTable(self::ITEMS_TABLE, [
            'plugin_grcmanager_processingactivities_id' => $activityId,
            'itemtype'                                  => Control::class,
        ]) > 0;
    }

    /**
     * @return list<string>
     */
    public function getChecks(): array
    {
        return Rules::checks($this->fields, date('Y-m-d'), self::hasLinkedControls((int) $this->getID()));
    }

    public function rawSearchOptions()
    {
        $tab = [['id' => 'common', 'name' => self::getTypeName(1)]];
        $table = $this->getTable();
        $tab[] = ['id' => 1, 'table' => $table, 'field' => 'name', 'name' => __('Name', 'grcmanager'),
            'datatype' => 'itemlink', 'itemtype' => self::class];
        $tab[] = ['id' => 2, 'table' => $table, 'field' => 'reference', 'name' => __('Reference', 'grcmanager'),
            'datatype' => 'string'];
        $tab[] = ['id' => 3, 'table' => $table, 'field' => 'status', 'name' => __('Status', 'grcmanager'),
            'datatype' => 'specific'];
        $tab[] = ['id' => 4, 'table' => $table, 'field' => 'role',
            'name' => __('Role of the organisation', 'grcmanager'), 'datatype' => 'specific'];
        $tab[] = ['id' => 5, 'table' => $table, 'field' => 'purpose', 'name' => __('Purpose', 'grcmanager'),
            'datatype' => 'text'];
        $tab[] = ['id' => 6, 'table' => $table, 'field' => 'legal_basis', 'name' => __('Legal basis', 'grcmanager'),
            'datatype' => 'specific'];
        $tab[] = ['id' => 7, 'table' => $table, 'field' => 'data_subjects',
            'name' => __('Data subjects', 'grcmanager'), 'datatype' => 'specific', 'massiveaction' => false];
        $tab[] = ['id' => 8, 'table' => $table, 'field' => 'data_categories',
            'name' => __('Categories of personal data', 'grcmanager'), 'datatype' => 'specific',
            'massiveaction' => false];
        $tab[] = ['id' => 9, 'table' => $table, 'field' => 'sensitive_data',
            'name' => __('Sensitive data', 'grcmanager'), 'datatype' => 'specific', 'massiveaction' => false];
        $tab[] = ['id' => 10, 'table' => $table, 'field' => 'transfers_outside_eu',
            'name' => __('Transfers outside the EU', 'grcmanager'), 'datatype' => 'bool'];
        $tab[] = ['id' => 11, 'table' => $table, 'field' => 'retention_period',
            'name' => __('Retention period', 'grcmanager'), 'datatype' => 'text'];
        $tab[] = ['id' => 12, 'table' => $table, 'field' => 'dpia_status', 'name' => __('DPIA', 'grcmanager'),
            'datatype' => 'specific'];
        $tab[] = ['id' => 13, 'table' => 'glpi_users', 'field' => 'name', 'name' => _x('owner', 'Owner', 'grcmanager'),
            'datatype' => 'dropdown', 'right' => 'all'];
        $tab[] = ['id' => 14, 'table' => $table, 'field' => 'last_review_date',
            'name' => __('Last review', 'grcmanager'), 'datatype' => 'date'];
        // Écarts calculés à l'affichage à partir des champs de la ligne (non triables).
        $tab[] = ['id' => 15, 'table' => $table, 'field' => 'id', 'name' => __('Checks', 'grcmanager'),
            'datatype' => 'specific', 'nosearch' => true, 'nosort' => true, 'massiveaction' => false,
            'additionalfields' => ['status', 'role', 'purpose', 'legal_basis', 'data_subjects', 'data_categories',
                'sensitive_data', 'retention_period', 'security_measures', 'controller_name',
                'transfers_outside_eu', 'transfer_safeguards', 'dpia_status', 'last_review_date']];
        $tab[] = ['id' => 16, 'table' => $table, 'field' => 'recipients', 'name' => __('Recipients', 'grcmanager'),
            'datatype' => 'text'];
        $tab[] = ['id' => 17, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'itemlink',
            'itemtype' => self::class];
        $tab[] = ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => Entity::getTypeName(1),
            'datatype' => 'dropdown', 'massiveaction' => false];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $value = $values[$field] ?? '';

        switch ($field) {
            case 'status':
                return htmlescape(self::getStatuses()[(string) $value] ?? '');
            case 'role':
                return htmlescape(self::getRoles()[(string) $value] ?? '');
            case 'legal_basis':
                return htmlescape(self::getLegalBases()[(string) $value] ?? '');
            case 'dpia_status':
                return htmlescape(self::getDpiaStatuses()[(string) $value] ?? '');
            case 'data_subjects':
            case 'data_categories':
            case 'sensitive_data':
                return htmlescape(implode(', ', self::listLabels($field, $value)));
            case 'id':
                if (array_key_exists('purpose', $values)) {
                    $hasControls = self::hasLinkedControls((int) ($values['id'] ?? 0));
                    return self::checksHtml(Rules::checks($values, date('Y-m-d'), $hasControls));
                }
                break;
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        $options['value'] = $values[$field] ?? '';

        return match ($field) {
            'status'      => Dropdown::showFromArray($name, self::getStatuses(), $options),
            'role'        => Dropdown::showFromArray($name, self::getRoles(), $options),
            'legal_basis' => Dropdown::showFromArray($name, self::getLegalBases(), $options),
            'dpia_status' => Dropdown::showFromArray($name, self::getDpiaStatuses(), $options),
            default       => parent::getSpecificValueToSelect($field, $name, $values, $options),
        };
    }

    /**
     * Libellés d'une liste enregistrée en JSON.
     *
     * @return list<string>
     */
    public static function listLabels(string $field, mixed $json): array
    {
        $labels = match ($field) {
            'data_subjects'   => self::getDataSubjects(),
            'data_categories' => self::getDataCategories(),
            'sensitive_data'  => self::getSensitiveData(),
            default           => [],
        };
        $keys = Rules::decodeList($json, self::LIST_FIELDS[$field] ?? []);

        return array_values(array_map(static fn (string $key): string => $labels[$key] ?? $key, $keys));
    }

    /**
     * @param list<string> $checks
     */
    public static function checksHtml(array $checks): string
    {
        $items = [];
        foreach ($checks as $check) {
            $items[] = ['label' => self::getCheckLabel($check), 'blocking' => Rules::isBlocking($check)];
        }

        return trim(TemplateRenderer::getInstance()->render(
            '@grcmanager/_processing_activity_checks.html.twig',
            ['checks' => $items]
        ));
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $activityId = $this->isNewItem() ? 0 : (int) $ID;

        $linked = [];
        $controls = [];
        foreach (self::getLinks($activityId) as $link) {
            // Un élément invisible pour l'utilisateur n'est jamais nommé par la liste déroulante.
            if (!self::canSeeLinkedItem($link['itemtype'], $link['items_id'])) {
                continue;
            }
            if ($link['itemtype'] === Control::class) {
                $controls[] = $link['items_id'];
            } else {
                $linked[$link['itemtype']][] = $link['items_id'];
            }
        }
        $groups = [];
        foreach (self::getLinkableItemtypes() as $itemtype) {
            if (!is_a($itemtype, CommonDBTM::class, true) || !$itemtype::canView()) {
                continue;
            }
            $groups[] = [
                'itemtype' => $itemtype,
                'field'    => self::linkedFieldName($itemtype),
                'label'    => $itemtype::getTypeName(Session::getPluralNumber()),
                'values'   => $linked[$itemtype] ?? [],
            ];
        }
        $controlChoices = [];
        if (Control::canView()) {
            $controlChoices = AccessReview::getControlChoices();
            unset($controlChoices[0]);
        }
        $links = self::getVisibleLinks($activityId);

        TemplateRenderer::getInstance()->display('@grcmanager/processing_activity_form.html.twig', [
            'item'             => $this,
            'params'           => $options,
            'roles'            => self::getRoles(),
            'statuses'         => self::getStatuses(),
            'legal_bases'      => ['' => Dropdown::EMPTY_VALUE] + self::getLegalBases(),
            'safeguards'       => ['' => Dropdown::EMPTY_VALUE] + self::getSafeguards(),
            'dpia_statuses'    => self::getDpiaStatuses(),
            'data_subjects'    => self::getDataSubjects(),
            'data_categories'  => self::getDataCategories(),
            'sensitive_data'   => self::getSensitiveData(),
            'values'           => [
                'data_subjects'   => Rules::decodeList($this->fields['data_subjects'] ?? '', Rules::DATA_SUBJECTS),
                'data_categories' => Rules::decodeList($this->fields['data_categories'] ?? '', Rules::DATA_CATEGORIES),
                'sensitive_data'  => Rules::decodeList($this->fields['sensitive_data'] ?? '', Rules::SENSITIVE_DATA),
            ],
            'lists_marker'     => self::LISTS_MARKER,
            'groups'           => $groups,
            'controls_field'   => self::CONTROLS_FIELD,
            'control_choices'  => $controlChoices,
            'control_values'   => $controls,
            'hidden_links'     => $links['hidden'],
            'checks_html'      => $activityId > 0 ? self::checksHtml($this->getChecks()) : '',
        ]);

        return true;
    }

    /**
     * Fiches visibles (entités de la session, hors corbeille) qui citent un élément.
     *
     * @return list<array<string, mixed>>
     */
    public static function getForItem(CommonDBTM $item): array
    {
        global $DB;

        $table = self::getTable();
        $criteria = [
            'SELECT'     => ["$table.*"],
            'FROM'       => $table,
            'INNER JOIN' => [
                self::ITEMS_TABLE => [
                    'ON' => [self::ITEMS_TABLE => 'plugin_grcmanager_processingactivities_id', $table => 'id'],
                ],
            ],
            'WHERE'      => [
                self::ITEMS_TABLE . '.itemtype' => $item::class,
                self::ITEMS_TABLE . '.items_id' => (int) $item->getID(),
                "$table.is_deleted"             => 0,
            ] + getEntitiesRestrictCriteria($table, '', '', true),
            'ORDER'      => "$table.name",
        ];
        $rows = [];
        foreach ($DB->request($criteria) as $row) {
            $rows[] = [
                'id'      => (int) $row['id'],
                'url'     => self::getFormURLWithID((int) $row['id']),
                'name'    => (string) $row['name'],
                'status'  => self::getStatuses()[(string) $row['status']] ?? '',
                'purpose' => (string) $row['purpose'],
                'role'    => self::getRoles()[(string) $row['role']] ?? '',
            ];
        }

        return $rows;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            !$item instanceof CommonDBTM || $item->isNewItem() || !self::canView()
            || !in_array($item::class, self::getTabItemtypes(), true)
        ) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), count(self::getForItem($item)));
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : même contrôle ici,
        // plus la lecture de l'élément lui-même (un incident cloisonné ne révèle pas ses traitements).
        if (
            !$item instanceof CommonDBTM || !self::canView() || $item->isNewItem()
            || !in_array($item::class, self::getTabItemtypes(), true) || !$item->can((int) $item->getID(), READ)
        ) {
            return false;
        }
        $isIncident = $item::class === self::INCIDENT_ITEMTYPE;
        $canLink = $isIncident && $item->can((int) $item->getID(), UPDATE);
        TemplateRenderer::getInstance()->display('@grcmanager/processing_activity_tab.html.twig', [
            'activities'   => self::getForItem($item),
            'is_incident'  => $isIncident,
            'can_link'     => $canLink,
            'incident_id'  => (int) $item->getID(),
            'action'       => self::getFormURL(),
            'choices'      => $canLink ? self::linkChoices($item) : [],
        ]);

        return true;
    }

    /**
     * Types qui portent l'onglet : éléments citables depuis la fiche, et incidents de sécurité.
     *
     * @return list<string>
     */
    public static function getTabItemtypes(): array
    {
        return array_merge(self::getLinkableItemtypes(), [self::INCIDENT_ITEMTYPE]);
    }

    /**
     * Fiches que la personne peut lire, pas encore reliées à l'incident.
     *
     * @return array<int, string>
     */
    private static function linkChoices(CommonDBTM $incident): array
    {
        global $DB;

        $table = self::getTable();
        $linked = array_column(self::getForItem($incident), 'id');
        $choices = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => $table,
                'WHERE'  => ['is_deleted' => 0] + getEntitiesRestrictCriteria($table, '', '', true),
                'ORDER'  => 'name',
            ]) as $row
        ) {
            if (!in_array((int) $row['id'], $linked, true)) {
                $choices[(int) $row['id']] = (string) $row['name'];
            }
        }

        return $choices;
    }

    /**
     * Relie (ou délie) une fiche du registre et un incident : il faut pouvoir modifier l'incident et
     * lire la fiche (droit RGPD et entité). Rien d'autre n'est modifié sur la fiche.
     */
    public static function setIncidentLink(int $activityId, int $incidentId, bool $linked): bool
    {
        global $DB;

        $incident = getItemForItemtype(self::INCIDENT_ITEMTYPE);
        $activity = new self();
        if (
            !$incident instanceof CommonDBTM || !$incident->can($incidentId, UPDATE)
            || !$activity->can($activityId, READ) || (int) $activity->fields['is_deleted'] === 1
        ) {
            return false;
        }
        $row = [
            'plugin_grcmanager_processingactivities_id' => $activityId,
            'itemtype'                                  => self::INCIDENT_ITEMTYPE,
            'items_id'                                  => $incidentId,
        ];
        $exists = countElementsInTable(self::ITEMS_TABLE, $row) > 0;
        if ($linked && !$exists) {
            $DB->insert(self::ITEMS_TABLE, $row + ['date_creation' => date('Y-m-d H:i:s')]);
        } elseif (!$linked && $exists) {
            $DB->delete(self::ITEMS_TABLE, $row);
        }
        AuditLog::record($linked ? 'link' : 'unlink', self::class, $activityId, $incidentId);

        return true;
    }

    /**
     * Hooks::ITEM_PURGE : un élément supprimé définitivement ne reste pas cité par le registre.
     */
    public static function forgetItem(CommonDBTM $item): void
    {
        global $DB;

        if ($DB->tableExists(self::ITEMS_TABLE)) {
            $DB->delete(self::ITEMS_TABLE, ['itemtype' => $item::class, 'items_id' => (int) $item->getID()]);
        }
    }

    /**
     * Fiches du registre pour l'export (PDF ou CSV) : entités de la session, hors corbeille,
     * libellés traduits, éléments liés limités à ceux que l'utilisateur peut lire.
     *
     * @return list<array<string, string>>
     */
    public static function registerRows(): array
    {
        global $DB;

        $table = self::getTable();
        $criteria = [
            'FROM'  => $table,
            'WHERE' => ["$table.is_deleted" => 0] + getEntitiesRestrictCriteria($table, '', '', true),
            'ORDER' => ['reference', 'name'],
        ];
        $rows = [];
        foreach ($DB->request($criteria) as $row) {
            $id = (int) $row['id'];
            $links = self::getVisibleLinks($id);
            $controls = [];
            $items = [];
            foreach ($links['visible'] as $link) {
                if ($link['itemtype'] === Control::class) {
                    $controls[] = $link['name'];
                } else {
                    $items[] = $link['type'] . ' : ' . $link['name'];
                }
            }
            $transfer = (int) $row['transfers_outside_eu'] === 1
                ? __('Yes') . ((string) $row['transfer_countries'] !== '' ? ' - ' . $row['transfer_countries'] : '')
                    . ((string) $row['transfer_safeguards'] !== ''
                        ? ' (' . (self::getSafeguards()[(string) $row['transfer_safeguards']] ?? '') . ')'
                        : '')
                : __('No');
            $checks = Rules::checks($row, date('Y-m-d'), self::hasLinkedControls($id));
            $rows[] = [
                'reference'         => (string) $row['reference'],
                'name'              => (string) $row['name'],
                'entity'            => Dropdown::getDropdownName(Entity::getTable(), (int) $row['entities_id']),
                'status'            => self::getStatuses()[(string) $row['status']] ?? '',
                'role'              => self::getRoles()[(string) $row['role']] ?? '',
                'controller_name'   => (string) $row['controller_name'],
                'owner'             => (int) $row['users_id'] > 0 ? getUserName((int) $row['users_id']) : '',
                'purpose'           => (string) $row['purpose'],
                'legal_basis'       => trim((self::getLegalBases()[(string) $row['legal_basis']] ?? '')
                    . ((string) $row['legal_basis_detail'] !== '' ? ' - ' . $row['legal_basis_detail'] : '')),
                'data_subjects'     => implode(', ', self::listLabels('data_subjects', $row['data_subjects'])),
                'data_categories'   => implode(', ', self::listLabels('data_categories', $row['data_categories'])),
                'sensitive_data'    => implode(', ', self::listLabels('sensitive_data', $row['sensitive_data'])),
                'data_details'      => (string) $row['data_details'],
                'recipients'        => (string) $row['recipients'],
                'transfers'         => $transfer,
                'retention_period'  => (string) $row['retention_period'],
                'security_measures' => trim((string) $row['security_measures']
                    . ($controls !== [] ? "\n" . implode("\n", $controls) : '')),
                'items'             => implode("\n", $items),
                'dpia_status'       => self::getDpiaStatuses()[(string) $row['dpia_status']] ?? '',
                'last_review_date'  => $row['last_review_date']
                    ? (string) Html::convDate((string) $row['last_review_date']) : '',
                'checks'            => implode(', ', array_map(self::getCheckLabel(...), $checks)),
            ];
        }

        return $rows;
    }

    /**
     * Article 30 : coordonnées du délégué à la protection des données, sur l'export du registre.
     */
    public static function dpoContact(): string
    {
        $values = \Config::getConfigurationValues('plugin:grcmanager', [self::DPO_CONFIG_KEY]);

        return (string) ($values[self::DPO_CONFIG_KEY] ?? '');
    }

    /**
     * Texte brut uniquement : les balises sont retirées (affiché sur la configuration et le PDF).
     */
    public static function saveDpoContact(string $contact): void
    {
        \Config::setConfigurationValues('plugin:grcmanager', [
            self::DPO_CONFIG_KEY => mb_substr(trim(strip_tags($contact)), 0, self::DPO_MAX_LENGTH),
        ]);
    }

    /**
     * En-têtes des colonnes de l'export, dans l'ordre de registerRows().
     *
     * @return array<string, string>
     */
    public static function registerColumns(): array
    {
        return [
            'reference'         => __('Reference', 'grcmanager'),
            'name'              => __('Name', 'grcmanager'),
            'entity'            => Entity::getTypeName(1),
            'status'            => __('Status', 'grcmanager'),
            'role'              => __('Role of the organisation', 'grcmanager'),
            'controller_name'   => __('Controller or joint controller', 'grcmanager'),
            'owner'             => _x('owner', 'Owner', 'grcmanager'),
            'purpose'           => __('Purpose', 'grcmanager'),
            'legal_basis'       => __('Legal basis', 'grcmanager'),
            'data_subjects'     => __('Data subjects', 'grcmanager'),
            'data_categories'   => __('Categories of personal data', 'grcmanager'),
            'sensitive_data'    => __('Sensitive data', 'grcmanager'),
            'data_details'      => __('Details of the data', 'grcmanager'),
            'recipients'        => __('Recipients', 'grcmanager'),
            'transfers'         => __('Transfers outside the EU', 'grcmanager'),
            'retention_period'  => __('Retention period', 'grcmanager'),
            'security_measures' => __('Security measures', 'grcmanager'),
            'items'             => __('Applications, assets and suppliers', 'grcmanager'),
            'dpia_status'       => __('DPIA', 'grcmanager'),
            'last_review_date'  => __('Last review', 'grcmanager'),
            'checks'            => __('Checks', 'grcmanager'),
        ];
    }

    public static function getExportURL(string $format): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/processingactivity.export.php?format=' . $format;
    }
}

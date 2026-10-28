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

use CommonITILObject;
use CronTask;
use Document_Item;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\SupplierRiskBase;
use GlpiPlugin\Grcmanager\Services\Risk\ReviewReminderService;
use GlpiPlugin\Grcmanager\Services\Ui\Fragment;
use GlpiPlugin\Grcmanager\Traits\RiskAssessmentTrait;
use Html;
use Log;
use PluginGrcmanagerSecurityWatch;
use GlpiPlugin\Grcmanager\SupplierAssessment;
use Session;
use Supplier;
use Ticket;

/**
 * Supplier/third-party risk register (ISO 27001 clause 6.1.2/8.2 applied specifically to
 * suppliers, see also Annex A controls A.5.19-A.5.22 covered by the SoA), Sprint 5. Each row is
 * one risk tied to a real GLPI core `Supplier` (`suppliers_id`, GLPI's own native supplier
 * itemtype, never a parallel supplier concept of this plugin's own), with the exact same
 * probability x impact scoring and accept/mitigate/transfer/avoid treatment workflow as the
 * generic risk register (Risk): both share RiskAssessmentTrait so that scoring can
 * never drift between the two registers, see its docblock.
 */
class SupplierRisk extends SupplierRiskBase
{
    use RiskAssessmentTrait;

    // Registre sensible : droit dédié, voir Installer::SENSITIVE_RIGHT.
    public const RIGHTNAME = 'plugin_grcmanager_sensitive';

    /**
     * GLPI notification event name (see inc/notificationtargetsupplierrisk.class.php and
     * src/Services/Risk/ReviewReminderService.php), same single-source-of-truth pattern as
     * Risk::REVIEW_DUE_EVENT. Deliberately the same event name as the generic risk
     * register: both itemtypes raise 'review_due' on their own NotificationTarget class, GLPI
     * dispatches by the notified item's own get_class(), so reusing the string is safe and keeps a
     * single vocabulary ("review due") across both registers rather than inventing a second one.
     */
    public const REVIEW_DUE_EVENT = 'review_due';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_supplierrisks';
    }

    /**
     * Le titre tient lieu de nom : sans cela, l'en-tête de la fiche et les listes déroulantes
     * affichaient « N/A » (même correctif que Policy::getNameField()).
     */
    public static function getNameField()
    {
        return 'title';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Supplier risk', 'Supplier risks', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-truck';
    }

    /**
     * The one validation this class adds on top of RiskAssessmentTrait's shared scoring: a
     * supplier risk without a linked Supplier would defeat the whole point of a dedicated
     * register (it would just be a generic risk with a "third_party" category, already possible
     * on Risk since Sprint 1). Delegates the actual probability x impact scoring
     * to the trait's own computeRiskLevel() so this override never duplicates that logic.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    /**
     * Issue #127 : questionnaire de sécurité et contrats du fournisseur
     * (SupplierAssessment), preuves (Documents) et historique.
     */
    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(SupplierAssessment::class, $tabs, $options)
            // Issue #167 : avis du CERT-FR et vulnérabilités du parc qui citent le fournisseur.
            ->addStandardTab(PluginGrcmanagerSecurityWatch::class, $tabs, $options)
            ->addStandardTab(Document_Item::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function prepareInputForAdd($input)
    {
        return $this->validateSupplierAndComputeRiskLevel($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        return $this->validateSupplierAndComputeRiskLevel($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function validateSupplierAndComputeRiskLevel(array $input)
    {
        $suppliersId = (int) ($input['suppliers_id'] ?? ($this->fields['suppliers_id'] ?? 0));

        if ($suppliersId <= 0) {
            Session::addMessageAfterRedirect(
                __('A supplier is required for a supplier risk.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }

        return $this->computeRiskLevel($input);
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
            'name'     => __('Title', 'grcmanager'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
        ];

        // Real GLPI-native dropdown join to the core Supplier itemtype (unlike the control<->risk
        // and audit<->control links of Sprints 3-4, deliberately kept simple with a direct-access
        // helper, see TECH_DEBT.md): a genuine `datatype => 'dropdown'` search option on
        // `glpi_suppliers` gives a real filter/search-by-supplier combo box and a native join for
        // free, exactly what this sprint's requirement ("filtres... par le Fournisseur lié") asks
        // for, confirmed against a real GLPI 11 instance the same way the `users_id` (owner) column
        // already does for `glpi_users` below.
        $tab[] = [
            'id'       => 2,
            'table'    => 'glpi_suppliers',
            'field'    => 'name',
            'name'     => Supplier::getTypeName(1),
            'datatype' => 'dropdown',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'category',
            'name'     => __('Category', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'probability',
            'name'     => __('Probability', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'impact',
            'name'     => __('Impact', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'risk_level',
            'name'     => __('Risk level', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'treatment',
            'name'     => __('Treatment', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 8,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => _x('owner', 'Owner', 'grcmanager'),
            'datatype' => 'dropdown',
            'right'    => 'all',
        ];

        $tab[] = [
            'id'       => 9,
            'table'    => $this->getTable(),
            'field'    => 'status',
            'name'     => __('Status', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 10,
            'table'    => $this->getTable(),
            'field'    => 'review_date',
            'name'     => __('Review date', 'grcmanager'),
            'datatype' => 'date',
        ];

        // Row link, same "a list with no way back to showForm() is not self-explanatory" lesson
        // already applied throughout this plugin family, see Risk::rawSearchOptions().
        $tab[] = [
            'id'       => 11,
            'table'    => $this->getTable(),
            'field'    => 'id',
            'name'     => __('ID'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
        ];

        return $tab;
    }

    /**
     * Every enum column here (category/probability/impact/risk_level/treatment/status) is shared
     * with the generic risk register, see RiskAssessmentTrait::commonValueToDisplay(). No
     * class-specific field needs a badge: `suppliers_id` renders through GLPI's own generic
     * dropdown resolution (the search option above is a real join, not `datatype => 'specific'`).
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        $common = self::commonValueToDisplay($field, $values[$field] ?? null);
        if ($common !== null) {
            return $common;
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Same dispatch as Risk::getSpecificValueToSelect(), see
     * RiskAssessmentTrait::commonValueToSelect(). `suppliers_id` is left to GLPI core's own default
     * for a `datatype => 'dropdown'` search option (a real supplier picker), not overridden here.
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        $options['display'] = false;
        $options['name']    = $name;
        $options['value']   = $values[$field] ?? '';

        $common = self::commonValueToSelect($field, $name, $options);
        if ($common !== null) {
            return $common;
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $reviewTicket = (int) ($this->fields['review_tickets_id'] ?? 0);

        TemplateRenderer::getInstance()->display('@grcmanager/supplier_risk_form.html.twig', [
            'item'              => $this,
            'params'            => $options,
            'is_new'            => $this->isNewID($ID),
            'supplier_label'    => Supplier::getTypeName(1),
            'categories'        => self::getCategories(),
            'probabilities'     => self::getProbabilities(),
            'impacts'           => self::getImpacts(),
            'treatments'        => self::getTreatments(),
            'statuses'          => self::getStatuses(),
            'risk_level_badge'  => self::riskLevelBadge($this->fields['risk_level'] ?? null),
            'review_ticket_id'  => $reviewTicket,
            'review_ticket_url' => $reviewTicket > 0 ? Ticket::getFormURLWithID($reviewTicket) : '',
        ]);

        return true;
    }

    /**
     * GLPI Cron entry point, same structure as Risk::cronReviewreminder() and
     * registered the same way in src/Install/Installer.php (a second dedicated CronTask entry,
     * `SupplierRisk`/`reviewreminder`: GLPI's Cron task model registers/dispatches
     * one static entry point per itemtype, so a single task cannot itself cover two different
     * itemtypes, see ReviewReminderService's own docblock for why the underlying query/notify logic
     * is shared instead of duplicated).
     *
     * @return int 0 if no supplier risk was due, 1 otherwise
     */
    public static function cronReviewreminder(CronTask $task): int
    {
        $result = (new ReviewReminderService(self::class))->notify();
        $tickets = self::openReviewTickets();

        $task->addVolume($result->getNotified() + $tickets);
        $task->log(sprintf(
            '%d risque(s) fournisseur en attente de revue, %d notification(s) déclenchée(s).',
            $result->getDue(),
            $result->getNotified()
        ));

        return $result->getDue() > 0 ? 1 : 0;
    }

    /**
     * Issue #127 : à l'échéance de la revue (date dépassée ou atteinte), un ticket « Réévaluer le
     * fournisseur » pour le propriétaire du risque, en plus de la notification existante. Un seul
     * ticket tant qu'il reste ouvert ; rien n'est modifié sur le risque lui-même, hormis le lien.
     *
     * @return int nombre de tickets ouverts
     */
    public static function openReviewTickets(): int
    {
        global $DB;

        $count = 0;
        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'review_date' => ['<=', date('Y-m-d')],
                'NOT'         => ['status' => 'closed'],
            ],
        ]);
        foreach ($rows as $row) {
            $existing = new Ticket();
            $ticketsId = (int) ($row['review_tickets_id'] ?? 0);
            if (
                $ticketsId > 0
                && $existing->getFromDB($ticketsId)
                && !in_array(
                    (int) $existing->fields['status'],
                    [CommonITILObject::SOLVED, CommonITILObject::CLOSED],
                    true
                )
            ) {
                continue;
            }
            $supplier = Dropdown::getDropdownName(Supplier::getTable(), (int) $row['suppliers_id']);
            // Ticket dans l'entité du fournisseur (et non à la racine, visible de tous les
            // techniciens de la racine) : seuls ceux qui voient le fournisseur voient la revue.
            $supplierItem = new Supplier();
            $entityId = $supplierItem->getFromDB((int) $row['suppliers_id'])
                ? (int) $supplierItem->fields['entities_id']
                : 0;
            $input = [
                'name'        => sprintf(__('Reassess supplier %s', 'grcmanager'), $supplier),
                'content'     => Fragment::ticketContent(
                    [
                        sprintf(
                            __('The review of supplier risk "%1$s" was due on %2$s.', 'grcmanager'),
                            (string) $row['title'],
                            Html::convDate((string) $row['review_date'])
                        ),
                        __(
                            'Update the security questionnaire, check the contracts and evidence, then set the next '
                                . 'review date.',
                            'grcmanager'
                        ),
                    ],
                    self::getFormURLWithID((int) $row['id']),
                    __('Open the supplier risk', 'grcmanager')
                ),
                'type'        => Ticket::DEMAND_TYPE,
                'entities_id' => $entityId,
            ];
            if ((int) $row['users_id'] > 0) {
                $input['_users_id_requester'] = (int) $row['users_id'];
                $input['_users_id_assign'] = (int) $row['users_id'];
            }
            $newTicket = (int) (new Ticket())->add($input);
            if ($newTicket > 0) {
                $DB->update(self::getTable(), ['review_tickets_id' => $newTicket], ['id' => (int) $row['id']]);
                $count++;
            }
        }

        return $count;
    }
}

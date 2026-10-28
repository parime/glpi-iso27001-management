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
use CronTask;
use Document;
use Document_Item;
use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\AccessReviewBase;
use GlpiPlugin\Grcmanager\Pdf\PdfRenderer;
use GlpiPlugin\Grcmanager\Services\Access\AccessReviewLogic;
use Group;
use Group_User;
use Html;
use Log;
use NotificationEvent;
use Profile;
use Profile_User;
use Session;
use Ticket;
use User;

/**
 * Issue #126 : campagne de revue périodique des accès (ISO 27001 A.5.18, droits d'accès).
 *
 * - le lancement fige, pour chaque compte actif de l'entité, ses profils et groupes GLPI, et
 *   désigne le responsable qui les revoit (responsable hiérarchique, sinon responsable d'un de
 *   ses groupes, sinon le pilote de la campagne, voir AccessReviewLogic::reviewerFor()) ;
 * - chaque responsable valide ou demande une modification / un retrait, ligne par ligne, depuis
 *   « Mes revues d'accès » (front/accessreview.mine.php), même sans droit sur le plugin ;
 * - une demande de modification ou de retrait crée un ticket pour l'équipe support : le plugin ne
 *   modifie jamais un droit, un compte ni un statut ;
 * - les responsables en retard sont relancés (tâche « accessreviewreminder ») ;
 * - la clôture archive un rapport PDF (Document GLPI) sur la campagne et sur la mesure de la SoA.
 */
class AccessReview extends AccessReviewBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const LINES_TABLE = 'glpi_plugin_grcmanager_accessreviews_users';

    public const REQUEST_EVENT = 'access_review_request';
    public const REMINDER_EVENT = 'access_review_reminder';

    private const TAB_LINES = 1;
    private const TAB_CONTROL = 2;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_accessreviews';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Access review', 'Access reviews', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-user-check';
    }

    /** Rubrique « Revues des accès » : les campagnes et l'hygiène des comptes. */
    /**
     * Rubrique « Opérations » : revues des accès, hygiène des comptes, revues périodiques,
     * formations et exercices de crise (Menu::sections()).
     */
    public static function getMenuContent()
    {
        $menu = parent::getMenuContent();
        if (is_array($menu) && $menu !== []) {
            $menu['section_label'] = $menu['title'];
            $menu['title'] = __('Operations', 'grcmanager');
        }

        return Menu::withSubPages(self::class, $menu);
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            AccessReviewLogic::STATUS_DRAFT  => __('In preparation', 'grcmanager'),
            AccessReviewLogic::STATUS_OPEN   => __('In progress', 'grcmanager'),
            AccessReviewLogic::STATUS_CLOSED => __('Closed', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getFrequencies(): array
    {
        return [
            'quarterly'  => __('Quarterly', 'grcmanager'),
            'semiannual' => __('Half-yearly', 'grcmanager'),
            'annual'     => __('Yearly', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getDecisionLabels(): array
    {
        return [
            AccessReviewLogic::DECISION_PENDING => _x('to review', 'To review', 'grcmanager'),
            AccessReviewLogic::DECISION_KEEP    => __('Access approved', 'grcmanager'),
            AccessReviewLogic::DECISION_MODIFY  => __('To change', 'grcmanager'),
            AccessReviewLogic::DECISION_REMOVE  => __('To remove', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getSourceLabels(): array
    {
        return [
            AccessReviewLogic::SOURCE_SUPERVISOR => __('line manager', 'grcmanager'),
            AccessReviewLogic::SOURCE_GROUP      => __('group manager', 'grcmanager'),
            AccessReviewLogic::SOURCE_OWNER      => __('owner (no manager)', 'grcmanager'),
        ];
    }

    public static function getMineURL(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/accessreview.mine.php';
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(self::class, $tabs, $options)
            ->addStandardTab(Document_Item::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        global $DB;

        if ($item instanceof self && !$item->isNewItem()) {
            $count = countElementsInTable(self::LINES_TABLE, ['plugin_grcmanager_accessreviews_id' => $item->getID()]);

            return [
                self::TAB_LINES => self::createTabEntry(
                    __('Accounts to review', 'grcmanager'),
                    $count,
                    null,
                    'ti ti-users'
                ),
            ];
        }
        // Le contrôle est global à l'instance, les campagnes non : droit de lecture sur les revues
        // d'accès exigé, et seules les campagnes des entités de la session sont comptées/listées.
        if ($item instanceof Control && !$item->isNewItem() && self::canView()) {
            $count = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => self::getTable(),
                'WHERE' => ['plugin_grcmanager_controls_id' => $item->getID()]
                    + getEntitiesRestrictCriteria(self::getTable(), '', '', true),
            ])->current()['cpt'] ?? 0;

            return [
                self::TAB_CONTROL => self::createTabEntry(self::getTypeName(2), (int) $count, null, self::getIcon()),
            ];
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof self) {
            $item->showLines();

            return true;
        }
        if ($item instanceof Control) {
            if (!self::canView()) {
                return false;
            }
            self::showForControl($item);

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        if (trim((string) ($input['name'] ?? '')) === '') {
            Session::addMessageAfterRedirect(__('The campaign name is required.', 'grcmanager'), false, ERROR);

            return false;
        }
        $input['status'] = AccessReviewLogic::STATUS_DRAFT;
        if (!isset(self::getFrequencies()[$input['frequency'] ?? ''])) {
            $input['frequency'] = 'annual';
        }
        if ((int) ($input['users_id'] ?? 0) <= 0) {
            $input['users_id'] = (int) Session::getLoginUserID();
        }
        if (!isset($input['plugin_grcmanager_controls_id'])) {
            $input['plugin_grcmanager_controls_id'] = self::getDefaultControlId();
        }
        if (empty($input['date_start'])) {
            $input['date_start'] = date('Y-m-d');
        }
        if (empty($input['due_date'])) {
            $input['due_date'] = date('Y-m-d', (int) strtotime($input['date_start'] . ' +30 days'));
        }

        return $input;
    }

    /**
     * Le statut n'évolue que par launch() et close(), jamais par le formulaire.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForUpdate($input)
    {
        unset($input['status'], $input['date_launch'], $input['date_close'], $input['documents_id']);
        if (isset($input['frequency']) && !isset(self::getFrequencies()[$input['frequency']])) {
            unset($input['frequency']);
        }

        return $input;
    }

    public function cleanDBonPurge()
    {
        global $DB;

        $DB->delete(self::LINES_TABLE, ['plugin_grcmanager_accessreviews_id' => $this->getID()]);
    }

    public static function getDefaultControlId(): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Control::getTable(),
            'WHERE'  => ['code' => AccessReviewLogic::DEFAULT_CONTROL_CODE],
            'LIMIT'  => 1,
        ])->current();

        return (int) ($row['id'] ?? 0);
    }

    public function rawSearchOptions()
    {
        $tab = [];
        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];
        $tab[] = [
            'id'            => 1,
            'table'         => $this->getTable(),
            'field'         => 'name',
            'name'          => __('Nom'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'       => 2,
            'table'    => $this->getTable(),
            'field'    => 'status',
            'name'     => __('Status', 'grcmanager'),
            'datatype' => 'specific',
        ];
        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'frequency',
            'name'     => __('Frequency', 'grcmanager'),
            'datatype' => 'specific',
        ];
        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'date_start',
            'name'     => __('Start date', 'grcmanager'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id'       => 5,
            'table'    => $this->getTable(),
            'field'    => 'due_date',
            'name'     => __('Due date', 'grcmanager'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id'       => 6,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => _x('process pilot', 'Owner', 'grcmanager'),
            'datatype' => 'dropdown',
            'right'    => 'all',
        ];
        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'date_close',
            'name'     => __('Closing date', 'grcmanager'),
            'datatype' => 'datetime',
        ];
        $tab[] = [
            'id'       => 8,
            'table'    => $this->getTable(),
            'field'    => 'id',
            'name'     => __('ID'),
            'datatype' => 'itemlink',
            'itemtype' => self::class,
        ];
        $tab[] = [
            'id'       => 80,
            'table'    => 'glpi_entities',
            'field'    => 'completename',
            'name'     => Entity::getTypeName(1),
            'datatype' => 'dropdown',
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $value = (string) ($values[$field] ?? '');
        switch ($field) {
            case 'status':
                return htmlescape(self::getStatuses()[$value] ?? $value);
            case 'frequency':
                return htmlescape(self::getFrequencies()[$value] ?? $value);
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
        switch ($field) {
            case 'status':
                return Dropdown::showFromArray($name, self::getStatuses(), $options);
            case 'frequency':
                return Dropdown::showFromArray($name, self::getFrequencies(), $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /**
     * @return array<int, string> id => « A.5.18 - Droits d'accès »
     */
    public static function getControlChoices(): array
    {
        global $DB;

        $choices = [0 => Dropdown::EMPTY_VALUE];
        foreach ($DB->request(['SELECT' => ['id', 'code'], 'FROM' => Control::getTable()]) as $row) {
            $choices[(int) $row['id']] = $row['code'] . ' - ' . Control::getControlTitle($row['code']);
        }
        asort($choices);

        return $choices;
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $isNew = $this->isNewID($ID);
        $status = (string) ($this->fields['status'] ?? '') ?: AccessReviewLogic::STATUS_DRAFT;

        TemplateRenderer::getInstance()->display('@grcmanager/access_review_form.html.twig', [
            'item'         => $this,
            'params'       => $options,
            'is_new'       => $isNew,
            'frequencies'  => self::getFrequencies(),
            'status_label' => self::getStatuses()[$status] ?? $status,
            'status_class' => [
                AccessReviewLogic::STATUS_DRAFT  => 'bg-secondary-lt',
                AccessReviewLogic::STATUS_OPEN   => 'bg-blue-lt',
                AccessReviewLogic::STATUS_CLOSED => 'bg-green-lt',
            ][$status] ?? 'bg-secondary-lt',
            // Nouvelle campagne : du jour à J+30, pilotée par la personne qui la crée.
            'date_start'   => $isNew ? date('Y-m-d') : ($this->fields['date_start'] ?? ''),
            'due_date'     => $isNew
                ? date('Y-m-d', (int) strtotime('+30 days'))
                : ($this->fields['due_date'] ?? ''),
            'pilot'        => $isNew ? Session::getLoginUserID() : ($this->fields['users_id'] ?? 0),
            'controls'     => self::getControlChoices(),
            'control_id'   => $isNew
                ? self::getDefaultControlId()
                : (int) ($this->fields['plugin_grcmanager_controls_id'] ?? 0),
        ]);

        return true;
    }

    /**
     * Lignes de revue d'une campagne, avec le nom du compte et du responsable.
     *
     * @param array<string, mixed> $where critères supplémentaires sur la table des lignes
     * @return list<array<string, mixed>>
     */
    public static function getLines(int $campaignId, array $where = []): array
    {
        global $DB;

        $lines = [];
        $rows = $DB->request([
            'FROM'  => self::LINES_TABLE,
            'WHERE' => ['plugin_grcmanager_accessreviews_id' => $campaignId] + $where,
            'ORDER' => ['reviewers_id', 'id'],
        ]);
        foreach ($rows as $row) {
            $lines[] = self::decorateLine($row);
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decorateLine(array $row): array
    {
        $profiles = json_decode((string) ($row['profiles'] ?? ''), true);
        $groups = json_decode((string) ($row['user_groups'] ?? ''), true);
        $row['id'] = (int) $row['id'];
        $row['users_id'] = (int) $row['users_id'];
        $row['reviewers_id'] = (int) $row['reviewers_id'];
        $row['tickets_id'] = (int) $row['tickets_id'];
        $row['user_name'] = getUserName((int) $row['users_id']);
        $row['user_url'] = User::getFormURLWithID((int) $row['users_id']);
        $row['reviewer_name'] = getUserName((int) $row['reviewers_id']);
        $row['profiles_list'] = is_array($profiles) ? $profiles : [];
        $row['groups_list'] = is_array($groups) ? $groups : [];
        $row['decided_by_name'] = (int) $row['decided_by'] > 0 ? getUserName((int) $row['decided_by']) : '';
        $row['ticket_url'] = (int) $row['tickets_id'] > 0 ? Ticket::getFormURLWithID((int) $row['tickets_id']) : '';

        return $row;
    }

    /**
     * Lignes des campagnes en cours confiées à un responsable.
     *
     * @return list<array<string, mixed>>
     */
    public static function getLinesForReviewer(int $reviewerId): array
    {
        global $DB;

        $lines = [];
        $rows = $DB->request([
            'SELECT'     => [
                'review_lines.*',
                'campaigns.name AS campaign_name',
                'campaigns.due_date AS campaign_due_date',
            ],
            'FROM'       => self::LINES_TABLE . ' AS review_lines',
            'INNER JOIN' => [
                self::getTable() . ' AS campaigns' => [
                    'FKEY' => ['review_lines' => 'plugin_grcmanager_accessreviews_id', 'campaigns' => 'id'],
                ],
            ],
            'WHERE'      => [
                'review_lines.reviewers_id' => $reviewerId,
                'campaigns.status'          => AccessReviewLogic::STATUS_OPEN,
            ],
            'ORDER'      => ['campaigns.due_date', 'review_lines.id'],
        ]);
        foreach ($rows as $row) {
            $lines[] = self::decorateLine($row);
        }

        return $lines;
    }

    /**
     * Lance la campagne : fige les comptes de l'entité (et sous-entités si récursive), leurs
     * profils et groupes, désigne leur responsable puis prévient les responsables.
     *
     * @return int nombre de comptes à revoir, -1 si la campagne n'est pas en préparation
     */
    public function launch(): int
    {
        global $DB;

        if (($this->fields['status'] ?? '') !== AccessReviewLogic::STATUS_DRAFT) {
            return -1;
        }
        $campaignId = $this->getID();
        $entityId = (int) $this->fields['entities_id'];
        $entities = (int) $this->fields['is_recursive'] === 1
            ? array_values(array_map('intval', getSonsOf(Entity::getTable(), $entityId)))
            : [$entityId];
        $ownerId = (int) $this->fields['users_id'];

        $accounts = [];
        $rows = $DB->request([
            'SELECT'     => [
                'pu.users_id',
                'pu.profiles_id',
                'pu.entities_id',
                'pu.is_recursive',
                'u.users_id_supervisor',
            ],
            'FROM'       => Profile_User::getTable() . ' AS pu',
            'INNER JOIN' => [User::getTable() . ' AS u' => ['FKEY' => ['u' => 'id', 'pu' => 'users_id']]],
            'WHERE'      => ['pu.entities_id' => $entities, 'u.is_active' => 1, 'u.is_deleted' => 0],
            'ORDER'      => ['pu.users_id', 'pu.id'],
        ]);
        foreach ($rows as $row) {
            $usersId = (int) $row['users_id'];
            $accounts[$usersId]['supervisor'] = (int) $row['users_id_supervisor'];
            $accounts[$usersId]['profiles'][] =
                Dropdown::getDropdownName(Profile::getTable(), (int) $row['profiles_id'])
                . ' — ' . Dropdown::getDropdownName(Entity::getTable(), (int) $row['entities_id'])
                . ((int) $row['is_recursive'] === 1 ? ' ' . __('(and sub-entities)', 'grcmanager') : '');
        }
        if ($accounts === []) {
            Session::addMessageAfterRedirect(
                __('No active account has a profile in this entity.', 'grcmanager'),
                false,
                ERROR
            );

            return 0;
        }

        $memberships = [];
        $managers = [];
        foreach ($DB->request(['FROM' => Group_User::getTable(), 'ORDER' => ['groups_id', 'id']]) as $row) {
            $memberships[(int) $row['users_id']][] = (int) $row['groups_id'];
            if ((int) $row['is_manager'] === 1) {
                $managers[(int) $row['groups_id']][] = (int) $row['users_id'];
            }
        }

        $now = date('Y-m-d H:i:s');
        foreach ($accounts as $usersId => $account) {
            $groupIds = $memberships[$usersId] ?? [];
            $groupManagers = [];
            foreach ($groupIds as $groupId) {
                foreach ($managers[$groupId] ?? [] as $managerId) {
                    $groupManagers[] = ['groups_id' => $groupId, 'users_id' => $managerId];
                }
            }
            $reviewer = AccessReviewLogic::reviewerFor($usersId, $account['supervisor'], $groupManagers, $ownerId);
            $DB->insert(self::LINES_TABLE, [
                'plugin_grcmanager_accessreviews_id' => $campaignId,
                'users_id'                           => $usersId,
                'reviewers_id'                       => $reviewer['users_id'],
                'groups_id'                          => $reviewer['groups_id'],
                'reviewer_source'                    => $reviewer['source'],
                'profiles'                           => json_encode(array_values(array_unique($account['profiles']))),
                'user_groups'                        => json_encode(array_map(
                    static fn (int $groupId): string => Dropdown::getDropdownName(Group::getTable(), $groupId),
                    array_values(array_unique($groupIds))
                )),
                'decision'                           => AccessReviewLogic::DECISION_PENDING,
                'date_creation'                      => $now,
            ]);
        }

        $DB->update(
            self::getTable(),
            ['status' => AccessReviewLogic::STATUS_OPEN, 'date_launch' => $now, 'date_mod' => $now],
            ['id' => $campaignId]
        );
        $this->getFromDB($campaignId);
        NotificationEvent::raiseEvent(self::REQUEST_EVENT, $this, ['entities_id' => $entityId]);

        return count($accounts);
    }

    /**
     * Décision d'un responsable sur une ligne. Une demande de modification ou de retrait crée un
     * ticket (une seule fois : la ligne est ensuite figée) ; aucun droit n'est modifié.
     *
     * @return string message d'erreur, chaîne vide si la décision est enregistrée
     */
    public static function decide(int $lineId, string $decision, string $comment, int $actorId): string
    {
        global $DB;

        $line = $DB->request(['FROM' => self::LINES_TABLE, 'WHERE' => ['id' => $lineId]])->current();
        $campaign = new self();
        if (
            $line === null
            || !$campaign->getFromDB((int) $line['plugin_grcmanager_accessreviews_id'])
            || $campaign->fields['status'] !== AccessReviewLogic::STATUS_OPEN
        ) {
            return __('This line does not belong to an ongoing campaign.', 'grcmanager');
        }
        // Gestionnaire des campagnes : droit UPDATE sur CETTE campagne (donc sur son entité), pas le
        // seul droit global — sinon une ligne d'une campagne d'une autre entité serait modifiable.
        if ((int) $line['reviewers_id'] !== $actorId && !$campaign->can($campaign->getID(), UPDATE)) {
            return __('This line is assigned to another reviewer.', 'grcmanager');
        }
        if (!AccessReviewLogic::isDecision($decision)) {
            return __('Unknown decision.', 'grcmanager');
        }
        if ((int) $line['tickets_id'] > 0) {
            return __(
                'A ticket has already been created for this account: the decision can no longer change.',
                'grcmanager'
            );
        }
        if (AccessReviewLogic::needsTicket($decision) && trim($comment) === '') {
            return __('State what must be changed or removed.', 'grcmanager');
        }

        $update = [
            'decision'      => $decision,
            'comment'       => $comment,
            'decided_by'    => $actorId,
            'date_decision' => date('Y-m-d H:i:s'),
        ];
        if (AccessReviewLogic::needsTicket($decision)) {
            $update['tickets_id'] = self::openTicket(
                $campaign,
                self::decorateLine($line),
                $decision,
                $comment,
                $actorId
            );
        }
        $DB->update(self::LINES_TABLE, $update, ['id' => $lineId]);

        return '';
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function openTicket(
        self $campaign,
        array $line,
        string $decision,
        string $comment,
        int $actorId
    ): int {
        $title = $decision === AccessReviewLogic::DECISION_REMOVE
            ? __('Access review: remove the access of %s', 'grcmanager')
            : __('Access review: change the access of %s', 'grcmanager');
        // Issue #199 : contenu du ticket rendu par Twig.
        $content = TemplateRenderer::getInstance()->render('@grcmanager/ticket/access_review_request.html.twig', [
            'campaign' => (string) $campaign->fields['name'],
            'actor'    => getUserName($actorId),
            'comment'  => $comment,
            'profiles' => $line['profiles_list'],
            'groups'   => $line['groups_list'],
        ]);

        $ticket = new Ticket();

        return (int) $ticket->add([
            'name'                => sprintf($title, $line['user_name']),
            'content'             => $content,
            'type'                => Ticket::DEMAND_TYPE,
            'entities_id'         => (int) $campaign->fields['entities_id'],
            '_users_id_requester' => $actorId,
        ]);
    }

    /**
     * Clôture : archive le rapport PDF (Document) sur la campagne et sur la mesure de la SoA.
     */
    public function close(): bool
    {
        global $DB;

        if (($this->fields['status'] ?? '') !== AccessReviewLogic::STATUS_OPEN) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $DB->update(
            self::getTable(),
            ['status' => AccessReviewLogic::STATUS_CLOSED, 'date_close' => $now, 'date_mod' => $now],
            ['id' => $this->getID()]
        );
        $this->getFromDB($this->getID());

        $pdf = PdfRenderer::renderHtmlToPdf(self::renderReport($this));
        $documentsId = $this->attachPdf($pdf);
        $DB->update(self::getTable(), ['documents_id' => $documentsId], ['id' => $this->getID()]);
        $this->getFromDB($this->getID());

        return true;
    }

    public static function renderReport(self $campaign): string
    {
        $lines = self::getLines($campaign->getID());
        $controlId = (int) $campaign->fields['plugin_grcmanager_controls_id'];

        return TemplateRenderer::getInstance()->render('@grcmanager/pdf/access_review_report.html.twig', [
            'report_title'       => sprintf(__('Access review: %s', 'grcmanager'), $campaign->fields['name']),
            'generated_at_label' => sprintf(
                __('Generated on %s', 'grcmanager'),
                Html::convDateTime(date('Y-m-d H:i:s'))
            ),
            'campaign'  => $campaign->fields,
            'entity'    => Dropdown::getDropdownName(Entity::getTable(), (int) $campaign->fields['entities_id']),
            'owner'     => getUserName((int) $campaign->fields['users_id']),
            'frequency' => self::getFrequencies()[$campaign->fields['frequency']] ?? '',
            'control'   => $controlId > 0 ? (self::getControlChoices()[$controlId] ?? '') : '',
            'progress'  => AccessReviewLogic::progress(array_column($lines, 'decision')),
            'lines'     => $lines,
            'decisions' => self::getDecisionLabels(),
            'dates'     => [
                'start'  => Html::convDate($campaign->fields['date_start']),
                'due'    => Html::convDate($campaign->fields['due_date']),
                'launch' => Html::convDateTime($campaign->fields['date_launch']),
                'close'  => Html::convDateTime($campaign->fields['date_close']),
            ],
        ]);
    }

    private function attachPdf(string $pdf): int
    {
        $fileName = sprintf('revue-acces-%d-%s.pdf', $this->getID(), date('Ymd-His'));
        if (file_put_contents(GLPI_TMP_DIR . '/' . $fileName, $pdf) === false) {
            return 0;
        }

        // Document::add() empile des messages techniques sans intérêt pour l'utilisateur.
        $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
        $documentsId = (int) (new Document())->add([
            'name'          => sprintf(__('Access review report - %s', 'grcmanager'), $this->fields['name']),
            'entities_id'   => (int) $this->fields['entities_id'],
            // Comptes, profils et justifications d'une seule entité : pas de récursivité, et aucun
            // lien vers la mesure A.5.18 (globale, lisible de toutes les entités ; le document
            // serait téléchargeable par tout lecteur de la SoA). La campagne reste listée sur
            // l'onglet de la mesure pour qui peut la voir.
            'is_recursive'  => 0,
            '_filename'     => [$fileName],
            '_tag_filename' => [$fileName],
        ]);
        $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
        if ($documentsId <= 0) {
            return 0;
        }
        (new Document_Item())->add([
            'documents_id' => $documentsId,
            'itemtype'     => self::class,
            'items_id'     => $this->getID(),
        ]);
        RestrictedDocument::register($documentsId, $this);

        return $documentsId;
    }

    /**
     * Prépare la campagne suivante (en préparation) d'après la fréquence de celle-ci.
     */
    public function prepareNext(): int
    {
        $currentStart = (string) $this->fields['date_start'];
        $start = AccessReviewLogic::nextStartDate($currentStart, (string) $this->fields['frequency']);
        $length = max(
            1,
            (int) ((strtotime((string) $this->fields['due_date']) - strtotime($currentStart)) / DAY_TIMESTAMP)
        );

        return (int) (new self())->add([
            'name'                          => sprintf(
                __('Access review of %s', 'grcmanager'),
                Html::convDate($start)
            ),
            'entities_id'                   => (int) $this->fields['entities_id'],
            'is_recursive'                  => (int) $this->fields['is_recursive'],
            'frequency'                     => $this->fields['frequency'],
            'users_id'                      => (int) $this->fields['users_id'],
            'plugin_grcmanager_controls_id' => (int) $this->fields['plugin_grcmanager_controls_id'],
            'date_start'                    => $start,
            'due_date'                      => date('Y-m-d', (int) strtotime($start . ' +' . $length . ' days')),
        ]);
    }

    private function showLines(): void
    {
        global $CFG_GLPI;

        $lines = self::getLines($this->getID());
        $status = (string) $this->fields['status'];
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/access_review_lines.html.twig', [
            'item'        => $this->fields,
            'status'      => $status,
            'lines'       => $lines,
            'progress'    => AccessReviewLogic::progress(array_column($lines, 'decision')),
            'decisions'   => self::getDecisionLabels(),
            'sources'     => self::getSourceLabels(),
            'can_manage'  => $this->canUpdateItem(),
            'can_decide'  => $status === AccessReviewLogic::STATUS_OPEN && $this->canUpdateItem(),
            'form_action' => self::getFormURL(),
            'decide_url'  => self::getMineURL(),
            'mine_url'    => self::getMineURL(),
            'report_url'  => (int) $this->fields['documents_id'] > 0
                ? $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . (int) $this->fields['documents_id']
                : '',
            'late'        => $status === AccessReviewLogic::STATUS_OPEN
                && date('Y-m-d') > (string) $this->fields['due_date'],
        ]);
    }

    private static function showForControl(Control $control): void
    {
        global $DB, $CFG_GLPI;

        $campaigns = [];
        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_grcmanager_controls_id' => $control->getID()]
                + getEntitiesRestrictCriteria(self::getTable(), '', '', true),
            'ORDER' => 'date_start DESC',
        ]);
        foreach ($rows as $row) {
            $lines = self::getLines((int) $row['id']);
            $campaigns[] = [
                'name'       => (string) $row['name'],
                'url'        => self::getFormURLWithID((int) $row['id']),
                'status'     => self::getStatuses()[$row['status']] ?? $row['status'],
                'date_start' => $row['date_start'],
                'date_close' => $row['date_close'],
                'progress'   => AccessReviewLogic::progress(array_column($lines, 'decision')),
                'report_url' => (int) $row['documents_id'] > 0
                    ? $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . (int) $row['documents_id']
                    : '',
            ];
        }
        TemplateRenderer::getInstance()->display('@grcmanager/tabs/control_access_reviews.html.twig', [
            'campaigns' => $campaigns,
            'new_url'   => self::getFormURL(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function cronInfo(string $name): array
    {
        return [
            'description' => __('Reminds reviewers who are late on an access review', 'grcmanager'),
        ];
    }

    /**
     * Relance, au plus une fois par semaine, les responsables qui ont encore des comptes à revoir
     * dans une campagne dont l'échéance est dépassée.
     */
    public static function cronAccessreviewreminder(CronTask $task): int
    {
        $count = self::sendReminders(date('Y-m-d'));
        $task->addVolume($count);

        return $count > 0 ? 1 : 0;
    }

    /**
     * @return int nombre de responsables relancés
     */
    public static function sendReminders(string $today): int
    {
        global $DB;

        $count = 0;
        $campaigns = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['status' => AccessReviewLogic::STATUS_OPEN, 'due_date' => ['<', $today]],
        ]);
        foreach ($campaigns as $row) {
            $late = [];
            $pending = $DB->request([
                'SELECT' => ['reviewers_id', 'MAX' => 'date_reminder AS last_reminder'],
                'FROM'   => self::LINES_TABLE,
                'WHERE'  => [
                    'plugin_grcmanager_accessreviews_id' => (int) $row['id'],
                    'decision'                           => AccessReviewLogic::DECISION_PENDING,
                ],
                'GROUPBY' => 'reviewers_id',
            ]);
            foreach ($pending as $reviewer) {
                if (AccessReviewLogic::isReminderDue((string) $row['due_date'], $reviewer['last_reminder'], $today)) {
                    $late[] = (int) $reviewer['reviewers_id'];
                }
            }
            if ($late === []) {
                continue;
            }
            $campaign = new self();
            $campaign->getFromDB((int) $row['id']);
            NotificationEvent::raiseEvent(self::REMINDER_EVENT, $campaign, [
                'entities_id' => (int) $row['entities_id'],
                'reviewers'   => $late,
            ]);
            $DB->update(self::LINES_TABLE, ['date_reminder' => date('Y-m-d H:i:s')], [
                'plugin_grcmanager_accessreviews_id' => (int) $row['id'],
                'reviewers_id'                       => $late,
                'decision'                           => AccessReviewLogic::DECISION_PENDING,
            ]);
            $count += count($late);
        }

        return $count;
    }

    /**
     * Responsables qui ont encore des comptes à revoir sur une campagne.
     *
     * @return list<int>
     */
    public static function getPendingReviewerIds(int $campaignId): array
    {
        global $DB;

        $ids = [];
        $rows = $DB->request([
            'SELECT'   => ['reviewers_id'],
            'DISTINCT' => true,
            'FROM'     => self::LINES_TABLE,
            'WHERE'    => [
                'plugin_grcmanager_accessreviews_id' => $campaignId,
                'decision'                           => AccessReviewLogic::DECISION_PENDING,
            ],
        ]);
        foreach ($rows as $row) {
            $ids[] = (int) $row['reviewers_id'];
        }

        return $ids;
    }
}

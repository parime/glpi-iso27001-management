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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Compliance\ComplianceAuditScore;

/**
 * Issue #162 : audit de conformité technique (ISO 27001, NIS2, RGPD) à partir de l'inventaire et
 * des processus de GLPI : postes sans antivirus à jour, disques non chiffrés, postes non
 * inventoriés, changements clos sans validation approuvée, comptes à vérifier. Chaque contrôle
 * liste ses écarts ; un ticket de mise en conformité est proposé, jamais créé automatiquement, et
 * un seul reste ouvert par contrôle et par entité. Ancre sans table, comme PluginGrcmanagerMaturity.
 *
 * Chaque contrôle n'est évalué que si la personne a le droit natif de lecture des éléments
 * concernés (ordinateurs, changements, utilisateurs).
 */
class PluginGrcmanagerComplianceAudit extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public const TICKETS_TABLE = 'glpi_plugin_grcmanager_complianceaudittickets';

    /** Un poste non inventorié depuis ce nombre de jours est un écart. */
    public const INVENTORY_DAYS = 30;

    /** Changements clos sur cette période (jours) contrôlés. */
    public const CHANGE_PERIOD_DAYS = 365;

    /** Comptes sans connexion depuis ce nombre de jours (hygiène des comptes). */
    public const ACCOUNT_DAYS = 90;

    /** Écarts liés au ticket de mise en conformité, au plus. */
    private const TICKET_ITEMS_MAX = 100;

    public static function getTypeName($nb = 0)
    {
        return __('Audit de conformité technique', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-checklist';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/complianceaudit.php';
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::RIGHTNAME, READ);
    }

    public static function getMenuContent()
    {
        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * @return array<string, array{label: string, help: string}>
     */
    public static function getCheckLabels(int $approvals = 1): array
    {
        return [
            'antivirus'         => [
                'label' => __('Postes sans antivirus actif et à jour', 'grcmanager'),
                'help'  => __(
                    'D\'après l\'inventaire (onglet « Antivirus » des ordinateurs) : aucun antivirus, ou antivirus '
                        . 'inactif, pas à jour ou expiré.',
                    'grcmanager'
                ),
            ],
            'encryption'        => [
                'label' => __('Postes avec un disque non chiffré', 'grcmanager'),
                'help'  => __(
                    'D\'après l\'inventaire des volumes : au moins un disque non chiffré ou partiellement chiffré. '
                        . 'Seuls les ordinateurs dont les volumes sont inventoriés sont comptés.',
                    'grcmanager'
                ),
            ],
            'inventory'         => [
                'label' => sprintf(
                    __('Postes non inventoriés depuis plus de %d jours', 'grcmanager'),
                    self::INVENTORY_DAYS
                ),
                'help'  => __(
                    'Ordinateurs remontés par l\'agent d\'inventaire dont la dernière remontée est ancienne : '
                        . 'l\'inventaire des actifs n\'est plus fiable pour eux.',
                    'grcmanager'
                ),
            ],
            'change_validation' => [
                'label' => sprintf(
                    _n(
                        'Changements clos sans validation approuvée',
                        'Changements clos sans %d validations approuvées',
                        $approvals,
                        'grcmanager'
                    ),
                    $approvals
                ),
                'help'  => __(
                    'Changements résolus ou clos depuis un an dont les validations approuvées sont moins '
                        . 'nombreuses que le nombre exigé.',
                    'grcmanager'
                ),
            ],
            'accounts'          => [
                'label' => __('Comptes à vérifier', 'grcmanager'),
                'help'  => sprintf(
                    __(
                        'Comptes actifs supprimés de l\'annuaire, expirés, en doublon ou sans connexion depuis '
                            . '%d jours (écran « Hygiène des comptes »).',
                        'grcmanager'
                    ),
                    self::ACCOUNT_DAYS
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getFrameworkLabels(): array
    {
        return [
            ComplianceAuditScore::ISO27001 => 'ISO/IEC 27001',
            ComplianceAuditScore::NIS2     => 'NIS2',
            ComplianceAuditScore::GDPR     => __('RGPD', 'grcmanager'),
        ];
    }

    /**
     * Tous les contrôles, dans l'ordre d'affichage.
     *
     * @return array<string, array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}>
     */
    public static function run(int $approvals = 1, ?string $now = null): array
    {
        $now ??= Session::getCurrentTime();
        $computers = Computer::canView() ? self::computers() : null;

        return [
            'antivirus'         => $computers === null ? self::notEvaluated() : self::antivirus($computers, $now),
            'encryption'        => $computers === null ? self::notEvaluated() : self::encryption($computers),
            'inventory'         => $computers === null ? self::notEvaluated() : self::inventory($computers, $now),
            // Liste (et lie au ticket) tous les changements des entités : réservé à qui voit tous
            // les changements. Change::canView() est vrai aussi avec « mes changements » ou un
            // droit de validation (revue de sécurité 2.11.0).
            'change_validation' => Session::haveRight(Change::$rightname, Change::READALL)
                ? self::changeValidation($approvals, $now)
                : self::notEvaluated(),
            'accounts'          => PluginGrcmanagerAccountHygiene::canView()
                ? self::accounts($now)
                : self::notEvaluated(),
        ];
    }

    /**
     * @return array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}
     */
    private static function notEvaluated(): array
    {
        return ['evaluated' => false, 'total' => 0, 'gaps' => []];
    }

    /**
     * Ordinateurs visibles (entités actives, hors corbeille et gabarits).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function computers(): array
    {
        global $DB;

        $computers = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'is_dynamic', 'last_inventory_update'],
                'FROM'   => Computer::getTable(),
                'WHERE'  => ['is_deleted' => 0, 'is_template' => 0]
                    + getEntitiesRestrictCriteria(Computer::getTable(), '', '', true),
                'ORDER'  => 'name',
            ]) as $row
        ) {
            $computers[(int) $row['id']] = $row;
        }

        return $computers;
    }

    /**
     * @return array<string, mixed>
     */
    private static function gap(string $itemtype, int $id, string $name, string $detail): array
    {
        return [
            'itemtype' => $itemtype,
            'id'       => $id,
            'name'     => $name !== '' ? $name : sprintf('%s %d', $itemtype::getTypeName(1), $id),
            'url'      => $itemtype::getFormURLWithID($id),
            'detail'   => $detail,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $computers
     * @return array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}
     */
    private static function antivirus(array $computers, string $now): array
    {
        global $DB;

        $found = [];
        $compliant = [];
        if ($computers !== []) {
            foreach (
                $DB->request([
                    'FROM'  => 'glpi_itemantiviruses',
                    'WHERE' => ['itemtype' => Computer::class, 'items_id' => array_keys($computers), 'is_deleted' => 0],
                ]) as $row
            ) {
                $found[(int) $row['items_id']] = true;
                if (ComplianceAuditScore::isAntivirusCompliant($row, substr($now, 0, 10))) {
                    $compliant[(int) $row['items_id']] = true;
                }
            }
        }
        $gaps = [];
        foreach ($computers as $id => $computer) {
            if (!isset($compliant[$id])) {
                $gaps[] = self::gap(Computer::class, $id, (string) $computer['name'], isset($found[$id])
                    ? __('Antivirus inactif, pas à jour ou expiré', 'grcmanager')
                    : __('Aucun antivirus inventorié', 'grcmanager'));
            }
        }

        return ['evaluated' => true, 'total' => count($computers), 'gaps' => $gaps];
    }

    /**
     * @param array<int, array<string, mixed>> $computers
     * @return array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}
     */
    private static function encryption(array $computers): array
    {
        global $DB;

        $withDisks = [];
        $clear = [];
        if ($computers !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['items_id', 'name', 'mountpoint', 'encryption_status'],
                    'FROM'   => 'glpi_items_disks',
                    'WHERE'  => [
                        'itemtype'   => Computer::class,
                        'items_id'   => array_keys($computers),
                        'is_deleted' => 0,
                    ],
                ]) as $row
            ) {
                $id = (int) $row['items_id'];
                $withDisks[$id] = true;
                if ((int) $row['encryption_status'] !== Item_Disk::ENCRYPTION_STATUS_YES) {
                    $clear[$id][] = (string) ($row['mountpoint'] ?: $row['name']);
                }
            }
        }
        $gaps = [];
        foreach ($clear as $id => $volumes) {
            $gaps[] = self::gap(
                Computer::class,
                $id,
                (string) $computers[$id]['name'],
                sprintf(__('Non chiffré : %s', 'grcmanager'), implode(', ', $volumes))
            );
        }

        return ['evaluated' => true, 'total' => count($withDisks), 'gaps' => $gaps];
    }

    /**
     * @param array<int, array<string, mixed>> $computers
     * @return array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}
     */
    private static function inventory(array $computers, string $now): array
    {
        $limit = date('Y-m-d H:i:s', (int) strtotime($now) - self::INVENTORY_DAYS * DAY_TIMESTAMP);
        $total = 0;
        $gaps = [];
        foreach ($computers as $id => $computer) {
            if (empty($computer['is_dynamic'])) {
                continue;
            }
            $total++;
            $last = (string) ($computer['last_inventory_update'] ?? '');
            if ($last === '' || $last < $limit) {
                $gaps[] = self::gap(Computer::class, $id, (string) $computer['name'], $last === ''
                    ? __('Jamais inventorié', 'grcmanager')
                    : sprintf(__('Dernier inventaire : %s', 'grcmanager'), Html::convDateTime($last)));
            }
        }

        return ['evaluated' => true, 'total' => $total, 'gaps' => $gaps];
    }

    /**
     * @return array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}
     */
    private static function changeValidation(int $approvals, string $now): array
    {
        global $DB;

        $since = date('Y-m-d H:i:s', (int) strtotime($now) - self::CHANGE_PERIOD_DAYS * DAY_TIMESTAMP);
        $changes = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => Change::getTable(),
                'WHERE'  => [
                    'is_deleted' => 0,
                    'status'     => array_merge(Change::getSolvedStatusArray(), Change::getClosedStatusArray()),
                    'OR'         => [['solvedate' => ['>=', $since]], ['closedate' => ['>=', $since]]],
                ] + getEntitiesRestrictCriteria(Change::getTable(), '', '', true),
                'ORDER'  => 'id DESC',
            ]) as $row
        ) {
            $changes[(int) $row['id']] = (string) $row['name'];
        }
        $accepted = [];
        if ($changes !== []) {
            foreach (
                $DB->request([
                    'SELECT' => ['changes_id'],
                    'FROM'   => ChangeValidation::getTable(),
                    'WHERE'  => ['changes_id' => array_keys($changes), 'status' => CommonITILValidation::ACCEPTED],
                ]) as $row
            ) {
                $accepted[(int) $row['changes_id']] = ($accepted[(int) $row['changes_id']] ?? 0) + 1;
            }
        }
        $gaps = [];
        foreach ($changes as $id => $name) {
            $count = $accepted[$id] ?? 0;
            if ($count < $approvals) {
                $gaps[] = self::gap(Change::class, $id, $name, sprintf(
                    __('%1$d validation(s) approuvée(s) sur %2$d exigée(s)', 'grcmanager'),
                    $count,
                    $approvals
                ));
            }
        }

        return ['evaluated' => true, 'total' => count($changes), 'gaps' => $gaps];
    }

    /**
     * @return array{evaluated: bool, total: int, gaps: list<array<string, mixed>>}
     */
    private static function accounts(string $now): array
    {
        global $DB;

        $total = (int) $DB->request([
            'COUNT'      => 'cpt',
            'SELECT'     => 'glpi_users.id',
            'DISTINCT'   => true,
            'FROM'       => 'glpi_users',
            'INNER JOIN' => [
                'glpi_profiles_users' => ['ON' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id']],
            ],
            'WHERE'      => ['glpi_users.is_active' => 1, 'glpi_users.is_deleted' => 0]
                + getEntitiesRestrictCriteria('glpi_profiles_users', '', '', true),
        ])->current()['cpt'];
        $gaps = [];
        foreach (PluginGrcmanagerAccountHygiene::findAccounts(self::ACCOUNT_DAYS, $now) as $account) {
            $gaps[] = self::gap(User::class, $account['id'], $account['fullname'], implode(', ', array_map(
                static fn (string $check): string => PluginGrcmanagerAccountHygiene::getCheckLabel(
                    $check,
                    self::ACCOUNT_DAYS
                ),
                $account['checks']
            )));
        }

        return ['evaluated' => true, 'total' => $total, 'gaps' => $gaps];
    }

    /**
     * Ticket de mise en conformité encore ouvert pour ce contrôle dans l'entité active.
     */
    public static function openTicket(string $check): ?Ticket
    {
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => self::TICKETS_TABLE,
                'WHERE' => ['check_key' => $check, 'entities_id' => Session::getActiveEntity()],
                'ORDER' => 'id DESC',
            ]) as $row
        ) {
            $ticket = new Ticket();
            if (
                $ticket->getFromDB((int) $row['tickets_id'])
                && empty($ticket->fields['is_deleted'])
                && !in_array(
                    (int) $ticket->fields['status'],
                    array_merge(Ticket::getSolvedStatusArray(), Ticket::getClosedStatusArray()),
                    true
                )
            ) {
                return $ticket;
            }
        }

        return null;
    }

    /**
     * Ticket de mise en conformité d'un contrôle : écarts recalculés au moment de la demande,
     * éléments liés au ticket. Un ticket déjà ouvert pour ce contrôle est réutilisé.
     *
     * @return int id du ticket, 0 si rien à faire ou refusé
     */
    public static function createTicket(string $check, int $approvals = 1): int
    {
        global $DB;

        if (
            !Session::haveRight(self::RIGHTNAME, UPDATE)
            || !Session::haveRight(Ticket::$rightname, CREATE)
            || !in_array($check, ['antivirus', 'encryption', 'inventory', 'change_validation'], true)
        ) {
            return 0;
        }
        $existing = self::openTicket($check);
        if ($existing !== null) {
            return $existing->getID();
        }
        $result = self::run($approvals)[$check];
        if (!$result['evaluated'] || $result['gaps'] === []) {
            return 0;
        }
        $label = self::getCheckLabels($approvals)[$check]['label'];
        $references = [];
        foreach (ComplianceAuditScore::CHECKS[$check] as $framework => $reference) {
            $references[] = self::getFrameworkLabels()[$framework] . ' ' . $reference;
        }
        $gaps = array_slice($result['gaps'], 0, self::TICKET_ITEMS_MAX);
        $ticketId = (int) (new Ticket())->add([
            'name'        => sprintf(
                __('Mise en conformité : %1$s (%2$d)', 'grcmanager'),
                $label,
                count($result['gaps'])
            ),
            'content'     => TemplateRenderer::getInstance()->render('@grcmanager/compliance_audit_ticket.html.twig', [
                'label'      => $label,
                'help'       => self::getCheckLabels($approvals)[$check]['help'],
                'references' => $references,
                'gaps'       => $gaps,
                'more'       => count($result['gaps']) - count($gaps),
            ]),
            'type'        => Ticket::DEMAND_TYPE,
            'entities_id' => Session::getActiveEntity(),
        ]);
        if ($ticketId <= 0) {
            return 0;
        }
        foreach ($gaps as $gap) {
            // Sans notification par élément lié : le ticket a déjà notifié sa création.
            if ($gap['itemtype'] === Change::class) {
                (new Change_Ticket())->add([
                    'changes_id'    => $gap['id'],
                    'tickets_id'    => $ticketId,
                    '_disablenotif' => true,
                ]);
            } else {
                (new Item_Ticket())->add([
                    'itemtype'      => $gap['itemtype'],
                    'items_id'      => $gap['id'],
                    'tickets_id'    => $ticketId,
                    '_disablenotif' => true,
                ]);
            }
        }
        $DB->insert(self::TICKETS_TABLE, [
            'check_key'     => $check,
            'entities_id'   => Session::getActiveEntity(),
            'tickets_id'    => $ticketId,
            'date_creation' => Session::getCurrentTime(),
        ]);

        return $ticketId;
    }
}

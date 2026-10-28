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
use GlpiPlugin\Grcmanager\Compatibility\Base\PeriodicReviewBase;
use GlpiPlugin\Grcmanager\Services\Review\PeriodicReviewCatalog;

/**
 * Issue #141 : revues périodiques du SMSI (droits d'accès, matrice des flux, registre des risques,
 * politiques, SoA, tests de restauration et de continuité…). Une ligne par revue du catalogue
 * (PeriodicReviewCatalog) ; l'activer crée un gabarit de ticket et un ticket récurrent NATIFS de
 * GLPI (Assistance > Tickets récurrents), modifiables ensuite comme n'importe quel autre.
 *
 * Règle commune : le plugin ne change jamais un statut, un droit ni une affectation. Il prépare le
 * ticket que l'équipe traite ; quand ce ticket est résolu, la date de dernière revue est affichée
 * (onTicketUpdate()), sans rien modifier d'autre.
 *
 * Les éléments du plugin sont globaux : gabarit et ticket récurrent sont créés dans l'entité
 * racine, récursifs.
 */
class PluginGrcmanagerPeriodicReview extends PeriodicReviewBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_periodicreviews';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Periodic review', 'Periodic reviews', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-repeat';
    }

    /** Les lignes viennent du catalogue (créées par l'installeur) : jamais créées à la main. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getLabel(string $key): string
    {
        return match ($key) {
            'access_rights'       => __('Review of accounts and access rights', 'grcmanager'),
            'privileged_accounts' => __('Review of privileged and service accounts', 'grcmanager'),
            'network_flows'       => __('Review of the network flow matrix (firewall rules)', 'grcmanager'),
            'risk_register'       => __('Risk register review', 'grcmanager'),
            'supplier_risks'      => __('Supplier risk review', 'grcmanager'),
            'policies'            => __('Security policy review', 'grcmanager'),
            'soa'                 => __('Statement of Applicability review', 'grcmanager'),
            'legal_obligations'   => __('Review of legal and contractual obligations', 'grcmanager'),
            'backup_restore_test' => __('Backup restore test', 'grcmanager'),
            'continuity_test'     => __('Business continuity and disaster recovery test (BCP / DRP)', 'grcmanager'),
            'security_logs'       => __('Security log review', 'grcmanager'),
            default               => $key,
        };
    }

    /**
     * Points à vérifier, repris dans le ticket.
     *
     * @return list<string>
     */
    public static function getChecklist(string $key): array
    {
        return match ($key) {
            'access_rights' => [
                __('Launch or close the access review campaign', 'grcmanager'),
                __('Check the accounts of people who left or changed jobs', 'grcmanager'),
                __('Open a ticket for each right to remove or change', 'grcmanager'),
            ],
            'privileged_accounts' => [
                __('List administrator and service accounts', 'grcmanager'),
                __('Confirm the need and the owner of each one', 'grcmanager'),
                __('Check password rotation and strong authentication', 'grcmanager'),
            ],
            'network_flows' => [
                __('Export the firewall rules', 'grcmanager'),
                __('Check that each flow has a justification and an owner', 'grcmanager'),
                __('Remove unneeded or expired rules (through a ticket)', 'grcmanager'),
            ],
            'risk_register' => [
                __('Reassess the likelihood and impact of the risks', 'grcmanager'),
                __('Check the progress of the treatment plans', 'grcmanager'),
                __('Add newly identified risks', 'grcmanager'),
            ],
            'supplier_risks' => [
                __('Reassess the critical suppliers', 'grcmanager'),
                __('Check contract and certificate expiry dates', 'grcmanager'),
            ],
            'policies' => [
                __('Reread each policy and date the review', 'grcmanager'),
                __('Publish the new versions and inform the people concerned', 'grcmanager'),
            ],
            'soa' => [
                __('Check the applicability and justification of each control', 'grcmanager'),
                __('Update the implementation status', 'grcmanager'),
                __('Request a new approval if the SoA changed', 'grcmanager'),
            ],
            'legal_obligations' => [
                __('List new obligations (laws, contracts, customers)', 'grcmanager'),
                __('Check compliance with each tracked obligation', 'grcmanager'),
            ],
            'backup_restore_test' => [
                __('Restore a sample of files and a database', 'grcmanager'),
                __('Measure the restore time and compare it with the objective', 'grcmanager'),
                __('Attach the test report to this ticket', 'grcmanager'),
            ],
            'continuity_test' => [
                __('Run a recovery scenario (tabletop or live exercise)', 'grcmanager'),
                __('Check the emergency contacts and procedures', 'grcmanager'),
                __('Attach the report and the improvement actions', 'grcmanager'),
            ],
            'security_logs' => [
                __('Review the security alerts and logs for the period', 'grcmanager'),
                __('Open a security incident for each confirmed anomaly', 'grcmanager'),
            ],
            default => [],
        };
    }

    /**
     * Écran du plugin où se fait la revue, s'il existe.
     */
    public static function getScreenPath(string $key): ?string
    {
        return match ($key) {
            'access_rights'       => '/plugins/grcmanager/front/accessreview.php',
            'privileged_accounts' => '/plugins/grcmanager/front/accounthygiene.php',
            'network_flows'     => '/plugins/grcmanager/front/networkflow.php',
            'risk_register'     => '/plugins/grcmanager/front/risk.php',
            'supplier_risks'    => '/plugins/grcmanager/front/supplierrisk.php',
            'policies'          => '/plugins/grcmanager/front/policy.php',
            'soa'               => '/plugins/grcmanager/front/control.php',
            'legal_obligations' => '/plugins/grcmanager/front/complianceobligation.php',
            default             => null,
        };
    }

    public static function getByKey(string $key): ?self
    {
        $review = new self();
        return $review->getFromDBByCrit(['review_key' => $key]) ? $review : null;
    }

    public function getKey(): string
    {
        return (string) $this->fields['review_key'];
    }

    public function isActive(): bool
    {
        return (int) $this->fields['is_active'] === 1;
    }

    /**
     * Activer une revue demande le droit du plugin ET celui de créer des tickets récurrents : le
     * plugin ne doit pas permettre ce que GLPI refuserait à la même personne. Le gabarit et le
     * ticket récurrent sont créés à l'entité racine (récursifs) : il faut aussi y avoir accès,
     * comme dans les formulaires de GLPI (revue de sécurité 2.11.0).
     */
    public static function canManage(): bool
    {
        return Session::haveRight(self::RIGHTNAME, UPDATE)
            && Session::haveRight(TicketRecurrent::$rightname, CREATE)
            && Session::haveRight(TicketTemplate::$rightname, CREATE)
            && Session::haveAccessToEntity(0);
    }

    /**
     * Attribution acceptée seulement pour ce que proposent les listes du formulaire : un
     * utilisateur actif qui peut se voir attribuer des tickets à la racine, un groupe
     * « attribuable » ; 0 sinon.
     *
     * @return array{0: int, 1: int}
     */
    public static function validAssignees(int $usersId, int $groupsId): array
    {
        $user = new User();
        if (
            $usersId > 0
            && !(
                $user->getFromDB($usersId)
                && (int) $user->fields['is_active'] === 1
                && (int) $user->fields['is_deleted'] === 0
                && Profile::haveUserRight($usersId, Ticket::$rightname, Ticket::OWN, 0)
            )
        ) {
            $usersId = 0;
        }
        $group = new Group();
        if ($groupsId > 0 && !($group->getFromDB($groupsId) && (int) $group->fields['is_assign'] === 1)) {
            $groupsId = 0;
        }

        return [max(0, $usersId), max(0, $groupsId)];
    }

    /**
     * Active la revue (ou enregistre ses réglages si elle l'est déjà) : gabarit et ticket récurrent
     * natifs créés ou mis à jour.
     */
    public function activate(int $months, int $usersId, int $groupsId): bool
    {
        if (!self::canManage()) {
            return false;
        }
        $months = PeriodicReviewCatalog::normalizeMonths($months);
        [$usersId, $groupsId] = self::validAssignees($usersId, $groupsId);
        $wasActive = $this->isActive();
        $this->update([
            'id'               => $this->getID(),
            'frequency_months' => $months,
            'users_id_assign'  => max(0, $usersId),
            'groups_id_assign' => max(0, $groupsId),
        ]);
        $this->getFromDB($this->getID());

        $templatesId = $this->saveTemplate();
        if ($templatesId <= 0) {
            return false;
        }
        $recurrent = new TicketRecurrent();
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $periodicity = PeriodicReviewCatalog::periodicity($months);
        $recurrentInput = [
            'name'               => $this->getRecurrentName(),
            'is_active'          => 1,
            'tickettemplates_id' => $templatesId,
        ];
        $recurrentsId = (int) $this->fields['ticketrecurrents_id'];
        if ($recurrentsId > 0 && $recurrent->getFromDB($recurrentsId)) {
            // GLPI recalcule la prochaine création dès que la périodicité ou le début sont fournis
            // (CommonITILRecurrent::prepareInputForUpdate()) : ne les envoyer que s'il le faut, et
            // repartir d'aujourd'hui tant qu'aucun ticket n'a été créé, pour ne jamais repousser le
            // premier.
            if ($recurrent->fields['periodicity'] !== $periodicity) {
                $recurrentInput['periodicity'] = $periodicity;
            }
            if ((int) $this->fields['last_tickets_id'] === 0) {
                $recurrentInput['begin_date'] = $now;
            }
            $ok = $recurrent->update(['id' => $recurrentsId] + $recurrentInput);
        } else {
            $recurrentsId = (int) $recurrent->add($recurrentInput + [
                'periodicity'   => $periodicity,
                'entities_id'   => 0,
                'is_recursive'  => 1,
                'begin_date'    => $now,
                'create_before' => 0,
                'comment'       => __('Created by GLPI GRC Manager (periodic reviews).', 'grcmanager'),
            ]);
            $ok = $recurrentsId > 0;
        }
        if (!$ok) {
            return false;
        }

        return $this->update([
            'id'                  => $this->getID(),
            'is_active'           => 1,
            'tickettemplates_id'  => $templatesId,
            'ticketrecurrents_id' => $recurrentsId,
            'date_activation'     => $wasActive && !empty($this->fields['date_activation'])
                ? $this->fields['date_activation']
                : date('Y-m-d'),
        ]);
    }

    /**
     * Désactive la revue : le ticket récurrent est suspendu (rien n'est supprimé, l'historique des
     * tickets reste).
     */
    public function deactivate(): bool
    {
        $recurrent = new TicketRecurrent();
        $recurrentsId = (int) $this->fields['ticketrecurrents_id'];
        if ($recurrentsId > 0 && $recurrent->getFromDB($recurrentsId)) {
            $recurrent->update(['id' => $recurrentsId, 'is_active' => 0]);
        }

        return $this->update(['id' => $this->getID(), 'is_active' => 0]);
    }

    public function getRecurrentName(): string
    {
        return sprintf(__('Periodic review: %s', 'grcmanager'), self::getLabel($this->getKey()));
    }

    /**
     * Contenu du ticket : objet, points à vérifier, mesures concernées, lien vers l'écran du plugin.
     */
    public function buildTicketContent(): string
    {
        global $CFG_GLPI;

        $key = $this->getKey();
        $intro = __(
            'Periodic review to carry out: %s. Once the review is done, solve this ticket: its date is the evidence '
                . 'of the review.',
            'grcmanager'
        );
        $controls = PeriodicReviewCatalog::controls($key);
        $path = self::getScreenPath($key);

        // Issue #199 : contenu du ticket rendu par Twig.
        return TemplateRenderer::getInstance()->render('@grcmanager/ticket/periodic_review.html.twig', [
            'intro'        => sprintf($intro, self::getLabel($key)),
            'items'        => self::getChecklist($key),
            'requirements' => $controls !== []
                ? sprintf(__('Related requirements: %s', 'grcmanager'), implode(', ', $controls))
                : '',
            'url'          => $path !== null ? rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . $path : '',
        ]);
    }

    /**
     * Gabarit de ticket natif portant le titre, le contenu et l'attribution de la revue.
     *
     * @return int id du gabarit, 0 en cas d'échec
     */
    private function saveTemplate(): int
    {
        $template = new TicketTemplate();
        $templatesId = (int) $this->fields['tickettemplates_id'];
        $name = sprintf(__('GRC: %s', 'grcmanager'), self::getLabel($this->getKey()));
        if ($templatesId > 0 && $template->getFromDB($templatesId)) {
            $template->update(['id' => $templatesId, 'name' => $name]);
        } else {
            $templatesId = (int) $template->add([
                'name'         => $name,
                'entities_id'  => 0,
                'is_recursive' => 1,
                'comment'      => __('Created by GLPI GRC Manager (periodic reviews).', 'grcmanager'),
            ]);
            if ($templatesId <= 0) {
                return 0;
            }
        }

        $ticket = new Ticket();
        $values = [
            $ticket->getSearchOptionIDByField('field', 'name', 'glpi_tickets')    => $this->getRecurrentName(),
            $ticket->getSearchOptionIDByField('field', 'content', 'glpi_tickets') => $this->buildTicketContent(),
            5 => (int) $this->fields['users_id_assign'],  // _users_id_assign (ITILTemplate::getAllowedFields())
            8 => (int) $this->fields['groups_id_assign'], // _groups_id_assign
        ];
        $field = new TicketTemplatePredefinedField();
        foreach ($values as $num => $value) {
            $field->deleteByCriteria(['tickettemplates_id' => $templatesId, 'num' => $num]);
            if ($value !== 0 && $value !== '') {
                $field->add(['tickettemplates_id' => $templatesId, 'num' => $num, 'value' => $value]);
            }
        }

        return $templatesId;
    }

    /**
     * Hook ITEM_ADD sur Ticket : un ticket créé par le ticket récurrent d'une revue (son gabarit
     * est dans l'input, cf. CommonITILRecurrent::createItem()) devient le ticket en cours de la revue.
     *
     * GLPI ne transmet pas l'id du ticket récurrent au ticket créé : seuls un ticket créé par une
     * action automatique (Session::isCron()) et marqué « _auto_import » (comme le fait
     * createItem()) comptent. Un ticket saisi à la main avec le même gabarit ne peut donc plus
     * dater la revue (revue de sécurité 2.11.0, remarque « Quality »).
     */
    public static function onTicketAdd(Ticket $ticket): void
    {
        $templatesId = (int) ($ticket->input['tickettemplates_id'] ?? 0);
        if ($templatesId <= 0 || empty($ticket->input['_auto_import']) || !Session::isCron()) {
            return;
        }
        $review = new self();
        if ($review->getFromDBByCrit(['tickettemplates_id' => $templatesId, 'is_active' => 1])) {
            $review->update(['id' => $review->getID(), 'last_tickets_id' => $ticket->getID()]);
        }
    }

    /**
     * Hook ITEM_UPDATE sur Ticket : le ticket d'une revue résolu ou clos date la dernière revue.
     */
    public static function onTicketUpdate(Ticket $ticket): void
    {
        $done = [CommonITILObject::SOLVED, CommonITILObject::CLOSED];
        $status = (int) $ticket->fields['status'];
        if (!in_array('status', $ticket->updates, true) || !in_array($status, $done, true)) {
            return;
        }
        $review = new self();
        if (!$review->getFromDBByCrit(['last_tickets_id' => $ticket->getID()])) {
            return;
        }
        $solved = (string) ($ticket->fields['solvedate'] ?? '') ?: (string) ($ticket->fields['closedate'] ?? '');
        if ($solved === '') {
            $solved = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        }
        $review->update(['id' => $review->getID(), 'last_review_date' => substr($solved, 0, 10)]);
    }

    /**
     * Prochaine création prévue par le ticket récurrent natif, si actif.
     */
    public function getNextCreationDate(): ?string
    {
        $recurrent = new TicketRecurrent();
        $found = $this->isActive() && $recurrent->getFromDB((int) $this->fields['ticketrecurrents_id']);
        if (!$found || empty($recurrent->fields['is_active'])) {
            return null;
        }
        $next = (string) ($recurrent->fields['next_creation_date'] ?? '');
        return $next !== '' && $next !== 'NULL' ? $next : null;
    }

    public function isOverdue(string $today): bool
    {
        return $this->isActive() && PeriodicReviewCatalog::isOverdue(
            $this->fields['last_review_date'] ?: null,
            (int) $this->fields['frequency_months'],
            (string) ($this->fields['date_activation'] ?: $today),
            $today
        );
    }

    /**
     * Écran « Revues périodiques » : une ligne par revue du catalogue.
     */
    public static function showList(): void
    {
        global $DB;

        $canManage = self::canManage();
        $today = date('Y-m-d');
        $frequencies = [];
        foreach (PeriodicReviewCatalog::FREQUENCIES as $months) {
            $frequencies[$months] = self::frequencyLabel($months);
        }

        $reviews = [];
        $rows = iterator_to_array($DB->request(['FROM' => self::getTable()]), false);
        $byKey = array_column($rows, null, 'review_key');
        foreach (PeriodicReviewCatalog::keys() as $key) {
            if (!isset($byKey[$key])) {
                continue;
            }
            $review = new self();
            $review->getFromDB((int) $byKey[$key]['id']);
            $reviews[] = $review->rowData($canManage, $today);
        }

        TemplateRenderer::getInstance()->display('@grcmanager/periodic_reviews.html.twig', [
            'reviews'     => $reviews,
            'can_manage'  => $canManage,
            'frequencies' => $frequencies,
            'action'      => self::getSearchURL(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowData(bool $canManage, string $today): array
    {
        $key = $this->getKey();
        $form = 'grc-review-' . $this->getID();
        $months = (int) $this->fields['frequency_months'];
        $ticketsId = (int) $this->fields['last_tickets_id'];
        $recurrentsId = (int) $this->fields['ticketrecurrents_id'];
        $state = !$this->isActive() ? 'inactive' : ($this->isOverdue($today) ? 'overdue' : 'active');

        return [
            'id'              => $this->getID(),
            'label'           => self::getLabel($key),
            'controls'        => PeriodicReviewCatalog::controls($key),
            'months'          => $months,
            'frequency_label' => self::frequencyLabel($months),
            'state'           => $state,
            'last_review'     => (string) ($this->fields['last_review_date'] ?? ''),
            'tickets_id'      => $ticketsId,
            'ticket_url'      => $ticketsId > 0 ? Ticket::getFormURLWithID($ticketsId) : '',
            'next'            => (string) ($this->getNextCreationDate() ?? ''),
            'recurrent_url'   => $recurrentsId > 0 ? TicketRecurrent::getFormURLWithID($recurrentsId) : '',
            'owner'           => trim(getUserName((int) $this->fields['users_id_assign']) . ' '
                . Dropdown::getDropdownName('glpi_groups', (int) $this->fields['groups_id_assign'])),
            // Listes natives de GLPI, rattachées au formulaire de la ligne (attribut form).
            'user_dropdown'   => $canManage ? User::dropdown([
                'name'          => 'users_id_assign',
                'value'         => (int) $this->fields['users_id_assign'],
                'right'         => 'own_ticket',
                'addicon'       => false,
                'width'         => '100%',
                'rand'          => mt_rand(),
                'display'       => false,
                'specific_tags' => ['form' => $form],
            ]) : '',
            'group_dropdown'  => $canManage ? Group::dropdown([
                'name'          => 'groups_id_assign',
                'value'         => (int) $this->fields['groups_id_assign'],
                'condition'     => ['is_assign' => 1],
                'addicon'       => false,
                'width'         => '100%',
                'rand'          => mt_rand(),
                'display'       => false,
                'specific_tags' => ['form' => $form],
            ]) : '',
        ];
    }

    public static function frequencyLabel(int $months): string
    {
        return match ($months) {
            1       => __('Monthly', 'grcmanager'),
            3       => _x('every quarter', 'Quarterly', 'grcmanager'),
            6       => __('Every six months', 'grcmanager'),
            12      => _x('every year', 'Yearly', 'grcmanager'),
            24      => __('Every two years', 'grcmanager'),
            default => sprintf(_n('Every %d months', 'Every %d months', $months, 'grcmanager'), $months),
        };
    }
}

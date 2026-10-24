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
        return _n('Revue périodique', 'Revues périodiques', $nb, 'grcmanager');
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
            'access_rights'       => __('Revue des comptes et des droits d\'accès', 'grcmanager'),
            'privileged_accounts' => __('Revue des comptes à privilèges et des comptes de service', 'grcmanager'),
            'network_flows'       => __('Revue de la matrice des flux (règles de pare-feu)', 'grcmanager'),
            'risk_register'       => __('Revue du registre des risques', 'grcmanager'),
            'supplier_risks'      => __('Revue des risques fournisseurs', 'grcmanager'),
            'policies'            => __('Revue des politiques de sécurité', 'grcmanager'),
            'soa'                 => __('Revue de la Déclaration d\'Applicabilité', 'grcmanager'),
            'legal_obligations'   => __('Revue des obligations légales et contractuelles', 'grcmanager'),
            'backup_restore_test' => __('Test de restauration des sauvegardes', 'grcmanager'),
            'continuity_test'     => __('Test du plan de continuité et de reprise (PCA / PRA)', 'grcmanager'),
            'security_logs'       => __('Revue des journaux de sécurité', 'grcmanager'),
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
                __('Lancer ou clôturer la campagne de revue des accès', 'grcmanager'),
                __('Vérifier les comptes des personnes parties ou ayant changé de poste', 'grcmanager'),
                __('Ouvrir un ticket pour chaque droit à retirer ou modifier', 'grcmanager'),
            ],
            'privileged_accounts' => [
                __('Lister les comptes administrateurs et les comptes de service', 'grcmanager'),
                __('Confirmer pour chacun le besoin et son responsable', 'grcmanager'),
                __('Vérifier la rotation des mots de passe et l\'authentification forte', 'grcmanager'),
            ],
            'network_flows' => [
                __('Exporter les règles des pare-feu', 'grcmanager'),
                __('Vérifier que chaque flux a une justification et un propriétaire', 'grcmanager'),
                __('Supprimer les règles inutiles ou expirées (par ticket)', 'grcmanager'),
            ],
            'risk_register' => [
                __('Réévaluer la probabilité et l\'impact des risques', 'grcmanager'),
                __('Vérifier l\'avancement des plans de traitement', 'grcmanager'),
                __('Ajouter les nouveaux risques identifiés', 'grcmanager'),
            ],
            'supplier_risks' => [
                __('Réévaluer les fournisseurs critiques', 'grcmanager'),
                __('Vérifier les échéances des contrats et des attestations', 'grcmanager'),
            ],
            'policies' => [
                __('Relire chaque politique et dater la revue', 'grcmanager'),
                __('Publier les nouvelles versions et informer les personnes concernées', 'grcmanager'),
            ],
            'soa' => [
                __('Vérifier l\'applicabilité et la justification de chaque mesure', 'grcmanager'),
                __('Mettre à jour l\'état de mise en œuvre', 'grcmanager'),
                __('Demander une nouvelle approbation si la SoA a changé', 'grcmanager'),
            ],
            'legal_obligations' => [
                __('Recenser les nouvelles obligations (lois, contrats, clients)', 'grcmanager'),
                __('Vérifier la conformité de chaque obligation suivie', 'grcmanager'),
            ],
            'backup_restore_test' => [
                __('Restaurer un échantillon de fichiers et une base de données', 'grcmanager'),
                __('Mesurer la durée de restauration et la comparer à l\'objectif', 'grcmanager'),
                __('Joindre le compte rendu du test à ce ticket', 'grcmanager'),
            ],
            'continuity_test' => [
                __('Dérouler un scénario de reprise (exercice sur table ou réel)', 'grcmanager'),
                __('Vérifier les contacts et les procédures d\'urgence', 'grcmanager'),
                __('Joindre le compte rendu et les actions d\'amélioration', 'grcmanager'),
            ],
            'security_logs' => [
                __('Consulter les alertes et les journaux de sécurité de la période', 'grcmanager'),
                __('Ouvrir un incident de sécurité pour chaque anomalie avérée', 'grcmanager'),
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
     * plugin ne doit pas permettre ce que GLPI refuserait à la même personne.
     */
    public static function canManage(): bool
    {
        return Session::haveRight(self::RIGHTNAME, UPDATE)
            && Session::haveRight(TicketRecurrent::$rightname, CREATE)
            && Session::haveRight(TicketTemplate::$rightname, CREATE);
    }

    /**
     * Active la revue (ou enregistre ses réglages si elle l'est déjà) : gabarit et ticket récurrent
     * natifs créés ou mis à jour.
     */
    public function activate(int $months, int $usersId, int $groupsId): bool
    {
        $months = PeriodicReviewCatalog::normalizeMonths($months);
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
                'comment'       => __('Créé par GLPI GRC Manager (revues périodiques).', 'grcmanager'),
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
        return sprintf(__('Revue périodique : %s', 'grcmanager'), self::getLabel($this->getKey()));
    }

    /**
     * Contenu du ticket : objet, points à vérifier, mesures concernées, lien vers l'écran du plugin.
     */
    public function buildTicketContent(): string
    {
        global $CFG_GLPI;

        $key = $this->getKey();
        $intro = __(
            'Revue périodique à réaliser : %s. Une fois la revue faite, résolvez ce ticket : '
                . 'sa date sert de preuve de revue.',
            'grcmanager'
        );
        $html = '<p>' . htmlescape(sprintf($intro, self::getLabel($key))) . '</p>';
        $items = self::getChecklist($key);
        if ($items !== []) {
            $html .= '<ul><li>' . implode('</li><li>', array_map('htmlescape', $items)) . '</li></ul>';
        }
        $controls = PeriodicReviewCatalog::controls($key);
        if ($controls !== []) {
            $requirements = sprintf(__('Exigences concernées : %s', 'grcmanager'), implode(', ', $controls));
            $html .= '<p>' . htmlescape($requirements) . '</p>';
        }
        $path = self::getScreenPath($key);
        if ($path !== null) {
            $url = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . $path;
            $html .= '<p><a href="' . htmlescape($url) . '">' . htmlescape($url) . '</a></p>';
        }

        return $html;
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
        $name = sprintf(__('GRC : %s', 'grcmanager'), self::getLabel($this->getKey()));
        if ($templatesId > 0 && $template->getFromDB($templatesId)) {
            $template->update(['id' => $templatesId, 'name' => $name]);
        } else {
            $templatesId = (int) $template->add([
                'name'         => $name,
                'entities_id'  => 0,
                'is_recursive' => 1,
                'comment'      => __('Créé par GLPI GRC Manager (revues périodiques).', 'grcmanager'),
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
     */
    public static function onTicketAdd(Ticket $ticket): void
    {
        $templatesId = (int) ($ticket->input['tickettemplates_id'] ?? 0);
        if ($templatesId <= 0) {
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
        if (!in_array('status', $ticket->updates ?? [], true) || !in_array($status, $done, true)) {
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

        echo '<div class="card"><div class="card-body">';
        echo '<p class="text-muted mb-3"><i class="ti ti-info-circle me-1"></i>' . htmlescape(__(
            'Chaque revue activée devient un ticket récurrent natif de GLPI (Assistance > Tickets récurrents), '
                . 'créé à la fréquence choisie et attribué au responsable. Résoudre le ticket date la revue : '
                . 'c\'est la preuve attendue par l\'auditeur.',
            'grcmanager'
        )) . '</p>';
        if (!$canManage) {
            echo '<div class="alert alert-info">' . htmlescape(__(
                'Lecture seule : activer une revue demande le droit de modification du plugin et le droit de '
                    . 'créer des tickets récurrents et des gabarits de tickets.',
                'grcmanager'
            )) . '</div>';
        }
        echo '<div class="table-responsive"><table class="table table-hover align-middle"><thead><tr>';
        $headers = [__('Revue', 'grcmanager'), __('Fréquence', 'grcmanager'), __('Responsable', 'grcmanager'),
            __('État', 'grcmanager'), __('Dernière revue', 'grcmanager'), __('Prochain ticket', 'grcmanager'), ''];
        foreach ($headers as $header) {
            echo '<th>' . htmlescape($header) . '</th>';
        }
        echo '</tr></thead><tbody>';

        $rows = iterator_to_array($DB->request(['FROM' => self::getTable()]), false);
        $byKey = array_column($rows, null, 'review_key');
        foreach (PeriodicReviewCatalog::keys() as $key) {
            if (!isset($byKey[$key])) {
                continue;
            }
            $review = new self();
            $review->getFromDB((int) $byKey[$key]['id']);
            $review->showRow($canManage, $today);
        }
        echo '</tbody></table></div></div></div>';
    }

    private function showRow(bool $canManage, string $today): void
    {
        $key = $this->getKey();
        $form = 'grc-review-' . $this->getID();
        echo '<tr><td><strong>' . htmlescape(self::getLabel($key)) . '</strong><div class="small text-muted">';
        foreach (PeriodicReviewCatalog::controls($key) as $control) {
            echo '<span class="badge bg-secondary-lt me-1">' . htmlescape($control) . '</span>';
        }
        echo '</div></td>';

        echo '<td>';
        $months = (int) $this->fields['frequency_months'];
        if ($canManage) {
            echo '<select name="frequency_months" form="' . $form . '" class="form-select form-select-sm"'
                . ' style="min-width: 170px;" aria-label="' . htmlescape(__('Fréquence', 'grcmanager')) . '">';
            foreach (PeriodicReviewCatalog::FREQUENCIES as $option) {
                echo '<option value="' . $option . '"' . ($option === $months ? ' selected' : '') . '>'
                    . htmlescape(self::frequencyLabel($option)) . '</option>';
            }
            echo '</select>';
        } else {
            echo htmlescape(self::frequencyLabel($months));
        }
        echo '</td>';

        echo '<td style="min-width: 240px;">';
        $this->showOwner($canManage, $form);
        echo '</td>';

        echo '<td>';
        if (!$this->isActive()) {
            echo self::badge('bg-secondary-lt', 'ti-player-pause', __('Inactive', 'grcmanager'));
        } elseif ($this->isOverdue($today)) {
            echo self::badge('bg-danger-lt', 'ti-alert-triangle', __('En retard', 'grcmanager'));
        } else {
            echo self::badge('bg-success-lt', 'ti-check', __('Active', 'grcmanager'));
        }
        echo '</td>';

        $last = (string) ($this->fields['last_review_date'] ?? '');
        echo '<td>' . htmlescape($last !== '' ? (string) Html::convDate($last) : __('Jamais', 'grcmanager'));
        $ticketsId = (int) $this->fields['last_tickets_id'];
        if ($ticketsId > 0) {
            echo ' <a href="' . htmlescape(Ticket::getFormURLWithID($ticketsId)) . '" class="small">'
                . '<i class="ti ti-ticket me-1"></i>'
                . htmlescape(sprintf(__('Ticket n°%d', 'grcmanager'), $ticketsId)) . '</a>';
        }
        echo '</td>';

        $next = $this->getNextCreationDate();
        echo '<td>' . htmlescape($next !== null ? (string) Html::convDateTime($next) : '—');
        $recurrentsId = (int) $this->fields['ticketrecurrents_id'];
        if ($recurrentsId > 0) {
            echo ' <a href="' . htmlescape(TicketRecurrent::getFormURLWithID($recurrentsId)) . '" class="small"'
                . ' title="' . htmlescape(__('Ouvrir le ticket récurrent', 'grcmanager')) . '">'
                . '<i class="ti ti-external-link"></i></a>';
        }
        echo '</td>';

        echo '<td class="text-end text-nowrap">';
        if ($canManage) {
            $this->showActions($form);
        }
        echo '</td></tr>';
    }

    private function showOwner(bool $canManage, string $form): void
    {
        if (!$canManage) {
            echo htmlescape(trim(getUserName((int) $this->fields['users_id_assign']) . ' '
                . Dropdown::getDropdownName('glpi_groups', (int) $this->fields['groups_id_assign'])));
            return;
        }
        echo '<div class="small text-muted">' . htmlescape(__('Technicien', 'grcmanager')) . '</div>';
        User::dropdown([
            'name'          => 'users_id_assign',
            'value'         => (int) $this->fields['users_id_assign'],
            'right'         => 'own_ticket',
            'addicon'       => false,
            'width'         => '100%',
            'rand'          => mt_rand(),
            'specific_tags' => ['form' => $form],
        ]);
        echo '<div class="small text-muted mt-1">' . htmlescape(__('Groupe', 'grcmanager')) . '</div><div>';
        Group::dropdown([
            'name'          => 'groups_id_assign',
            'value'         => (int) $this->fields['groups_id_assign'],
            'condition'     => ['is_assign' => 1],
            'addicon'       => false,
            'width'         => '100%',
            'rand'          => mt_rand(),
            'specific_tags' => ['form' => $form],
        ]);
        echo '</div>';
    }

    private function showActions(string $form): void
    {
        echo '<form id="' . $form . '" method="post" action="' . htmlescape(self::getSearchURL()) . '"'
            . ' class="d-inline">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . htmlescape(Session::getNewCSRFToken()) . '">';
        echo '<input type="hidden" name="id" value="' . $this->getID() . '">';
        if ($this->isActive()) {
            echo self::button('activate', 'btn-primary me-1', 'ti-device-floppy', __('Enregistrer', 'grcmanager'));
            $label = __('Désactiver', 'grcmanager');
            echo self::button('deactivate', 'btn-outline-secondary', 'ti-player-pause', $label);
        } else {
            echo self::button('activate', 'btn-success', 'ti-player-play', __('Activer', 'grcmanager'));
        }
        echo '</form>';
    }

    private static function badge(string $class, string $icon, string $label): string
    {
        return '<span class="badge ' . $class . '"><i class="ti ' . $icon . ' me-1"></i>'
            . htmlescape($label) . '</span>';
    }

    private static function button(string $name, string $class, string $icon, string $label): string
    {
        return '<button type="submit" name="' . $name . '" value="1" class="btn btn-sm ' . $class . '">'
            . '<i class="ti ' . $icon . ' me-1"></i>' . htmlescape($label) . '</button>';
    }

    public static function frequencyLabel(int $months): string
    {
        return match ($months) {
            1       => __('Tous les mois', 'grcmanager'),
            3       => __('Tous les trimestres', 'grcmanager'),
            6       => __('Tous les six mois', 'grcmanager'),
            12      => __('Tous les ans', 'grcmanager'),
            24      => __('Tous les deux ans', 'grcmanager'),
            default => sprintf(_n('Tous les %d mois', 'Tous les %d mois', $months, 'grcmanager'), $months),
        };
    }
}

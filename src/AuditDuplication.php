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
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Audit\CertificationAuditRules;
use GlpiPlugin\Grcmanager\Services\Audit\ChecklistRules;
use GlpiPlugin\Grcmanager\Services\Audit\DuplicationRules as Rules;
use GlpiPlugin\Grcmanager\Services\Audit\EvidenceRequestRules;
use Html;
use Session;
use User;

/**
 * Issue #881 : onglet « Cycle suivant » d'un audit. Prépare l'audit de l'année suivante à partir
 * d'un audit passé : préparation (objectifs, critères, périmètre, mesures, processus), personnes
 * (auditeur, responsable, équipe), demandes de preuves (échéances décalées d'autant que la date de
 * l'audit) et questions de la grille. Seuls la date, le titre et, pour un audit de l'organisme,
 * le type, l'organisme et son auditeur changent.
 *
 * Le nouvel audit repart de zéro : « Planifié », sans réalisation, conclusion, constat, pièce,
 * résultat de grille ni accès d'auditeur externe. Une personne qui n'a plus de profil dans
 * l'entité de l'audit (ou dont le compte est désactivé) n'est pas reprise : elle est comptée dans
 * le message pour être remplacée à la main. Aucune notification n'est envoyée : les demandes
 * partent quand l'auditeur les relance, comme une demande créée à la main.
 *
 * Droits : lire l'audit d'origine et pouvoir créer un audit dans son entité ; la grille n'est
 * reprise que par l'équipe de l'audit d'origine (mêmes règles que son onglet).
 */
class AuditDuplication extends CommonGLPI
{
    public static $rightname = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return __('Next cycle', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-copy';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/auditduplication.form.php';
    }

    public static function canDuplicate(Audit $source): bool
    {
        return !$source->isNewItem()
            && $source->can($source->getID(), READ)
            && (bool) Session::haveRight(Audit::RIGHTNAME, CREATE)
            && (!EntityPerimeter::hasSession()
                || Session::haveAccessToEntity((int) ($source->fields['entities_id'] ?? 0)));
    }

    // ---------------------------------------------------------------------------------------------
    // Lecture
    // ---------------------------------------------------------------------------------------------

    /**
     * Ce que la recopie reprendrait de l'audit, par partie.
     *
     * @return array<string, int>
     */
    public static function counts(Audit $source): array
    {
        $id = $source->getID();

        return [
            'controls'  => count(Audit::getLinkedControlIds($id)),
            'processes' => count(AuditProgramme::processIds($id)),
            'people'    => count(self::people($source)),
            'evidence'  => count(AuditEvidenceRequest::getForAudit($id)),
            'checklist' => AuditChecklist::canViewFor($source) ? count(AuditChecklist::getLines($id)) : 0,
        ];
    }

    /**
     * Personnes de l'audit : auditeur, responsable et membres de l'équipe.
     *
     * @return list<int>
     */
    private static function people(Audit $source): array
    {
        $ids = [(int) ($source->fields['users_id'] ?? 0), (int) ($source->fields['users_id_responsible'] ?? 0)];
        foreach (AuditTeam::getMembers($source->getID()) as $member) {
            $ids[] = $member['users_id'];
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * Audit d'origine visible par l'utilisateur, ou null.
     *
     * @return array{id: int, title: string, url: string, date: string}|null
     */
    public static function sourceOf(Audit $audit): ?array
    {
        $sourceId = (int) ($audit->fields[Rules::SOURCE_FIELD] ?? 0);
        $source = new Audit();
        if ($sourceId <= 0 || !$source->getFromDB($sourceId) || !$source->can($sourceId, READ)) {
            return null;
        }

        return self::summary($source);
    }

    /**
     * Audits préparés à partir de celui-ci, visibles par l'utilisateur.
     *
     * @return list<array{id: int, title: string, url: string, date: string}>
     */
    public static function copiesOf(Audit $audit): array
    {
        global $DB;

        if (!$DB->fieldExists(Audit::getTable(), Rules::SOURCE_FIELD)) {
            return [];
        }
        $copies = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => Audit::getTable(),
                'WHERE'  => array_merge(
                    [Rules::SOURCE_FIELD => $audit->getID()],
                    EntityPerimeter::criteria(Audit::getTable())
                ),
                'ORDER'  => ['planned_date', 'id'],
            ]) as $row
        ) {
            $copy = new Audit();
            if ($copy->getFromDB((int) $row['id'])) {
                $copies[] = self::summary($copy);
            }
        }

        return $copies;
    }

    /**
     * @return array{id: int, title: string, url: string, date: string}
     */
    private static function summary(Audit $audit): array
    {
        return [
            'id'    => $audit->getID(),
            'title' => (string) $audit->fields['title'],
            'url'   => Audit::getFormURLWithID($audit->getID()),
            'date'  => (string) Html::convDate($audit->fields['planned_date'] ?? null),
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Recopie
    // ---------------------------------------------------------------------------------------------

    /**
     * Crée l'audit du cycle suivant. Renvoie son identifiant (0 en cas d'échec, message affiché) et
     * le nombre de personnes non reprises.
     *
     * @param array<string, mixed> $input title, planned_date, parts[], audit_type, suppliers_id, lead_auditor
     * @return array{id: int, dropped: int}
     */
    public static function duplicate(Audit $source, array $input): array
    {
        if (!self::canDuplicate($source)) {
            return ['id' => 0, 'dropped' => 0];
        }
        $parts = Rules::parts((array) ($input['parts'] ?? []));
        $choices = $input;
        if (!CertificationAuditRules::isExternal((string) ($source->fields['audit_type'] ?? ''))) {
            // Un audit interne reste interne : ni organisme ni auditeur de l'organisme.
            unset($choices['audit_type'], $choices['suppliers_id'], $choices['lead_auditor']);
        }
        $auditInput = Rules::auditInput($source->fields, $parts, $choices);
        if ($auditInput['title'] === '' || $auditInput['planned_date'] === null) {
            Session::addMessageAfterRedirect(
                __('Give the new audit a title and a planned date.', 'grcmanager'),
                false,
                ERROR
            );

            return ['id' => 0, 'dropped' => 0];
        }
        $withPeople = in_array(Rules::PART_PEOPLE, $parts, true);
        $withScope = in_array(Rules::PART_SCOPE, $parts, true);
        if ($withScope) {
            $auditInput['linked_controls'] = Audit::getLinkedControlIds($source->getID());
        }
        $dropped = 0;
        foreach (['users_id', 'users_id_responsible'] as $field) {
            $usersId = (int) ($auditInput[$field] ?? 0);
            if ($usersId > 0 && !self::keepsPerson($usersId, $source)) {
                $auditInput[$field] = 0;
                $dropped++;
            }
        }

        $audit = new Audit();
        $id = (int) $audit->add($auditInput);
        if ($id <= 0 || !$audit->getFromDB($id)) {
            return ['id' => 0, 'dropped' => 0];
        }
        if ($withScope) {
            AuditProgramme::setProcesses($audit, AuditProgramme::processIds($source->getID()));
        }
        if ($withPeople) {
            foreach (AuditTeam::getMembers($source->getID()) as $member) {
                if (!self::keepsPerson($member['users_id'], $audit)) {
                    $dropped++;
                    continue;
                }
                AuditTeam::addMember($id, $member['users_id'], $member['role']);
            }
        }
        if (in_array(Rules::PART_EVIDENCE, $parts, true)) {
            $dropped += self::copyEvidenceRequests($source, $audit, $withPeople);
        }
        if (in_array(Rules::PART_CHECKLIST, $parts, true) && AuditChecklist::canViewFor($source)) {
            $dropped += self::copyChecklist($source, $audit, $withPeople);
        }

        return ['id' => $id, 'dropped' => $dropped];
    }

    /** Compte actif, avec un profil dans l'entité de l'audit. */
    private static function keepsPerson(int $usersId, Audit $audit): bool
    {
        $user = new User();

        return $usersId > 0 && $user->getFromDB($usersId)
            && (int) $user->fields['is_active'] === 1 && (int) $user->fields['is_deleted'] === 0
            && EntityPerimeter::userReachesItem($usersId, $audit);
    }

    /**
     * Demandes de preuves : même référence, description, mesure et personne désignée ; échéance
     * décalée ; statut « Demandée », sans pièce, commentaire, revue ni relance.
     *
     * @return int personnes désignées non reprises
     */
    private static function copyEvidenceRequests(Audit $source, Audit $audit, bool $withPeople): int
    {
        global $DB;

        $controls = array_flip(EntityPerimeter::controlIdsByCode((int) $audit->fields['entities_id']));
        $from = $source->fields['planned_date'] ?? null;
        $to = $audit->fields['planned_date'] ?? null;
        $now = date('Y-m-d H:i:s');
        $dropped = 0;
        foreach (AuditEvidenceRequest::getForAudit($source->getID()) as $row) {
            $usersId = $withPeople ? (int) $row['users_id'] : 0;
            if ($usersId > 0 && !self::keepsPerson($usersId, $audit)) {
                $usersId = 0;
                $dropped++;
            }
            $controlId = (int) $row['plugin_grcmanager_controls_id'];
            $DB->insert(AuditEvidenceRequest::getTable(), [
                'plugin_grcmanager_audits_id'   => $audit->getID(),
                'plugin_grcmanager_controls_id' => isset($controls[$controlId]) ? $controlId : 0,
                'reference'                     => (string) $row['reference'],
                'description'                   => (string) ($row['description'] ?? ''),
                'users_id'                      => $usersId,
                'users_id_requester'            => (int) Session::getLoginUserID(),
                'due_date'                      => Rules::shiftDate($row['due_date'] ?? null, $from, $to),
                'status'                        => EvidenceRequestRules::STATUS_REQUESTED,
                'date_creation'                 => $now,
                'date_mod'                      => $now,
            ]);
        }

        return $dropped;
    }

    /**
     * Grille : mêmes exigences, questions, ordre et audités ; résultat « Non audité », sans preuve,
     * commentaire ni constat.
     *
     * @return int audités non repris
     */
    private static function copyChecklist(Audit $source, Audit $audit, bool $withPeople): int
    {
        global $DB;

        $now = date('Y-m-d H:i:s');
        $dropped = 0;
        foreach (AuditChecklist::getLines($source->getID()) as $line) {
            $auditee = $withPeople ? (int) $line['users_id_auditee'] : 0;
            if ($auditee > 0 && !self::keepsPerson($auditee, $audit)) {
                $auditee = 0;
                $dropped++;
            }
            $DB->insert(AuditChecklist::TABLE, [
                'plugin_grcmanager_audits_id' => $audit->getID(),
                'ref_type'                    => (string) $line['ref_type'],
                'items_id'                    => (int) $line['items_id'],
                'code'                        => (string) $line['code'],
                'label'                       => (string) $line['label'],
                'question'                    => (string) ($line['question'] ?? ''),
                'users_id_auditee'            => $auditee,
                'result'                      => ChecklistRules::RESULT_NOT_AUDITED,
                'rank'                        => (int) $line['rank'],
                'users_id_lastupdater'        => (int) Session::getLoginUserID(),
                'date_creation'               => $now,
                'date_mod'                    => $now,
            ]);
        }

        return $dropped;
    }

    // ---------------------------------------------------------------------------------------------
    // Onglet de l'audit
    // ---------------------------------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Audit || $item->isNewItem() || !Audit::canView()) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(), count(self::copiesOf($item)), null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof Audit || !$item->can($item->getID(), READ)) {
            return false;
        }
        self::showForAudit($item);

        return true;
    }

    public static function showForAudit(Audit $audit): void
    {
        $type = (string) ($audit->fields['audit_type'] ?? CertificationAuditRules::TYPE_INTERNAL);
        $external = CertificationAuditRules::isExternal($type);
        $plannedDate = Rules::defaultPlannedDate($audit->fields['planned_date'] ?? null, date('Y-m-d'));
        $auditTypes = CertificationFinding::getAuditTypes();

        TemplateRenderer::getInstance()->display('@grcmanager/audit_duplication_tab.html.twig', [
            'audit'         => $audit,
            'can_duplicate' => self::canDuplicate($audit),
            'action'        => self::getFormURL(),
            'source'        => self::sourceOf($audit),
            'copies'        => self::copiesOf($audit),
            'counts'        => self::counts($audit),
            'can_checklist' => AuditChecklist::canViewFor($audit),
            'external'      => $external,
            'title'         => Rules::defaultTitle(
                (string) $audit->fields['title'],
                $audit->fields['planned_date'] ?? null,
                $plannedDate
            ),
            'planned_date'  => $plannedDate,
            'next_type'     => Rules::nextAuditType($type),
            'external_types' => array_intersect_key($auditTypes, array_flip(CertificationAuditRules::externalTypes())),
            'type_label'    => $auditTypes[$type] ?? $type,
            'suppliers_id'  => (int) ($audit->fields['suppliers_id'] ?? 0),
            'lead_auditor'  => (string) ($audit->fields['lead_auditor'] ?? ''),
        ]);
    }
}

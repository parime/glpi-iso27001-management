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
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Services\Audit\EvidenceRequestRules as Rules;
use Html;
use Session;
use User;

/**
 * Issue #538 : accès en lecture d'un auditeur, interne ou externe, à un seul audit et pour une
 * durée limitée (« Espace auditeur », front/auditorspace.php).
 *
 * L'accès est donné nominativement depuis l'onglet « Demandes de preuves » de l'audit, par qui peut
 * modifier l'audit, avec une date de fin (au plus un an) ; il peut être révoqué à tout moment et
 * s'éteint de lui-même à sa date de fin, sans rien supprimer. Il ne donne aucun droit GLPI : le
 * compte de l'auditeur peut n'avoir aucun droit sur le plugin (profil de l'interface simplifiée par
 * exemple), et ne voit alors rien d'autre que son espace.
 *
 * Dans l'espace : l'audit (titre, périmètre, dates, statut), ses mesures, l'avancement, les
 * demandes déjà soumises et leurs seules pièces soumises (téléchargement historisé), et les
 * constats. Ni les documents de travail de l'audit, ni ses notes, ni les pièces en préparation.
 * Chaque visite met à jour la date de dernière visite de l'accès.
 */
class AuditorAccess extends CommonGLPI
{
    public const TABLE = 'glpi_plugin_grcmanager_auditoraccesses';

    public static function getTypeName($nb = 0)
    {
        return __('Auditor space', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-eye-check';
    }

    public static function getSpaceURL(int $auditId = 0, bool $absolute = false): string
    {
        global $CFG_GLPI;

        $base = $absolute ? rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') : $CFG_GLPI['root_doc'];

        return $base . '/plugins/grcmanager/front/auditorspace.php' . ($auditId > 0 ? '?id=' . $auditId : '');
    }

    /**
     * Accès donnés sur un audit, les plus récents d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public static function getForAudit(int $auditId): array
    {
        global $DB;

        $today = date('Y-m-d');
        $list = [];
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['plugin_grcmanager_audits_id' => $auditId],
                'ORDER' => ['id DESC'],
            ]) as $row
        ) {
            $active = Rules::isAccessActive($row['date_begin'], $row['date_end'], $row['date_revoked'], $today);
            $state = match (true) {
                $row['date_revoked'] !== null => 'revoked',
                $active                       => 'active',
                default                       => 'expired',
            };
            $list[] = [
                'id'         => (int) $row['id'],
                'users_id'   => (int) $row['users_id'],
                'name'       => (string) getUserName((int) $row['users_id']),
                'begin'      => (string) Html::convDate($row['date_begin']),
                'end'        => (string) Html::convDate($row['date_end']),
                'state'      => $state,
                'grantor'    => (int) $row['users_id_grantor'] > 0 ? (string) getUserName((int) $row['users_id_grantor']) : '',
                'revoked'    => $row['date_revoked'] !== null ? (string) Html::convDateTime($row['date_revoked']) : '',
                'last_visit' => $row['date_last_visit'] !== null ? (string) Html::convDateTime($row['date_last_visit']) : '',
            ];
        }

        return $list;
    }

    /**
     * Donne (ou prolonge) l'accès d'un compte actif à l'audit jusqu'à la date de fin saisie.
     *
     * @return string message d'erreur, chaîne vide si l'accès est enregistré
     */
    public static function grant(int $auditId, int $usersId, mixed $endInput, int $grantorId): string
    {
        global $DB;

        $user = new User();
        if (
            $usersId <= 0 || !$user->getFromDB($usersId) || (int) $user->fields['is_active'] !== 1
            || (int) $user->fields['is_deleted'] !== 0
        ) {
            return __('Choose an active account.', 'grcmanager');
        }
        $today = date('Y-m-d');
        $end = Rules::accessEnd($endInput, $today);
        if ($end === null) {
            return sprintf(
                __('Give an end date between today and %d days from now.', 'grcmanager'),
                Rules::MAX_ACCESS_DAYS
            );
        }
        $now = date('Y-m-d H:i:s');
        $current = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [
                'plugin_grcmanager_audits_id' => $auditId,
                'users_id'                    => $usersId,
                'date_revoked'                => null,
                'date_end'                    => ['>=', $today],
            ],
            'ORDER' => ['id DESC'],
            'LIMIT' => 1,
        ])->current();
        if ($current !== null) {
            $DB->update(self::TABLE, ['date_end' => $end, 'users_id_grantor' => $grantorId], ['id' => $current['id']]);

            return '';
        }
        $DB->insert(self::TABLE, [
            'plugin_grcmanager_audits_id' => $auditId,
            'users_id'                    => $usersId,
            'date_begin'                  => $today,
            'date_end'                    => $end,
            'users_id_grantor'            => $grantorId,
            'date_creation'               => $now,
        ]);

        return '';
    }

    public static function revoke(int $auditId, int $accessId, int $revokerId): bool
    {
        global $DB;

        $DB->update(self::TABLE, [
            'date_revoked'     => date('Y-m-d H:i:s'),
            'users_id_revoker' => $revokerId,
        ], ['id' => $accessId, 'plugin_grcmanager_audits_id' => $auditId, 'date_revoked' => null]);

        return $DB->affectedRows() > 0;
    }

    /**
     * Accès en vigueur de la personne sur l'audit, ou null.
     *
     * @return array<string, mixed>|null
     */
    public static function getActive(int $auditId, int $usersId, ?string $today = null): ?array
    {
        global $DB;

        if ($auditId <= 0 || $usersId <= 0) {
            return null;
        }
        $today ??= date('Y-m-d');
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['plugin_grcmanager_audits_id' => $auditId, 'users_id' => $usersId],
                'ORDER' => ['id DESC'],
            ]) as $row
        ) {
            if (Rules::isAccessActive($row['date_begin'], $row['date_end'], $row['date_revoked'], $today)) {
                return $row;
            }
        }

        return null;
    }

    public static function isActive(int $auditId, int $usersId, ?string $today = null): bool
    {
        return self::getActive($auditId, $usersId, $today) !== null;
    }

    /**
     * Audits que la personne peut consulter aujourd'hui comme auditeur.
     *
     * @return list<array{id: int, title: string, date_end: string, end_text: string}>
     */
    public static function getActiveAudits(int $usersId): array
    {
        global $DB;

        if ($usersId <= 0) {
            return [];
        }
        $today = date('Y-m-d');
        $audits = [];
        foreach (
            $DB->request([
                'SELECT'     => ['x.plugin_grcmanager_audits_id', 'x.date_begin', 'x.date_end', 'x.date_revoked', 'a.title'],
                'FROM'       => self::TABLE . ' AS x',
                'INNER JOIN' => [Audit::getTable() . ' AS a' => ['FKEY' => ['x' => 'plugin_grcmanager_audits_id', 'a' => 'id']]],
                'WHERE'      => ['x.users_id' => $usersId, 'x.date_revoked' => null],
                'ORDER'      => ['x.date_end DESC'],
            ]) as $row
        ) {
            $auditId = (int) $row['plugin_grcmanager_audits_id'];
            if (isset($audits[$auditId]) || !Rules::isAccessActive($row['date_begin'], $row['date_end'], null, $today)) {
                continue;
            }
            $audits[$auditId] = [
                'id'       => $auditId,
                'title'    => (string) $row['title'],
                'date_end' => (string) $row['date_end'],
                'end_text' => (string) Html::convDate($row['date_end']),
            ];
        }

        return array_values($audits);
    }

    public static function purgeAudit(int $auditId): void
    {
        global $DB;

        $DB->delete(self::TABLE, ['plugin_grcmanager_audits_id' => $auditId]);
    }

    /**
     * Contenu de l'espace auditeur pour un audit dont l'accès est en vigueur (vérifié par l'appelant).
     *
     * @return array<string, mixed>
     */
    public static function buildSpace(Audit $audit, int $usersId): array
    {
        $auditId = $audit->getID();
        $requests = [];
        foreach (AuditEvidenceRequest::getForAudit($auditId) as $row) {
            if (Rules::isVisibleToAuditor((string) $row['status'], $row['date_submitted'] ?: null)) {
                $requests[] = AuditEvidenceRequest::present($row, $usersId, true);
            }
        }
        $controls = [];
        $implementation = Control::getImplementationStatuses();
        foreach (array_keys(Audit::getLinkedControls($auditId)) as $controlId) {
            $control = new Control();
            if (!$control->getFromDB($controlId)) {
                continue;
            }
            $status = (string) $control->fields['implementation_status'];
            $controls[] = [
                'code'           => (string) $control->fields['code'],
                'title'          => Control::getControlTitle((string) $control->fields['code']),
                'implementation' => $implementation[$status] ?? $status,
                'status'         => $status,
            ];
        }
        $access = self::getActive($auditId, $usersId);

        return [
            'audit'        => [
                'id'      => $auditId,
                'title'   => (string) $audit->fields['title'],
                'scope'   => (string) ($audit->fields['scope'] ?? ''),
                'planned' => (string) Html::convDate($audit->fields['planned_date'] ?? null),
                'actual'  => (string) Html::convDate($audit->fields['actual_date'] ?? null),
                'status'  => Audit::getStatuses()[$audit->fields['status'] ?? ''] ?? (string) ($audit->fields['status'] ?? ''),
                'auditor' => (int) $audit->fields['users_id'] > 0 ? (string) getUserName((int) $audit->fields['users_id']) : '',
            ],
            'controls'     => $controls,
            'requests'     => $requests,
            'progress'     => AuditEvidenceRequest::progressForAudit($auditId),
            'findings'     => Audit::getFindings($auditId),
            'access_end'   => $access !== null ? (string) Html::convDate($access['date_end']) : '',
        ];
    }

    /** Date de dernière visite de l'accès en vigueur. */
    public static function touch(int $auditId, int $usersId): void
    {
        global $DB;

        $access = self::getActive($auditId, $usersId);
        if ($access !== null) {
            $DB->update(self::TABLE, ['date_last_visit' => date('Y-m-d H:i:s')], ['id' => $access['id']]);
        }
    }

    /**
     * En-tête des pages ouvertes sans droit sur le plugin (espace auditeur, demandes de preuves) :
     * celui de l'interface simplifiée pour un profil de cette interface.
     */
    public static function header(string $title, string $url): void
    {
        if (Session::getCurrentInterface() === 'helpdesk') {
            Html::helpHeader($title);

            return;
        }
        Html::header($title, $url, 'grcmanager', MySpace::class);
    }

    public static function footer(): void
    {
        if (Session::getCurrentInterface() === 'helpdesk') {
            Html::helpFooter();

            return;
        }
        Html::footer();
    }

    /**
     * Entrée « GRC Manager » du menu de l'interface simplifiée : l'espace auditeur pour qui a un
     * accès en vigueur, sinon ses demandes de preuves en attente ; aucune entrée sinon.
     */
    public static function helpdeskMenuEntry(): ?string
    {
        $usersId = (int) Session::getLoginUserID();
        if ($usersId <= 0 || Session::getCurrentInterface() !== 'helpdesk') {
            return null;
        }
        if (self::getActiveAudits($usersId) !== []) {
            return '/front/auditorspace.php';
        }

        return AuditEvidenceRequest::countWaiting($usersId) > 0 ? '/front/auditevidencerequest.mine.php' : null;
    }

    public static function showSpace(Audit $audit, int $usersId): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/auditor_space.html.twig', self::buildSpace($audit, $usersId) + [
            'audits'   => self::getActiveAudits($usersId),
            'list_url' => self::getSpaceURL(),
        ]);
    }

    public static function showList(int $usersId): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/auditor_space.html.twig', [
            'audit'    => null,
            'audits'   => self::getActiveAudits($usersId),
            'list_url' => self::getSpaceURL(),
        ]);
    }
}

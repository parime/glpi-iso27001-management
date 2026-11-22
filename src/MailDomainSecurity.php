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
use GlpiPlugin\Grcmanager\Install\MailDomainTables;
use GlpiPlugin\Grcmanager\Services\MailDomain\DnsReader;
use GlpiPlugin\Grcmanager\Services\MailDomain\MailDomainRules as Rules;
use GlpiPlugin\Grcmanager\Services\MailDomain\SystemDnsReader;
use Html;
use Session;

/**
 * Issue #855 : sécurité de la messagerie et des domaines, pour les mesures A.5.14 (transfert de
 * l'information) et A.8.21 (sécurité des services réseau).
 *
 * - Domaines de l'organisation saisis dans le plugin, par entité. Sans domaine, aucune requête
 *   n'est faite : le module est inactif par défaut.
 * - Lecture DNS publique sur clic seulement (SPF, sélecteurs DKIM déclarés, DMARC, MTA-STS,
 *   TLS-RPT, DNSSEC) : aucun e-mail envoyé, aucune connexion aux serveurs de messagerie, aucun
 *   service tiers autre que les serveurs DNS.
 * - Échéances du domaine et du certificat des serveurs de messagerie saisies, alerte 30 jours avant.
 * - Écarts signalés avec un conseil, preuve sur les mesures et KRI. Rien n'est modifié dans le DNS
 *   ni dans GLPI.
 *
 * Droits : droit du plugin en lecture, dans les entités de la session ; modification du plugin pour
 * saisir les domaines et lancer la lecture.
 */
class MailDomainSecurity extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    /** Mesures dont la fiche affiche la preuve. */
    public const CONTROLS = ['A.5.14', 'A.8.21'];

    /** Domaines lus au plus par un clic sur « Vérifier tous les domaines ». */
    public const MAX_CHECK_ALL = 50;

    /** Domaines listés au plus. */
    public const MAX_ROWS = 300;

    public static function getTypeName($nb = 0)
    {
        return __('Mail and domain security', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-mail-check';
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/maildomainsecurity.php';
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::RIGHTNAME, READ);
    }

    /**
     * Saisie des domaines et lecture DNS.
     */
    public static function canManage(): bool
    {
        return self::canView() && (bool) Session::haveRight(self::RIGHTNAME, UPDATE);
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        // Sous-page de la rubrique « Sécurité technique » (Menu::sections()).
        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    // ------------------------------------------------------------------ Libellés.

    /**
     * Libellé et conseil de chaque écart (le conseil peut citer le domaine avec %s).
     *
     * @return array<string, array{label: string, advice: string}>
     */
    public static function findingInfos(): array
    {
        return [
            'unresolved' => [
                'label'  => __('The domain was not found in the DNS.', 'grcmanager'),
                'advice' => __('Check the spelling of the domain, or remove it if it is no longer used.', 'grcmanager'),
            ],
            'spf_missing' => [
                'label'  => __(
                    'No SPF record: anyone can send e-mails pretending to come from this domain.',
                    'grcmanager'
                ),
                'advice' => __(
                    'Publish on %s a TXT record "v=spf1 ... -all" listing the servers allowed to send its e-mails '
                        . '(for a domain that never sends e-mail: "v=spf1 -all").',
                    'grcmanager'
                ),
            ],
            'spf_multiple' => [
                'label'  => __('Several SPF records: receiving servers ignore all of them.', 'grcmanager'),
                'advice' => __('Merge them into a single TXT record starting with v=spf1.', 'grcmanager'),
            ],
            'spf_pass_all' => [
                'label'  => __('SPF allows every server in the world to send for the domain (+all).', 'grcmanager'),
                'advice' => __('Replace +all with -all (or ~all while you check the list of servers).', 'grcmanager'),
            ],
            'spf_neutral' => [
                'label'  => __('SPF does not say what to do with the other servers (?all or no all).', 'grcmanager'),
                'advice' => __('End the record with -all, or ~all while you check the list of servers.', 'grcmanager'),
            ],
            'spf_too_many_lookups' => [
                'label'  => __('SPF needs more than 10 DNS lookups: receiving servers may reject it.', 'grcmanager'),
                'advice' => __('Reduce the number of include, a and mx mechanisms of the record.', 'grcmanager'),
            ],
            'spf_softfail' => [
                'label'  => __('SPF only marks the other servers as suspicious (~all).', 'grcmanager'),
                'advice' => __('Move to -all once every service that sends e-mail for you is listed.', 'grcmanager'),
            ],
            'dmarc_missing' => [
                'label'  => __(
                    'No DMARC policy: receiving servers do not know what to do with fake e-mails.',
                    'grcmanager'
                ),
                'advice' => __(
                    'Publish a TXT record on _dmarc.%s starting with "v=DMARC1; p=none; rua=mailto:...", read the '
                        . 'reports, then move to p=quarantine and p=reject.',
                    'grcmanager'
                ),
            ],
            'dmarc_invalid' => [
                'label'  => __('The DMARC record is invalid or published several times.', 'grcmanager'),
                'advice' => __('Keep a single record with p=none, p=quarantine or p=reject.', 'grcmanager'),
            ],
            'dmarc_none' => [
                'label'  => __('DMARC only observes (p=none): fake e-mails are still delivered.', 'grcmanager'),
                'advice' => __('After reading the reports, move to p=quarantine, then p=reject.', 'grcmanager'),
            ],
            'dmarc_partial' => [
                'label'  => __('DMARC applies to only part of the e-mails (pct below 100).', 'grcmanager'),
                'advice' => __('Raise pct to 100, or remove it.', 'grcmanager'),
            ],
            'dmarc_no_reports' => [
                'label'  => __('DMARC sends no report (no rua address).', 'grcmanager'),
                'advice' => __(
                    'Add rua=mailto:... to receive the reports of the receiving servers.',
                    'grcmanager'
                ),
            ],
            'dkim_missing' => [
                'label'  => __('A declared DKIM selector has no published key.', 'grcmanager'),
                'advice' => __(
                    'Check the selector name given by your e-mail service, or publish its key.',
                    'grcmanager'
                ),
            ],
            'dkim_revoked' => [
                'label'  => __('A DKIM key is revoked (empty key).', 'grcmanager'),
                'advice' => __('Remove the selector from the list if it is no longer used.', 'grcmanager'),
            ],
            'dkim_not_declared' => [
                'label'  => __('No DKIM selector declared: the signature of the e-mails is not checked.', 'grcmanager'),
                'advice' => __(
                    'Enter the selectors given by your e-mail service (for example selector1 and selector2 for '
                        . 'Microsoft 365, google for Google Workspace).',
                    'grcmanager'
                ),
            ],
            'dnssec_missing' => [
                'label'  => __('The domain is not signed with DNSSEC: its DNS answers can be forged.', 'grcmanager'),
                'advice' => __('Enable DNSSEC with your registrar or DNS host.', 'grcmanager'),
            ],
            'dnssec_unknown' => [
                'label'  => __('DNSSEC could not be checked (no answer from the DNS server).', 'grcmanager'),
                'advice' => __('Check the domain again later.', 'grcmanager'),
            ],
            'mta_sts_missing' => [
                'label'  => __('No MTA-STS: encryption between mail servers is not required.', 'grcmanager'),
                'advice' => __(
                    'Publish an MTA-STS policy (record _mta-sts.%s and policy file) once your mail servers '
                        . 'accept encrypted connections.',
                    'grcmanager'
                ),
            ],
            'tls_rpt_missing' => [
                'label'  => __('No TLS-RPT: you receive no report of encryption failures.', 'grcmanager'),
                'advice' => __(
                    'Publish a TXT record on _smtp._tls.%s: "v=TLSRPTv1; rua=mailto:...".',
                    'grcmanager'
                ),
            ],
            'domain_expired' => [
                'label'  => __('The registration of the domain has expired.', 'grcmanager'),
                'advice' => __(
                    'Renew it with the registrar at once: an expired domain can be bought by someone else.',
                    'grcmanager'
                ),
            ],
            'domain_expiring' => [
                'label'  => __('The registration of the domain expires within 30 days.', 'grcmanager'),
                'advice' => __('Renew it, or enable automatic renewal with the registrar.', 'grcmanager'),
            ],
            'domain_expiry_unknown' => [
                'label'  => __('Expiry date of the domain not entered.', 'grcmanager'),
                'advice' => __(
                    'Enter the date shown by the registrar to be alerted before it expires.',
                    'grcmanager'
                ),
            ],
            'cert_expired' => [
                'label'  => __('The certificate of the mail servers has expired.', 'grcmanager'),
                'advice' => __(
                    'Renew it: other servers may refuse to deliver e-mail securely.',
                    'grcmanager'
                ),
            ],
            'cert_expiring' => [
                'label'  => __('The certificate of the mail servers expires within 30 days.', 'grcmanager'),
                'advice' => __('Plan its renewal.', 'grcmanager'),
            ],
        ];
    }

    /**
     * Badge de gravité d'un écart.
     *
     * @return array<string, array{label: string, icon: string}>
     */
    public static function severityInfos(): array
    {
        return [
            Rules::SEVERITY_DANGER  => ['label' => __('To fix', 'grcmanager'), 'icon' => 'ti ti-alert-octagon'],
            Rules::SEVERITY_WARNING => ['label' => __('To improve', 'grcmanager'), 'icon' => 'ti ti-alert-triangle'],
            Rules::SEVERITY_INFO    => ['label' => __('Advice', 'grcmanager'), 'icon' => 'ti ti-info-circle'],
            'success'               => ['label' => __('No gap', 'grcmanager'), 'icon' => 'ti ti-circle-check'],
            'secondary'             => ['label' => __('Not checked yet', 'grcmanager'), 'icon' => 'ti ti-help-circle'],
        ];
    }

    /**
     * Message d'une saisie refusée.
     */
    public static function errorMessage(string $code): string
    {
        return match ($code) {
            'name'      => __(
                'Enter a domain name only, for example example.org (no e-mail address, no https://).',
                'grcmanager'
            ),
            'duplicate' => __('This domain is already followed in this entity.', 'grcmanager'),
            'not_found' => __('Domain not found.', 'grcmanager'),
            default     => __('You do not have the right to change the domains.', 'grcmanager'),
        };
    }

    // ------------------------------------------------------------------ Domaines.

    /**
     * Domaines des entités de la session, par nom.
     *
     * @return list<array<string, mixed>>
     */
    public static function domains(): array
    {
        global $DB;

        if (!self::canView()) {
            return [];
        }
        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => MailDomainTables::DOMAINS,
                'WHERE' => getEntitiesRestrictCriteria(MailDomainTables::DOMAINS),
                'ORDER' => ['name'],
                'LIMIT' => self::MAX_ROWS,
            ]) as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Ligne d'un domaine des entités de la session, ou null.
     *
     * @return array<string, mixed>|null
     */
    private static function domain(int $id): ?array
    {
        global $DB;

        if ($id <= 0 || !self::canView()) {
            return null;
        }
        foreach (
            $DB->request([
                'FROM'  => MailDomainTables::DOMAINS,
                'WHERE' => ['id' => $id] + getEntitiesRestrictCriteria(MailDomainTables::DOMAINS),
                'LIMIT' => 1,
            ]) as $row
        ) {
            return $row;
        }

        return null;
    }

    /**
     * Champs saisis, validés.
     *
     * @param array<string, mixed> $input
     * @return array{name: ?string, dkim_selectors: string, domain_expiry: ?string, certificate_expiry: ?string,
     *     comment: string}
     */
    private static function input(array $input): array
    {
        return [
            'name'               => Rules::normalizeDomain((string) ($input['name'] ?? '')),
            'dkim_selectors'     => implode(', ', Rules::normalizeSelectors((string) ($input['dkim_selectors'] ?? ''))),
            'domain_expiry'      => Rules::normalizeDate((string) ($input['domain_expiry'] ?? '')),
            'certificate_expiry' => Rules::normalizeDate((string) ($input['certificate_expiry'] ?? '')),
            'comment'            => mb_substr(trim((string) ($input['comment'] ?? '')), 0, 255),
        ];
    }

    /**
     * Ajoute un domaine dans l'entité active. Aucune lecture DNS n'est lancée.
     *
     * @param array<string, mixed> $input
     * @return array{id: int, error: string}
     */
    public static function add(array $input): array
    {
        global $DB;

        if (!self::canManage()) {
            return ['id' => 0, 'error' => 'rights'];
        }
        $fields = self::input($input);
        if ($fields['name'] === null) {
            return ['id' => 0, 'error' => 'name'];
        }
        $entity = (int) Session::getActiveEntity();
        $same = ['entities_id' => $entity, 'name' => $fields['name']];
        if (countElementsInTable(MailDomainTables::DOMAINS, $same) > 0) {
            return ['id' => 0, 'error' => 'duplicate'];
        }
        $now = Session::getCurrentTime() ?? date('Y-m-d H:i:s');
        $DB->insert(MailDomainTables::DOMAINS, $fields + [
            'entities_id'   => $entity,
            'users_id'      => (int) Session::getLoginUserID(),
            'date_creation' => $now,
            'date_mod'      => $now,
        ]);
        $id = (int) $DB->insertId();
        AuditLog::record('create', self::class, $id, 0, 'success', ['domain' => $fields['name']]);

        return ['id' => $id, 'error' => ''];
    }

    /**
     * Modifie un domaine des entités de la session. Un changement de nom efface la dernière lecture.
     *
     * @param array<string, mixed> $input
     */
    public static function update(int $id, array $input): string
    {
        global $DB;

        if (!self::canManage()) {
            return 'rights';
        }
        $row = self::domain($id);
        if ($row === null) {
            return 'not_found';
        }
        $fields = self::input($input);
        if ($fields['name'] === null) {
            return 'name';
        }
        $same = [
            'entities_id' => (int) $row['entities_id'],
            'name'        => $fields['name'],
            'NOT'         => ['id' => $id],
        ];
        if (countElementsInTable(MailDomainTables::DOMAINS, $same) > 0) {
            return 'duplicate';
        }
        $changes = $fields + ['date_mod' => Session::getCurrentTime() ?? date('Y-m-d H:i:s')];
        if ($fields['name'] !== $row['name'] || $fields['dkim_selectors'] !== $row['dkim_selectors']) {
            $changes += ['last_check' => null, 'result' => null];
        }
        $DB->update(MailDomainTables::DOMAINS, $changes, ['id' => $id]);
        AuditLog::record('update', self::class, $id, 0, 'success', ['domain' => $fields['name']]);

        return '';
    }

    /**
     * Supprime un domaine des entités de la session.
     */
    public static function delete(int $id): bool
    {
        global $DB;

        if (!self::canManage() || self::domain($id) === null) {
            return false;
        }
        $ok = (bool) $DB->delete(MailDomainTables::DOMAINS, ['id' => $id]);
        AuditLog::record('purge', self::class, $id, 0, 'success');

        return $ok;
    }

    /**
     * Lecture DNS publique d'un domaine, sur clic, et enregistrement du résultat.
     */
    public static function check(int $id, ?DnsReader $reader = null): bool
    {
        global $DB;

        if (!self::canManage()) {
            return false;
        }
        $row = self::domain($id);
        if ($row === null) {
            return false;
        }
        $selectors = Rules::normalizeSelectors((string) $row['dkim_selectors']);
        $result = self::read((string) $row['name'], $selectors, $reader);
        $DB->update(MailDomainTables::DOMAINS, [
            'last_check' => Session::getCurrentTime() ?? date('Y-m-d H:i:s'),
            'result'     => (string) json_encode($result, JSON_UNESCAPED_SLASHES),
        ], ['id' => $id]);

        return true;
    }

    /**
     * Lecture de tous les domaines des entités de la session (au plus MAX_CHECK_ALL).
     */
    public static function checkAll(?DnsReader $reader = null): int
    {
        if (!self::canManage()) {
            return 0;
        }
        $count = 0;
        foreach (array_slice(self::domains(), 0, self::MAX_CHECK_ALL) as $row) {
            if (self::check((int) $row['id'], $reader)) {
                $count++;
            }
        }
        if ($count > 0) {
            AuditLog::record('verify', self::class, 0, 0, 'success', ['domains' => $count]);
        }

        return $count;
    }

    /**
     * Questions DNS d'un domaine. Seuls des enregistrements publics sont demandés.
     *
     * @param list<string> $selectors
     * @return array<string, mixed>
     */
    public static function read(string $name, array $selectors, ?DnsReader $reader = null): array
    {
        $reader ??= new SystemDnsReader();
        if (!$reader->exists($name)) {
            return Rules::analyse([
                'resolved' => false, 'mx' => [], 'txt' => [], 'dmarc' => [], 'mta_sts' => [], 'tls_rpt' => [],
                'dkim' => [], 'dnssec' => null,
            ]);
        }
        $dkim = [];
        foreach ($selectors as $selector) {
            $dkim[$selector] = $reader->txt($selector . '._domainkey.' . $name);
        }

        return Rules::analyse([
            'resolved' => true,
            'mx'       => $reader->mx($name),
            'txt'      => $reader->txt($name),
            'dmarc'    => $reader->txt('_dmarc.' . $name),
            'mta_sts'  => $reader->txt('_mta-sts.' . $name),
            'tls_rpt'  => $reader->txt('_smtp._tls.' . $name),
            'dkim'     => $dkim,
            'dnssec'   => $reader->hasDs($name),
        ]);
    }

    /**
     * Résultat enregistré d'un domaine, null si jamais lu.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private static function result(array $row): ?array
    {
        if (!is_string($row['result'] ?? null) || $row['result'] === '') {
            return null;
        }
        $result = json_decode($row['result'], true);

        return is_array($result) ? $result : null;
    }

    private static function today(): string
    {
        return substr(Session::getCurrentTime() ?? date('Y-m-d H:i:s'), 0, 10);
    }

    // ------------------------------------------------------------------ Page.

    /**
     * Badges d'un domaine (couleur, icône, texte) : aucun HTML.
     *
     * @param array<string, mixed>|null $result
     * @param array<string, mixed> $row
     * @return array<string, array{color: string, icon: string, text: string, title: string}>
     */
    private static function badges(?array $result, array $row): array
    {
        $badge = static fn (string $color, string $icon, string $text, string $title = ''): array => [
            'color' => $color, 'icon' => $icon, 'text' => $text, 'title' => $title,
        ];
        $unread = $badge('secondary', 'ti ti-help-circle', __('Not checked yet', 'grcmanager'));
        $badges = [];
        if ($result === null || empty($result['resolved'])) {
            foreach (['spf', 'dkim', 'dmarc', 'mta_sts', 'tls_rpt', 'dnssec'] as $key) {
                $badges[$key] = $result === null
                    ? $unread
                    : $badge('danger', 'ti ti-world-off', __('Domain not found', 'grcmanager'));
            }
        } else {
            $spf = is_array($result['spf'] ?? null) ? $result['spf'] : [];
            $rec = (string) ($spf['record'] ?? '');
            $state = (string) ($spf['status'] ?? '');
            $all = (string) ($spf['all'] ?? '');
            $badges['spf'] = match (true) {
                $state === 'missing'  => $badge('danger', 'ti ti-x', __('Missing', 'grcmanager')),
                $state === 'multiple' => $badge('danger', 'ti ti-copy', __('Several records', 'grcmanager'), $rec),
                $all === '-all'       => $badge('success', 'ti ti-lock', __('Strict (-all)', 'grcmanager'), $rec),
                $all === '~all'       => $badge('info', 'ti ti-lock-open', __('Lenient (~all)', 'grcmanager'), $rec),
                $all === '+all'       => $badge('danger', 'ti ti-lock-off', __('Anyone (+all)', 'grcmanager'), $rec),
                $all === 'redirect'   => $badge('info', 'ti ti-arrow-forward', __('Redirected', 'grcmanager'), $rec),
                default               => $badge('warning', 'ti ti-question-mark', __('Neutral', 'grcmanager'), $rec),
            };
            $dmarc = is_array($result['dmarc'] ?? null) ? $result['dmarc'] : [];
            $rec = (string) ($dmarc['record'] ?? '');
            $state = (string) ($dmarc['status'] ?? '');
            $policy = (string) ($dmarc['policy'] ?? '');
            $badges['dmarc'] = match (true) {
                $state === 'missing'     => $badge('danger', 'ti ti-x', __('Missing', 'grcmanager')),
                $state !== 'ok'          => $badge('danger', 'ti ti-alert-octagon', __('Invalid', 'grcmanager'), $rec),
                $policy === 'reject'     => $badge('success', 'ti ti-shield-check', __('Reject', 'grcmanager'), $rec),
                $policy === 'quarantine' => $badge('success', 'ti ti-shield', __('Quarantine', 'grcmanager'), $rec),
                default                  => $badge('warning', 'ti ti-eye', __('Monitoring (none)', 'grcmanager'), $rec),
            };
            $dkim = is_array($result['dkim'] ?? null) ? $result['dkim'] : [];
            $found = count(array_filter($dkim, static fn (mixed $status): bool => $status === 'ok'));
            $badges['dkim'] = $dkim === []
                ? $badge('secondary', 'ti ti-signature-off', __('No selector declared', 'grcmanager'))
                : $badge(
                    $found === count($dkim) ? 'success' : 'warning',
                    'ti ti-signature',
                    sprintf(__('%1$d of %2$d keys found', 'grcmanager'), $found, count($dkim)),
                    implode(', ', array_map(
                        static fn (string $selector, mixed $status): string => $selector . ' : ' . (string) $status,
                        array_keys($dkim),
                        $dkim
                    ))
                );
            $hasMx = !empty($result['mx']);
            foreach (['mta_sts', 'tls_rpt'] as $key) {
                $badges[$key] = !empty($result[$key])
                    ? $badge('success', 'ti ti-check', __('Published', 'grcmanager'))
                    : $badge($hasMx ? 'warning' : 'secondary', 'ti ti-minus', __('Not published', 'grcmanager'));
            }
            $badges['dnssec'] = match ($result['dnssec'] ?? null) {
                true    => $badge('success', 'ti ti-shield-lock', __('Signed', 'grcmanager')),
                false   => $badge('warning', 'ti ti-shield-x', __('Not signed', 'grcmanager')),
                default => $badge('secondary', 'ti ti-help-circle', __('Unknown', 'grcmanager')),
            };
        }
        $dates = ['domain_expiry' => 'ti ti-calendar-due', 'certificate_expiry' => 'ti ti-certificate'];
        foreach ($dates as $key => $icon) {
            $date = is_string($row[$key] ?? null) ? $row[$key] : null;
            $expiry = Rules::expiry($date, self::today());
            $label = $date === null ? '' : (string) Html::convDate($date);
            $badges[$key] = match ($expiry['status']) {
                Rules::EXPIRY_EXPIRED => $badge('danger', $icon, sprintf(__('Expired on %s', 'grcmanager'), $label)),
                Rules::EXPIRY_SOON    => $badge('warning', $icon, sprintf(
                    _n('In %d day', 'In %d days', (int) $expiry['days'], 'grcmanager'),
                    (int) $expiry['days']
                ), $label),
                Rules::EXPIRY_OK      => $badge('success', $icon, $label),
                default               => $badge('secondary', $icon, __('Not entered', 'grcmanager')),
            };
        }

        return $badges;
    }

    /**
     * Domaines avec badges et écarts, et synthèse.
     *
     * @return array{domains: list<array<string, mixed>>, summary: array<string, int>,
     *     gaps: list<array<string, mixed>>}
     */
    public static function overview(): array
    {
        $infos = self::findingInfos();
        $today = self::today();
        $domains = [];
        $gaps = [];
        $summary = ['total' => 0, 'checked' => 0, 'dmarc_enforced' => 0, 'to_fix' => 0, 'expiring' => 0];
        foreach (self::domains() as $row) {
            $result = self::result($row);
            $findings = Rules::findings(
                $result,
                is_string($row['domain_expiry'] ?? null) ? $row['domain_expiry'] : null,
                is_string($row['certificate_expiry'] ?? null) ? $row['certificate_expiry'] : null,
                $today
            );
            $name = (string) $row['name'];
            $listed = [];
            foreach ($findings as $finding) {
                $entry = $finding + [
                    'label'  => $infos[$finding['code']]['label'] ?? $finding['code'],
                    'advice' => sprintf($infos[$finding['code']]['advice'] ?? '', $name),
                    'domain' => $name,
                    'id'     => (int) $row['id'],
                ];
                $listed[] = $entry;
                $gaps[] = $entry;
            }
            // Domaine jamais lu : pas de verdict tant que la lecture DNS n'a pas eu lieu, sauf échéance dépassée.
            $worst = Rules::worst($findings);
            if ($result === null && $worst !== Rules::SEVERITY_DANGER) {
                $worst = 'secondary';
            }
            $summary['total']++;
            $summary['checked'] += $result !== null ? 1 : 0;
            $summary['dmarc_enforced'] += Rules::dmarcEnforced($result) ? 1 : 0;
            $summary['to_fix'] += $worst === Rules::SEVERITY_DANGER ? 1 : 0;
            $codes = array_column($findings, 'code');
            $summary['expiring'] += array_intersect(
                $codes,
                ['domain_expired', 'domain_expiring', 'cert_expired', 'cert_expiring']
            ) !== [] ? 1 : 0;
            $domains[] = [
                'id'                 => (int) $row['id'],
                'name'               => $name,
                'dkim_selectors'     => (string) $row['dkim_selectors'],
                'domain_expiry'      => (string) ($row['domain_expiry'] ?? ''),
                'certificate_expiry' => (string) ($row['certificate_expiry'] ?? ''),
                'comment'            => (string) $row['comment'],
                'last_check_label'   => $row['last_check'] !== null
                    ? (string) Html::convDateTime((string) $row['last_check'])
                    : '',
                'mx'                 => is_array($result['mx'] ?? null) ? array_map('strval', $result['mx']) : [],
                'badges'             => self::badges($result, $row),
                'findings'           => $listed,
                'worst'              => $worst,
            ];
        }
        $rank = array_flip(Rules::SEVERITIES);
        usort(
            $gaps,
            static fn (array $a, array $b): int => [$rank[$a['severity']] ?? 9, $a['domain']]
                <=> [$rank[$b['severity']] ?? 9, $b['domain']]
        );

        return ['domains' => $domains, 'summary' => $summary, 'gaps' => $gaps];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pageData(int $edit = 0): array
    {
        $overview = self::overview();
        $editing = null;
        foreach ($overview['domains'] as $domain) {
            if ($domain['id'] === $edit) {
                $editing = $domain;
            }
        }

        return $overview + [
            'action'     => self::getSearchURL(),
            'can_manage' => self::canManage(),
            'editing'    => $editing,
            'severities' => self::severityInfos(),
            'alert_days' => Rules::ALERT_DAYS,
            'max_check'  => self::MAX_CHECK_ALL,
        ];
    }

    public static function showPage(int $edit = 0): void
    {
        TemplateRenderer::getInstance()->display('@grcmanager/mail_domain_security.html.twig', self::pageData($edit));
    }

    // ------------------------------------------------------------------ Preuve et KRI.

    /**
     * Preuve des mesures A.5.14 et A.8.21 : liste de sources affichée par le gabarit partagé de la
     * conformité de configuration (#781). Null si la mesure n'est pas concernée ou sans droit.
     *
     * @return array{sources: list<array<string, mixed>>}|null
     */
    public static function evidence(string $code): ?array
    {
        if (!in_array($code, self::CONTROLS, true) || !self::canView()) {
            return null;
        }
        $summary = self::overview()['summary'];
        $source = [
            'key'    => 'mail_domains',
            'icon'   => self::getIcon(),
            'label'  => self::getTypeName(),
            'badges' => [],
            'note'   => '',
            'url'    => self::getSearchURL(),
            'link'   => __('View the details', 'grcmanager'),
        ];
        if ($summary['total'] === 0) {
            $source['badges'][] = [
                'color' => 'secondary', 'icon' => 'ti ti-mail-off', 'text' => __('No domain entered yet', 'grcmanager'),
            ];
            $source['link'] = __('Enter the domains', 'grcmanager');

            return ['sources' => [$source]];
        }
        $source['badges'][] = [
            'color' => $summary['checked'] === 0
                ? 'secondary'
                : ($summary['dmarc_enforced'] === $summary['total'] ? 'success' : 'warning'),
            'icon'  => 'ti ti-shield-check',
            'text'  => sprintf(
                __('DMARC enforced on %1$d of %2$d domain(s)', 'grcmanager'),
                $summary['dmarc_enforced'],
                $summary['total']
            ),
        ];
        $source['badges'][] = [
            'color' => $summary['to_fix'] > 0 ? 'danger' : 'success',
            'icon'  => 'ti ti-alert-octagon',
            'text'  => sprintf(__('%d domain(s) with a gap to fix', 'grcmanager'), $summary['to_fix']),
        ];
        if ($summary['expiring'] > 0) {
            $source['badges'][] = [
                'color' => 'warning',
                'icon'  => 'ti ti-calendar-due',
                'text'  => sprintf(
                    __('%d domain(s) or certificate(s) expiring or expired', 'grcmanager'),
                    $summary['expiring']
                ),
            ];
        }
        if ($summary['checked'] < $summary['total']) {
            $source['note'] = sprintf(
                __('%d domain(s) not checked yet', 'grcmanager'),
                $summary['total'] - $summary['checked']
            );
        }

        return ['sources' => [$source]];
    }

    /**
     * KRI « domaines sans DMARC appliqué » (%) sur un périmètre d'entités, sans session : domaines
     * déjà lus dont la politique DMARC est absente, invalide ou en « none ». Null sans domaine lu.
     *
     * @param list<int> $perimeter
     */
    public static function domainsWithoutDmarcEnforced(array $perimeter): ?float
    {
        global $DB;

        if ($perimeter === []) {
            return null;
        }
        $total = 0;
        $weak = 0;
        foreach (
            $DB->request([
                'SELECT' => ['result'],
                'FROM'   => MailDomainTables::DOMAINS,
                'WHERE'  => ['entities_id' => $perimeter, 'NOT' => ['last_check' => null]],
            ]) as $row
        ) {
            $result = self::result($row);
            if ($result === null || empty($result['resolved'])) {
                continue;
            }
            $total++;
            $weak += Rules::dmarcEnforced($result) ? 0 : 1;
        }

        return $total === 0 ? null : round($weak * 100 / $total, 1);
    }
}

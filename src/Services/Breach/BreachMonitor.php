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

namespace GlpiPlugin\Grcmanager\Services\Breach;

use Closure;
use GlpiPlugin\Grcmanager\BreachExposure;

/**
 * Issue #183 : un passage de la surveillance des fuites (tâche planifiée « breachmonitor »).
 *
 * Pour chaque domaine professionnel configuré :
 * - domaine vérifié chez Have I Been Pwned : une requête pour tout le domaine (seul le domaine part) ;
 * - sinon : une requête par adresse des utilisateurs actifs de ce domaine, par lots, en respectant la
 *   limite de débit de la clé (429 : attente du délai demandé s'il reste du temps, sinon arrêt et
 *   reprise au même utilisateur au passage suivant).
 *
 * Chaque fuite trouvée devient une exposition reliée à l'utilisateur GLPI (registre sensible), créée
 * une seule fois. Les expositions des utilisateurs supprimés sont effacées.
 */
final class BreachMonitor
{
    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param int $timeBudget Secondes au plus pour attendre une limite de débit pendant un passage
     * @param (Closure(int): void)|null $sleep Attente (injectable pour les tests)
     */
    public function __construct(
        private readonly HibpClient $client,
        private readonly int $timeBudget = 240,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param list<string> $domains Domaines professionnels (normalisés)
     */
    public function run(array $domains, int $cursor = 0): BreachMonitorResult
    {
        $result = new BreachMonitorResult();
        $result->cursor = $cursor;
        $this->purgeOrphans();
        if ($domains === []) {
            return $result;
        }

        // Sans liste (offre sans recherche par domaine, qui répond 401 même avec une clé valide ; erreur
        // passagère) : adresse par adresse, qui révèle elle-même une clé invalide.
        $subscribed = $this->client->subscribedDomains();
        $verified = $subscribed->isOk() ? array_intersect($domains, (array) $subscribed->data) : [];
        $perAccount = array_values(array_diff($domains, $verified));

        $emails = self::monitoredEmails($domains);
        if ($verified !== []) {
            $this->scanVerifiedDomains(array_values($verified), $emails, $result);
        }
        if ($perAccount !== [] && $result->error === '') {
            $this->scanAccounts($perAccount, $emails, $result);
        }

        return $result;
    }

    /**
     * @param list<string> $domains
     * @param array<string, int> $emails adresse => utilisateur
     */
    private function scanVerifiedDomains(array $domains, array $emails, BreachMonitorResult $result): void
    {
        $catalog = null;
        foreach ($domains as $domain) {
            $response = $this->client->breachedDomain($domain);
            if (!$response->isOk()) {
                $result->error = $response->isUnauthorized() ? 'unauthorized' : 'http_' . $response->status;

                return;
            }
            foreach ((array) $response->data as $alias => $names) {
                $catalog ??= $this->client->breaches();
                if (!$catalog->isOk()) {
                    $result->error = 'http_' . $catalog->status;

                    return;
                }
                $email = $alias . '@' . $domain;
                foreach ($names as $name) {
                    $breach = ((array) $catalog->data)[$name] ?? ['Name' => $name];
                    $this->record($emails[$email] ?? 0, $email, $breach, $result);
                }
                $result->checked++;
            }
        }
    }

    /**
     * @param list<string> $domains
     * @param array<string, int> $emails adresse => utilisateur
     */
    private function scanAccounts(array $domains, array $emails, BreachMonitorResult $result): void
    {
        // Par utilisateur : le curseur n'avance qu'une fois toutes ses adresses vérifiées (une reprise
        // ne saute donc aucune adresse ; une adresse revérifiée ne crée pas de doublon).
        $queue = [];
        foreach ($emails as $email => $usersId) {
            if ($usersId > $result->cursor && BreachRules::isMonitored($email, $domains)) {
                $queue[$usersId][] = $email;
            }
        }
        ksort($queue);

        $waited = 0;
        foreach ($queue as $usersId => $userEmails) {
            foreach ($userEmails as $email) {
                do {
                    $response = $this->client->breachedAccount($email);
                    $delay = max(1, $response->retryAfter);
                    if ($response->isRateLimited() && $waited + $delay <= $this->timeBudget) {
                        ($this->sleep)($delay);
                        $waited += $delay;
                        continue;
                    }
                    break;
                } while (true);

                if ($response->isRateLimited()) {
                    $result->rateLimited = true;

                    return;
                }
                if (!$response->isOk()) {
                    $result->error = $response->isUnauthorized() ? 'unauthorized' : 'http_' . $response->status;

                    return;
                }
                foreach ((array) $response->data as $breach) {
                    if (is_array($breach)) {
                        $this->record($usersId, $email, $breach, $result);
                    }
                }
                $result->checked++;
            }
            $result->cursor = $usersId;
        }
        // Liste parcourue jusqu'au bout : le passage suivant repart du début.
        $result->cursor = 0;
    }

    /**
     * @param array<string, mixed> $breach
     */
    private function record(int $usersId, string $email, array $breach, BreachMonitorResult $result): void
    {
        global $DB;

        $summary = BreachRules::summarize($breach);
        if ($summary === null) {
            return;
        }
        // Minimisation : l'adresse n'est gardée que si aucun utilisateur GLPI ne la porte.
        $email = $usersId > 0 ? '' : $email;
        $exists = countElementsInTable(BreachExposure::getTable(), [
            'users_id'    => $usersId,
            'email'       => $email,
            'breach_name' => $summary['breach_name'],
        ]) > 0;
        if ($exists) {
            return;
        }
        $DB->insert(BreachExposure::getTable(), [
            'users_id'      => $usersId,
            'email'         => $email,
            'data_classes'  => json_encode($summary['data_classes']),
            'status'        => BreachExposure::STATUS_NEW,
            'date_creation' => date('Y-m-d H:i:s'),
            'date_mod'      => date('Y-m-d H:i:s'),
        ] + array_diff_key($summary, ['data_classes' => true]));
        $result->created++;
    }

    /**
     * Adresses des utilisateurs actifs sur les domaines surveillés : adresse (minuscules) => utilisateur.
     *
     * @param list<string> $domains
     * @return array<string, int>
     */
    public static function monitoredEmails(array $domains): array
    {
        global $DB;

        $emails = [];
        $rows = $DB->request([
            'SELECT'     => ['glpi_useremails.email', 'glpi_useremails.users_id'],
            'FROM'       => 'glpi_useremails',
            'INNER JOIN' => [
                'glpi_users' => ['FKEY' => ['glpi_useremails' => 'users_id', 'glpi_users' => 'id']],
            ],
            'WHERE'      => ['glpi_users.is_active' => 1, 'glpi_users.is_deleted' => 0],
            'ORDER'      => 'glpi_useremails.users_id',
        ]);
        foreach ($rows as $row) {
            $email = strtolower(trim((string) $row['email']));
            if (BreachRules::isMonitored($email, $domains) && !isset($emails[$email])) {
                $emails[$email] = (int) $row['users_id'];
            }
        }

        return $emails;
    }

    /** Expositions d'utilisateurs supprimés de GLPI : effacées. */
    private function purgeOrphans(): void
    {
        global $DB;

        $table = BreachExposure::getTable();
        $orphans = [];
        foreach (
            $DB->request([
                'SELECT'    => [$table . '.id'],
                'FROM'      => $table,
                'LEFT JOIN' => ['glpi_users' => ['FKEY' => [$table => 'users_id', 'glpi_users' => 'id']]],
                'WHERE'     => [$table . '.users_id' => ['>', 0], 'glpi_users.id' => null],
            ]) as $row
        ) {
            $orphans[] = (int) $row['id'];
        }
        if ($orphans !== []) {
            $DB->delete($table, ['id' => $orphans]);
        }
    }
}

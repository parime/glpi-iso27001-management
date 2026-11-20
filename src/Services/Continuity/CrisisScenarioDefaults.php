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

namespace GlpiPlugin\Grcmanager\Services\Continuity;

use GlpiPlugin\Grcmanager\Services\Crisis\CrisisCommunicationRules;

/**
 * Issue #776 : scénarios de crise sécurité de l'information proposés par défaut (A.5.29), ajoutés
 * à la demande dans l'entité active, dans la langue de la personne qui les ajoute, puis modifiables.
 *
 * Chaque scénario renvoie à ce que le plugin fournit déjà : la procédure de remédiation par défaut
 * de même catégorie et de même nom (PlaybookDefaults), le scénario d'exercice de crise
 * (CrisisExercise) et la situation des modèles de messages de crise (#616). Rien n'est dupliqué.
 */
final class CrisisScenarioDefaults
{
    public const KEYS = ['ransomware', 'data_breach', 'privileged_accounts', 'supplier_outage', 'denial_of_service'];

    /**
     * Procédure de remédiation fournie qui sert de guide de réponse : [catégorie, nom anglais].
     */
    public const PLAYBOOKS = [
        'ransomware'          => ['malware', 'Ransomware'],
        'data_breach'         => ['data_breach', 'Data leak'],
        'privileged_accounts' => ['unauthorized_access', 'Compromised account'],
    ];

    /** Scénario des exercices de crise (CrisisExercise::getScenarios()) qui exerce ce scénario. */
    public const EXERCISES = [
        'ransomware'      => 'ransomware',
        'data_breach'     => 'data_breach',
        'supplier_outage' => 'outage',
    ];

    /** Situation des modèles de messages de crise (#616). */
    public const SITUATIONS = [
        'ransomware'          => CrisisCommunicationRules::SITUATION_RANSOMWARE,
        'data_breach'         => CrisisCommunicationRules::SITUATION_DATA_BREACH,
        'privileged_accounts' => CrisisCommunicationRules::SITUATION_MAJOR_INCIDENT,
        'supplier_outage'     => CrisisCommunicationRules::SITUATION_UNAVAILABILITY,
        'denial_of_service'   => CrisisCommunicationRules::SITUATION_UNAVAILABILITY,
    ];

    public static function exists(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /**
     * Textes traduits de chaque scénario.
     *
     * @return array<string, array{name: string, description: string, degraded_mode: string,
     *     role_management: string, role_ciso: string, role_it: string}>
     */
    public static function texts(): array
    {
        return [
            'ransomware' => [
                'name'            => __('Ransomware attack', 'grcmanager'),
                'description'     => __(
                    'Data and systems are encrypted by malicious software and a ransom is demanded. Backups and '
                        . 'systems connected to the affected network may also be reached.',
                    'grcmanager'
                ),
                'degraded_mode'   => __(
                    'Isolate the affected network segments and suspend remote access (VPN, remote desktop). Work '
                        . 'only from clean, identified workstations; every administrator action is validated by a '
                        . 'second person. Restore from offline backups checked as clean before reconnecting them. '
                        . 'Communicate only through channels kept outside the affected information system. No '
                        . 'ransom is paid without a decision of management.',
                    'grcmanager'
                ),
                'role_management' => __(
                    'Chairs the crisis team, decides on the ransom, the complaint and external communication, '
                        . 'approves the return to normal.',
                    'grcmanager'
                ),
                'role_ciso'       => __(
                    'Qualifies the attack, coordinates containment and the investigation, decides which backups '
                        . 'can be trusted, prepares the notifications (authority, CERT, insurer).',
                    'grcmanager'
                ),
                'role_it'         => __(
                    'Isolates the systems, preserves the evidence, restores from clean backups in the order of '
                        . 'the recovery objectives, reinforces monitoring after the restart.',
                    'grcmanager'
                ),
            ],
            'data_breach' => [
                'name'            => __('Data leak', 'grcmanager'),
                'description'     => __(
                    'Personal or confidential data (customers, staff, partners) is disclosed, stolen or '
                        . 'published.',
                    'grcmanager'
                ),
                'degraded_mode'   => __(
                    'Close the leak path (account, share, application) and reset the credentials involved. '
                        . 'Restrict access to the affected data to the people handling the incident. Any external '
                        . 'statement is validated by management and the data protection officer. The facts are '
                        . 'recorded as they happen, for the 72-hour notification deadline.',
                    'grcmanager'
                ),
                'role_management' => __(
                    'Decides on notifying the authority and the people concerned, validates external '
                        . 'communication.',
                    'grcmanager'
                ),
                'role_ciso'       => __(
                    'Contains the leak, establishes the data and the people concerned, keeps the evidence, works '
                        . 'with the data protection officer.',
                    'grcmanager'
                ),
                'role_it'         => __(
                    'Closes the access, analyzes the logs, has the published copies removed where possible.',
                    'grcmanager'
                ),
            ],
            'privileged_accounts' => [
                'name'            => __('Compromise of privileged accounts', 'grcmanager'),
                'description'     => __(
                    'An administrator account, a service account or the directory itself is controlled by an '
                        . 'attacker.',
                    'grcmanager'
                ),
                'degraded_mode'   => __(
                    'Disable or reset the compromised accounts and those that could be reached from them; use the '
                        . 'sealed emergency administrator accounts. Administration is done only from dedicated '
                        . 'workstations, and every privileged action is validated by a second person until the '
                        . 'directory is declared clean. New connections and changes of rights are monitored.',
                    'grcmanager'
                ),
                'role_management' => __(
                    'Approves the temporary access restrictions and their impact on the activity.',
                    'grcmanager'
                ),
                'role_ciso'       => __(
                    'Coordinates the investigation (how the account was taken, what it did), decides when the '
                        . 'directory can be trusted again.',
                    'grcmanager'
                ),
                'role_it'         => __(
                    'Renews the secrets (passwords, keys, tickets), removes the persistence, checks the rights '
                        . 'granted during the compromise.',
                    'grcmanager'
                ),
            ],
            'supplier_outage' => [
                'name'            => __('Unavailability of a critical supplier', 'grcmanager'),
                'description'     => __(
                    'A hosting provider, cloud service, software publisher or outsourcer on which a critical '
                        . 'process depends is unavailable, or is itself compromised.',
                    'grcmanager'
                ),
                'degraded_mode'   => __(
                    'Switch the processes concerned to their backup solution or manual procedure. Cut or restrict '
                        . 'the connections and accounts of the supplier until it confirms it is not compromised. '
                        . 'Data exchanged through workarounds stays limited to what is necessary and is recorded. '
                        . 'Contact the supplier only through the contacts and channels provided in the contract.',
                    'grcmanager'
                ),
                'role_management' => __(
                    'Activates the continuity plan, handles the relationship with the supplier and with the '
                        . 'customers affected.',
                    'grcmanager'
                ),
                'role_ciso'       => __(
                    'Assesses whether the incident of the supplier is a security incident for the organization, '
                        . 'supervises the workarounds.',
                    'grcmanager'
                ),
                'role_it'         => __(
                    'Implements the backup solution, monitors the flows and accounts of the supplier, prepares '
                        . 'the return to the normal service.',
                    'grcmanager'
                ),
            ],
            'denial_of_service' => [
                'name'            => __('Denial of service attack', 'grcmanager'),
                'description'     => __(
                    'Internet-facing services (website, customer portal, messaging, remote access) are saturated '
                        . 'by a flood of traffic.',
                    'grcmanager'
                ),
                'degraded_mode'   => __(
                    'Activate the anti-DDoS protection of the operator or host and filter traffic by origin. Keep '
                        . 'the essential services open to identified users only (VPN, list of allowed addresses). '
                        . 'Look for another attack hidden behind the denial of service. Inform customers through '
                        . 'a channel independent of the attacked services.',
                    'grcmanager'
                ),
                'role_management' => __(
                    'Decides which services to keep or cut, and what to tell the customers.',
                    'grcmanager'
                ),
                'role_ciso'       => __(
                    'Coordinates with the operator and the CERT, looks for a concurrent intrusion, keeps the '
                        . 'evidence for a complaint.',
                    'grcmanager'
                ),
                'role_it'         => __(
                    'Applies the filtering and the alternative routing, monitors the load, restores the services '
                        . 'in order of priority.',
                    'grcmanager'
                ),
            ],
        ];
    }
}

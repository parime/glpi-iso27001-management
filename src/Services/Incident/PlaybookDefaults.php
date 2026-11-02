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

namespace GlpiPlugin\Grcmanager\Services\Incident;

/**
 * Issue #370 : les cinq procédures de remédiation fournies à l'installation, modifiables ensuite.
 * Textes en anglais (identifiants gettext), traduits dans la langue de l'installation par
 * l'installeur. Chaque étape : [phase, intitulé, description, obligatoire].
 */
final class PlaybookDefaults
{
    /**
     * @return list<array{
     *     key: string, category: string, name: string, description: string,
     *     steps: list<array{0: string, 1: string, 2: string, 3: bool}>
     * }>
     */
    public static function definitions(): array
    {
        return [
            [
                'key'         => 'ransomware',
                'category'    => 'malware',
                'name'        => 'Ransomware',
                'description' =>
                    'Encrypted files, ransom note: contain first, keep the evidence, never pay without legal advice.',
                'steps'       => [
                    [
                        'containment',
                        'Disconnect the affected machines from the network (do not switch them off)',
                        'Unplug the cable or isolate from the EDR console; switching off destroys the memory evidence.',
                        true,
                    ],
                    [
                        'containment',
                        'Isolate the backups',
                        'Disconnect or lock the backup repositories so they are not encrypted in turn.',
                        true,
                    ],
                    [
                        'containment',
                        'Disable the compromised accounts and the remote accesses',
                        'VPN, remote desktop, service accounts used by the attacker.',
                        true,
                    ],
                    [
                        'analysis',
                        'Identify the ransomware and the entry point',
                        'Ransom note, file extension, first infected machine, MITRE ATT&CK techniques.',
                        true,
                    ],
                    [
                        'analysis',
                        'Preserve the evidence',
                        'Memory and disk images, logs, ransom note; record each handling in the action log.',
                        true,
                    ],
                    ['notification', 'Inform management and the crisis unit', '', true],
                    [
                        'notification',
                        'File a complaint and notify the authorities',
                        'Police or gendarmerie, the CNIL within 72 h if personal data is affected, NIS2 if applicable.',
                        true,
                    ],
                    ['notification', 'Inform the cyber insurer', '', false],
                    [
                        'eradication',
                        'Remove the persistence and reset every password',
                        'Scheduled tasks, services, GPO, attacker accounts; reset the krbtgt account twice.',
                        true,
                    ],
                    [
                        'recovery',
                        'Restore from healthy backups',
                        'Check the backup date against the date of the first compromise.',
                        true,
                    ],
                    ['recovery', 'Reconnect gradually under monitoring', '', true],
                    [
                        'post_incident',
                        'Write the post-incident report',
                        'Root cause, timeline, costs, lessons learned.',
                        true,
                    ],
                    ['post_incident', 'Update the risk analysis and the action plan', '', false],
                ],
            ],
            [
                'key'         => 'phishing',
                'category'    => 'unauthorized_access',
                'name'        => 'Phishing',
                'description' =>
                    'A malicious email was received or clicked: find all recipients, block, check what was entered.',
                'steps'       => [
                    ['containment', 'Block the sender and the links', 'Mail gateway, web filtering, DNS.', true],
                    [
                        'containment',
                        'Delete the email from every mailbox',
                        'Message search on the whole messaging system.',
                        true,
                    ],
                    ['analysis', 'List the recipients who clicked or replied', '', true],
                    [
                        'analysis',
                        'Analyse the attachment and the links in a sandbox',
                        'Record the indicators of compromise (IOC tab).',
                        false,
                    ],
                    [
                        'eradication',
                        'Reset the passwords entered on the fake page',
                        'And revoke the open sessions and tokens.',
                        true,
                    ],
                    ['eradication', 'Scan the machines of those who opened the attachment', '', false],
                    ['notification', 'Warn the users', 'Short message: what to do if they received it.', false],
                    ['post_incident', 'Plan an awareness reminder', '', false],
                ],
            ],
            [
                'key'         => 'account_compromise',
                'category'    => 'unauthorized_access',
                'name'        => 'Compromised account',
                'description' =>
                    'An account is used by someone else: cut the access, measure what was done, close the gap.',
                'steps'       => [
                    [
                        'containment',
                        'Disable the account or reset its password',
                        'And revoke the sessions, tokens and application passwords.',
                        true,
                    ],
                    ['containment', 'Enforce multi-factor authentication', '', true],
                    [
                        'analysis',
                        'Review the sign-in logs and the actions performed',
                        'Unusual countries or times, forwarding rules, data accessed.',
                        true,
                    ],
                    ['analysis', 'Look for the other accounts affected', 'Same password, same source IP.', false],
                    [
                        'eradication',
                        'Remove the rules and accesses added by the attacker',
                        'Mail forwarding, delegations, registered devices.',
                        true,
                    ],
                    [
                        'notification',
                        'Assess whether personal data was exposed',
                        'If so, GDPR notification within 72 h.',
                        true,
                    ],
                    [
                        'recovery',
                        'Give the account back to its owner',
                        'With a new password and multi-factor authentication.',
                        true,
                    ],
                    ['post_incident', 'Check the password policy and the multi-factor coverage', '', false],
                ],
            ],
            [
                'key'         => 'malware',
                'category'    => 'malware',
                'name'        => 'Malware',
                'description' =>
                    'Malicious software detected on a machine: isolate, analyse its spread, clean or reinstall.',
                'steps'       => [
                    ['containment', 'Isolate the infected machine', 'From the EDR console or by unplugging it.', true],
                    [
                        'analysis',
                        'Identify the malware and its behaviour',
                        'Hash, name, network connections; record the indicators (IOC tab).',
                        true,
                    ],
                    ['analysis', 'Search the other machines for the same indicators', '', true],
                    [
                        'eradication',
                        'Clean or reinstall the machine',
                        'Reinstall when in doubt about persistence.',
                        true,
                    ],
                    ['eradication', 'Reset the passwords used on the machine', '', false],
                    ['recovery', 'Put the machine back in service and monitor it', '', true],
                    [
                        'post_incident',
                        'Close the entry point',
                        'Vulnerability to patch, removable media, download.',
                        false,
                    ],
                ],
            ],
            [
                'key'         => 'data_leak',
                'category'    => 'data_breach',
                'name'        => 'Data leak',
                'description' =>
                    'Data has left the organisation: stop the leak, assess the exposure, notify in time.',
                'steps'       => [
                    [
                        'containment',
                        'Stop the leak',
                        'Remove the public share, revoke the access, ask the host to take the data down.',
                        true,
                    ],
                    [
                        'analysis',
                        'Identify the data exposed and the people concerned',
                        'Categories of data, number of people, period of exposure.',
                        true,
                    ],
                    [
                        'analysis',
                        'Assess the risk for the people concerned',
                        'Seriousness, likelihood of misuse.',
                        true,
                    ],
                    [
                        'notification',
                        'Notify the CNIL within 72 h',
                        'Unless the breach is unlikely to result in a risk; document the decision.',
                        true,
                    ],
                    ['notification', 'Inform the people concerned if the risk is high', '', false],
                    ['recovery', 'Monitor the reuse of the data', 'Leak sites, fraud attempts.', false],
                    [
                        'post_incident',
                        'Record the breach in the breach register',
                        'Required by the GDPR (article 33.5) even without notification.',
                        true,
                    ],
                ],
            ],
        ];
    }
}

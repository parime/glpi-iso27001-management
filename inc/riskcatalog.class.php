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

use GlpiPlugin\Grcmanager\Services\Risk\InfrastructureRiskCatalog;

/**
 * Issue #178 : catalogue de risques types d'infrastructure. La personne coche ceux qui la
 * concernent (questions d'aide pour chacun) ; ils sont créés dans le registre au statut
 * « Identifié », avec leurs mesures de l'Annexe A, à évaluer ensuite comme tout risque. Un risque
 * déjà importé n'est pas recréé.
 *
 * Ancre sans table propre (CommonGLPI) ; le suivi des imports est dans
 * glpi_plugin_grcmanager_riskcatalogimports.
 */
class PluginGrcmanagerRiskCatalog extends CommonGLPI
{
    public const IMPORTS_TABLE = 'glpi_plugin_grcmanager_riskcatalogimports';

    private const CONTROLS_RISKS_TABLE = 'glpi_plugin_grcmanager_controls_risks';

    public static function getTypeName($nb = 0)
    {
        return __('Catalog of typical risks', 'grcmanager');
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/riskcatalog.php';
    }

    public static function canView(): bool
    {
        return Session::haveRight(PluginGrcmanagerRisk::$rightname, READ);
    }

    /**
     * @return array<string, array{title: string, description: string, threats: string, questions: list<string>}>
     */
    public static function getEntries(): array
    {
        $entries = [
            'admin_exposed' => [
                __('Administration service exposed to the Internet', 'grcmanager'),
                __(
                    'An administration interface (SSH, remote desktop, firewall console, proxy or hypervisor '
                        . 'dashboard) can be reached from the Internet.',
                    'grcmanager'
                ),
                __(
                    'Brute-force or stolen-password attack, exploitation of a published flaw before the update, '
                        . 'takeover of the device.',
                    'grcmanager'
                ),
                [
                    __('Does a firewall rule publish an administration port to the Internet?', 'grcmanager'),
                    __(
                        'Does administrative access go through a VPN or a bastion with strong authentication?',
                        'grcmanager'
                    ),
                ],
            ],
            'remote_mgmt_exposed' => [
                __('Server remote management cards reachable outside the administration network', 'grcmanager'),
                __(
                    'iLO, iDRAC, IPMI or similar interfaces are on the same network as workstations or servers, or '
                        . 'even published.',
                    'grcmanager'
                ),
                __(
                    'Hardware-level takeover of the server (console, power, image mounting) that bypasses the '
                        . 'operating system and its protections.',
                    'grcmanager'
                ),
                [
                    __('Are the management cards on a dedicated, filtered network?', 'grcmanager'),
                    __('Have their default passwords been changed and their firmware updated?', 'grcmanager'),
                ],
            ],
            'vpn_overlap' => [
                __('Networks overlapping with a partner connected by VPN', 'grcmanager'),
                __(
                    'A partner, customer or provider connected by VPN uses the same address range as one of your '
                        . 'sites.',
                    'grcmanager'
                ),
                __(
                    'Misrouted or blocked traffic, improvised workarounds (address translation added in a hurry, '
                        . 'overly broad rules) that open more than needed.',
                    'grcmanager'
                ),
                [
                    __('Do VPN tunnels connect your networks to third parties\' networks?', 'grcmanager'),
                    __('Are the ranges on each side documented and distinct?', 'grcmanager'),
                ],
            ],
            'guest_network' => [
                __('Insufficiently isolated guest network', 'grcmanager'),
                __('The guest Wi-Fi or visitor network can reach internal devices.', 'grcmanager'),
                __(
                    'An unknown or compromised device reaches workstations, printers or servers; internal traffic '
                        . 'is eavesdropped.',
                    'grcmanager'
                ),
                [
                    __('Does the guest network only have access to the Internet?', 'grcmanager'),
                    __('Has the isolation been checked by a test from a guest device?', 'grcmanager'),
                ],
            ],
            'flat_network' => [
                __('Flat internal network, without segmentation', 'grcmanager'),
                __(
                    'Workstations, servers, printers, telephony and industrial equipment share the same network.',
                    'grcmanager'
                ),
                __(
                    'A compromised workstation (ransomware, Trojan) spreads freely to servers and backups.',
                    'grcmanager'
                ),
                [
                    __('Does each use have its own network or VLAN, with filtering between them?', 'grcmanager'),
                    __('Are the flows allowed between networks listed in the network flow matrix?', 'grcmanager'),
                ],
            ],
            'spof_power' => [
                __('Single point of failure: power supply', 'grcmanager'),
                __(
                    'The server room or network closet depends on a single UPS, a single power feed or a single '
                        . 'power supply per device.',
                    'grcmanager'
                ),
                __(
                    'Total outage during a UPS failure or electrical work; equipment damaged by a sudden shutdown.',
                    'grcmanager'
                ),
                [
                    __('Do critical devices have two power supplies, on two circuits?', 'grcmanager'),
                    __('Is the UPS runtime tested and sufficient for a clean shutdown?', 'grcmanager'),
                ],
            ],
            'spof_hypervisor' => [
                __('Single point of failure: hypervisor or shared storage', 'grcmanager'),
                __(
                    'Several critical virtual servers rely on a single virtualization host or a single storage '
                        . 'array.',
                    'grcmanager'
                ),
                __(
                    'Simultaneous outage of all hosted applications after a hardware failure or a failed update.',
                    'grcmanager'
                ),
                [
                    __('Can a second host take over the critical virtual machines?', 'grcmanager'),
                    __('Has the recovery time been measured during a test?', 'grcmanager'),
                ],
            ],
            'spof_isp_link' => [
                __('Single point of failure: carrier link', 'grcmanager'),
                __(
                    'A site, or access to online services, depends on a single Internet link or a single carrier.',
                    'grcmanager'
                ),
                __(
                    'Site cut off during an outage (works, carrier failure), telephony and hosted applications '
                        . 'unavailable.',
                    'grcmanager'
                ),
                [
                    __('Is there a second link, with another carrier and over another physical path?', 'grcmanager'),
                    __('Has the failover been tested?', 'grcmanager'),
                ],
            ],
            'spof_directory' => [
                __('Single point of failure: directory (domain controller)', 'grcmanager'),
                __('A site has only one domain controller, or the directory has only one server.', 'grcmanager'),
                __(
                    'No more logons or access to shares after a failure; long and tricky directory restore.',
                    'grcmanager'
                ),
                [
                    __(
                        'Does each site have at least two domain controllers, or reliable access to a second one?',
                        'grcmanager'
                    ),
                    __('Has the directory restore ever been tested?', 'grcmanager'),
                ],
            ],
            'backups_same_site' => [
                __('Backups on the same site as the data', 'grcmanager'),
                __(
                    'Backups are stored in the same room, building or administration domain as the data they '
                        . 'protect.',
                    'grcmanager'
                ),
                __(
                    'Simultaneous loss of data and backups in a fire, a flood or a ransomware attack that also '
                        . 'encrypts the backups.',
                    'grcmanager'
                ),
                [
                    __('Is a copy kept off-site or offline, immutable?', 'grcmanager'),
                    __('Is a full restore tested regularly?', 'grcmanager'),
                ],
            ],
            'outdated_diagrams' => [
                __('Network diagram and address plan not kept up to date', 'grcmanager'),
                __(
                    'The network diagram, the address plan and the list of flows no longer reflect reality.',
                    'grcmanager'
                ),
                __(
                    'Decisions made on a wrong picture, longer incident diagnosis, forgotten devices outside any '
                        . 'monitoring.',
                    'grcmanager'
                ),
                [
                    __('Is the date of the last diagram update known?', 'grcmanager'),
                    __('Is a periodic review of the diagram and flows scheduled?', 'grcmanager'),
                ],
            ],
            'logs_not_reviewed' => [
                __('Security logs neither centralized nor reviewed', 'grcmanager'),
                __('Firewall, directory and server logs stay on each device, with no alert or review.', 'grcmanager'),
                __(
                    'Intrusion detected late, or never; logs wiped by the attacker; impossible to reconstruct an '
                        . 'incident.',
                    'grcmanager'
                ),
                [
                    __('Are logs centralized and kept long enough?', 'grcmanager'),
                    __('Are there alerts or a regular review?', 'grcmanager'),
                ],
            ],
            // Systèmes d'IA, rattachés à ISO/IEC 27090:2026 (InfrastructureRiskCatalog::iso27090()).
            'ai_public_services' => [
                __('Company data entered into public AI services', 'grcmanager'),
                __(
                    'Staff paste documents, customer data or source code into public chatbots or AI tools '
                        . 'that the organization has not approved.',
                    'grcmanager'
                ),
                __(
                    'Leak of confidential or personal data, reuse of the data to train the service, breach of '
                        . 'GDPR or of contracts.',
                    'grcmanager'
                ),
                [
                    __('Is there a list of approved AI services and a rule on what data may be entered?', 'grcmanager'),
                    __('Have staff been made aware of it?', 'grcmanager'),
                ],
            ],
            'ai_prompt_injection' => [
                __('AI assistant or agent diverted by prompt injection', 'grcmanager'),
                __(
                    'An AI assistant reads e-mails, documents or web pages, or acts on tools (mailbox, '
                        . 'tickets, files) on behalf of users.',
                    'grcmanager'
                ),
                __(
                    'Instructions hidden in the content it reads make it disclose data, perform unwanted actions '
                        . 'or return a booby-trapped answer.',
                    'grcmanager'
                ),
                [
                    __(
                        'Does the assistant have more rights than needed, or act without human validation?',
                        'grcmanager'
                    ),
                    __('Is the content it processes filtered and are its actions logged?', 'grcmanager'),
                ],
            ],
            'ai_sensitive_output' => [
                __('AI system revealing confidential or personal data', 'grcmanager'),
                __(
                    'An internal assistant or model has access to documents or data broader than what each '
                        . 'user is allowed to see.',
                    'grcmanager'
                ),
                __(
                    'Disclosure through its answers of documents, personal data or training data to people '
                        . 'who should not see them.',
                    'grcmanager'
                ),
                [
                    __('Does the assistant respect the access rights of the person asking?', 'grcmanager'),
                    __('Were personal data excluded or minimized in what it can access?', 'grcmanager'),
                ],
            ],
            'ai_supply_chain' => [
                __('Compromised third-party AI model, dataset or service', 'grcmanager'),
                __(
                    'The organization uses models, datasets, libraries or AI services supplied by third '
                        . 'parties, sometimes downloaded from public repositories.',
                    'grcmanager'
                ),
                __(
                    'Trapped model or library, unknown origin of data, provider changing its terms or its '
                        . 'model without notice, dependency on a single provider.',
                    'grcmanager'
                ),
                [
                    __('Are the origin and version of each model and dataset known?', 'grcmanager'),
                    __('Do contracts with AI providers cover data use, security and location?', 'grcmanager'),
                ],
            ],
            'ai_data_poisoning' => [
                __('Training data or model tampered with', 'grcmanager'),
                __(
                    'The organization trains or fine-tunes a model, or feeds an assistant with a document base '
                        . 'that several people can modify.',
                    'grcmanager'
                ),
                __(
                    'Altered data or parameters skew the answers or plant a hidden behaviour that is hard to '
                        . 'detect.',
                    'grcmanager'
                ),
                [
                    __('Who can modify the training data, the document base or the model?', 'grcmanager'),
                    __('Are changes traced and the model tested before going into production?', 'grcmanager'),
                ],
            ],
            'ai_model_theft' => [
                __('Theft or extraction of an in-house model', 'grcmanager'),
                __(
                    'A model developed or fine-tuned by the organization is exposed through an API or stored '
                        . 'with the rest of the data.',
                    'grcmanager'
                ),
                __(
                    'Copy of the model files, or reconstruction of the model through massive queries, and loss '
                        . 'of the know-how it contains.',
                    'grcmanager'
                ),
                [
                    __('Are access to the model files restricted and its use limited and monitored?', 'grcmanager'),
                    __('Is the number of queries per user limited?', 'grcmanager'),
                ],
            ],
            'ai_no_inventory' => [
                __('AI systems neither inventoried nor monitored', 'grcmanager'),
                __(
                    'AI features are enabled in software, services or internal projects without anyone '
                        . 'having a complete list of them.',
                    'grcmanager'
                ),
                __(
                    'Data processing and risks that nobody assesses, misuse or abuse noticed late, or never.',
                    'grcmanager'
                ),
                [
                    __('Is there an inventory of AI systems with their owner and the data they use?', 'grcmanager'),
                    __('Is their use logged and reviewed?', 'grcmanager'),
                ],
            ],
        ];

        $result = [];
        foreach (InfrastructureRiskCatalog::keys() as $key) {
            [$title, $description, $threats, $questions] = $entries[$key];
            $result[$key] = [
                'title'       => $title,
                'description' => $description,
                'threats'     => $threats,
                'questions'   => $questions,
            ];
        }

        return $result;
    }

    /**
     * Clés déjà importées dont le risque existe encore => identifiant du risque.
     *
     * @return array<string, int>
     */
    public static function importedRisks(): array
    {
        global $DB;

        $risks = PluginGrcmanagerRisk::getTable();
        $imported = [];
        foreach (
            $DB->request([
                'SELECT'     => [
                    self::IMPORTS_TABLE . '.catalog_key',
                    self::IMPORTS_TABLE . '.plugin_grcmanager_risks_id',
                ],
                'FROM'       => self::IMPORTS_TABLE,
                'INNER JOIN' => [
                    $risks => ['FKEY' => [self::IMPORTS_TABLE => 'plugin_grcmanager_risks_id', $risks => 'id']],
                ],
            ]) as $row
        ) {
            $imported[(string) $row['catalog_key']] = (int) $row['plugin_grcmanager_risks_id'];
        }

        return $imported;
    }

    /**
     * Crée dans le registre les risques types demandés qui n'y sont pas encore.
     *
     * @param array<int|string, mixed> $requested
     * @return list<int> Identifiants des risques créés.
     */
    public static function import(array $requested): array
    {
        global $DB;

        $entries = self::getEntries();
        $controlIds = [];
        foreach ($DB->request(['SELECT' => ['id', 'code'], 'FROM' => PluginGrcmanagerControl::getTable()]) as $row) {
            $controlIds[(string) $row['code']] = (int) $row['id'];
        }

        $created = [];
        $imported = self::importedRisks();
        foreach (InfrastructureRiskCatalog::toImport($requested, array_keys($imported)) as $key) {
            $entry = $entries[$key];
            $description = $entry['description'] . "\n\n" . __('Threats:', 'grcmanager') . ' ' . $entry['threats']
                . "\n\n" . __('Questions to tell whether it applies:', 'grcmanager') . "\n- "
                . implode("\n- ", $entry['questions']);
            $sections = InfrastructureRiskCatalog::iso27090($key);
            if ($sections !== []) {
                $description .= "\n\n"
                    . sprintf(__('ISO/IEC 27090 sections: %s', 'grcmanager'), implode(', ', $sections));
            }
            $risksId = (int) (new PluginGrcmanagerRisk())->add([
                'title'       => $entry['title'],
                'description' => $description,
                'category'    => InfrastructureRiskCatalog::category($key),
                // Valeurs par défaut du registre, envoyées comme le fait le formulaire : sans elles,
                // le score n'est pas calculé. L'évaluation réelle reste à faire.
                'probability' => 'possible',
                'impact'      => 'medium',
                'status'      => 'identified',
                'users_id'    => (int) Session::getLoginUserID(),
            ]);
            if ($risksId <= 0) {
                continue;
            }
            foreach (InfrastructureRiskCatalog::controls($key) as $code) {
                if (isset($controlIds[$code])) {
                    $DB->insert(self::CONTROLS_RISKS_TABLE, [
                        'plugin_grcmanager_controls_id' => $controlIds[$code],
                        'plugin_grcmanager_risks_id'    => $risksId,
                        'date_creation'                 => date('Y-m-d H:i:s'),
                    ]);
                }
            }
            $DB->delete(self::IMPORTS_TABLE, ['catalog_key' => $key]);
            $DB->insert(self::IMPORTS_TABLE, [
                'catalog_key'                => $key,
                'plugin_grcmanager_risks_id' => $risksId,
                'users_id'                   => (int) Session::getLoginUserID(),
                'date_creation'              => date('Y-m-d H:i:s'),
            ]);
            $created[] = $risksId;
        }

        return $created;
    }
}

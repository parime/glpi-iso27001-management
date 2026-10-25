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
        return __('Catalogue de risques types', 'grcmanager');
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
                __('Service d\'administration exposé sur Internet', 'grcmanager'),
                __(
                    'Une interface d\'administration (SSH, bureau à distance, console d\'un pare-feu, tableau de '
                    . 'bord d\'un proxy ou d\'un hyperviseur) est joignable depuis Internet.',
                    'grcmanager'
                ),
                __(
                    'Attaque par force brute ou par mots de passe volés, exploitation d\'une faille publiée avant '
                    . 'la mise à jour, prise de contrôle de l\'équipement.',
                    'grcmanager'
                ),
                [
                    __('Une règle du pare-feu publie-t-elle un port d\'administration vers Internet ?', 'grcmanager'),
                    __(
                        'L\'accès d\'administration passe-t-il par un VPN ou un bastion avec authentification '
                        . 'forte ?',
                        'grcmanager'
                    ),
                ],
            ],
            'remote_mgmt_exposed' => [
                __(
                    'Cartes de gestion à distance des serveurs joignables hors du réseau d\'administration',
                    'grcmanager'
                ),
                __(
                    'Les interfaces iLO, iDRAC, IPMI ou équivalentes sont sur le même réseau que les postes ou les '
                    . 'serveurs, voire publiées.',
                    'grcmanager'
                ),
                __(
                    'Prise de contrôle matérielle du serveur (console, alimentation, montage d\'images) qui '
                    . 'contourne le système d\'exploitation et ses protections.',
                    'grcmanager'
                ),
                [
                    __('Les cartes de gestion sont-elles sur un réseau dédié, filtré ?', 'grcmanager'),
                    __(
                        'Leurs mots de passe par défaut ont-ils été changés et leur micrologiciel mis à jour ?',
                        'grcmanager'
                    ),
                ],
            ],
            'vpn_overlap' => [
                __('Réseaux qui se chevauchent avec un partenaire relié par VPN', 'grcmanager'),
                __(
                    'Un partenaire, un client ou un prestataire relié par VPN utilise la même plage d\'adresses '
                    . 'qu\'un de vos sites.',
                    'grcmanager'
                ),
                __(
                    'Flux mal routés ou bloqués, contournements improvisés (traduction d\'adresses ajoutée dans '
                    . 'l\'urgence, règles trop larges) qui ouvrent plus que nécessaire.',
                    'grcmanager'
                ),
                [
                    __('Des tunnels VPN relient-ils vos réseaux à ceux de tiers ?', 'grcmanager'),
                    __('Les plages de chaque côté sont-elles documentées et distinctes ?', 'grcmanager'),
                ],
            ],
            'guest_network' => [
                __('Réseau invités insuffisamment isolé', 'grcmanager'),
                __('Le Wi-Fi invités ou le réseau des visiteurs peut joindre des équipements internes.', 'grcmanager'),
                __(
                    'Un appareil inconnu ou compromis atteint les postes, les imprimantes ou les serveurs ; écoute '
                    . 'du trafic interne.',
                    'grcmanager'
                ),
                [
                    __('Le réseau invités n\'a-t-il accès qu\'à Internet ?', 'grcmanager'),
                    __('L\'isolation a-t-elle été vérifiée par un test depuis un appareil invité ?', 'grcmanager'),
                ],
            ],
            'flat_network' => [
                __('Réseau interne à plat, sans segmentation', 'grcmanager'),
                __(
                    'Postes, serveurs, imprimantes, téléphonie et équipements industriels partagent le même réseau.',
                    'grcmanager'
                ),
                __(
                    'Un poste compromis (rançongiciel, cheval de Troie) se propage librement vers les serveurs et '
                    . 'les sauvegardes.',
                    'grcmanager'
                ),
                [
                    __('Les usages ont-ils chacun leur réseau ou VLAN, avec un filtrage entre eux ?', 'grcmanager'),
                    __('Les flux autorisés entre réseaux sont-ils listés dans la matrice des flux ?', 'grcmanager'),
                ],
            ],
            'spof_power' => [
                __('Point de défaillance unique : alimentation électrique', 'grcmanager'),
                __(
                    'La salle serveurs ou le local réseau dépend d\'un seul onduleur, d\'une seule arrivée '
                    . 'électrique ou d\'une seule alimentation par équipement.',
                    'grcmanager'
                ),
                __(
                    'Coupure générale lors d\'une panne d\'onduleur ou d\'une intervention électrique ; matériel '
                    . 'endommagé par un arrêt brutal.',
                    'grcmanager'
                ),
                [
                    __('Les équipements critiques ont-ils deux alimentations, sur deux circuits ?', 'grcmanager'),
                    __('L\'autonomie des onduleurs est-elle testée et suffisante pour un arrêt propre ?', 'grcmanager'),
                ],
            ],
            'spof_hypervisor' => [
                __('Point de défaillance unique : hyperviseur ou stockage partagé', 'grcmanager'),
                __(
                    'Plusieurs serveurs virtuels critiques reposent sur un seul hôte de virtualisation ou une '
                    . 'seule baie de stockage.',
                    'grcmanager'
                ),
                __(
                    'Arrêt simultané de toutes les applications hébergées en cas de panne matérielle ou de mise à '
                    . 'jour ratée.',
                    'grcmanager'
                ),
                [
                    __('Un second hôte peut-il reprendre les machines virtuelles critiques ?', 'grcmanager'),
                    __('Le délai de reprise a-t-il été mesuré lors d\'un test ?', 'grcmanager'),
                ],
            ],
            'spof_isp_link' => [
                __('Point de défaillance unique : lien opérateur', 'grcmanager'),
                __(
                    'Un site, ou l\'accès aux services en ligne, dépend d\'un seul lien Internet ou d\'un seul '
                    . 'opérateur.',
                    'grcmanager'
                ),
                __(
                    'Site isolé pendant une coupure (travaux, panne opérateur), téléphonie et applications '
                    . 'hébergées inaccessibles.',
                    'grcmanager'
                ),
                [
                    __(
                        'Existe-t-il un second lien, chez un autre opérateur et par un autre chemin physique ?',
                        'grcmanager'
                    ),
                    __('La bascule a-t-elle été testée ?', 'grcmanager'),
                ],
            ],
            'spof_directory' => [
                __('Point de défaillance unique : annuaire (contrôleur de domaine)', 'grcmanager'),
                __(
                    'Un site n\'a qu\'un seul contrôleur de domaine, ou l\'annuaire n\'a qu\'un seul serveur.',
                    'grcmanager'
                ),
                __(
                    'Plus aucune connexion ni accès aux partages en cas de panne ; restauration longue et délicate '
                    . 'de l\'annuaire.',
                    'grcmanager'
                ),
                [
                    __(
                        'Chaque site a-t-il au moins deux contrôleurs de domaine, ou un accès fiable à un second ?',
                        'grcmanager'
                    ),
                    __('La restauration de l\'annuaire a-t-elle déjà été testée ?', 'grcmanager'),
                ],
            ],
            'backups_same_site' => [
                __('Sauvegardes sur le même site que les données', 'grcmanager'),
                __(
                    'Les sauvegardes sont stockées dans la même salle, le même bâtiment ou le même domaine '
                    . 'd\'administration que les données qu\'elles protègent.',
                    'grcmanager'
                ),
                __(
                    'Perte simultanée des données et des sauvegardes lors d\'un incendie, d\'un dégât des eaux ou '
                    . 'd\'un rançongiciel qui chiffre aussi les sauvegardes.',
                    'grcmanager'
                ),
                [
                    __('Une copie est-elle conservée hors site ou hors ligne, non modifiable ?', 'grcmanager'),
                    __('Une restauration complète est-elle testée régulièrement ?', 'grcmanager'),
                ],
            ],
            'outdated_diagrams' => [
                __('Schéma réseau et plan d\'adressage non tenus à jour', 'grcmanager'),
                __(
                    'Le schéma réseau, le plan d\'adressage et la liste des flux ne reflètent plus l\'existant.',
                    'grcmanager'
                ),
                __(
                    'Décisions prises sur une vision fausse, incident plus long à diagnostiquer, équipements '
                    . 'oubliés hors de toute surveillance.',
                    'grcmanager'
                ),
                [
                    __('La date de la dernière mise à jour du schéma est-elle connue ?', 'grcmanager'),
                    __('Une revue périodique du schéma et des flux est-elle planifiée ?', 'grcmanager'),
                ],
            ],
            'logs_not_reviewed' => [
                __('Journaux de sécurité ni centralisés ni consultés', 'grcmanager'),
                __(
                    'Les journaux des pare-feu, de l\'annuaire et des serveurs restent sur chaque équipement, sans '
                    . 'alerte ni revue.',
                    'grcmanager'
                ),
                __(
                    'Intrusion détectée tard, ou jamais ; journaux effacés par l\'attaquant ; impossibilité de '
                    . 'reconstituer un incident.',
                    'grcmanager'
                ),
                [
                    __('Les journaux sont-ils centralisés et conservés assez longtemps ?', 'grcmanager'),
                    __('Des alertes ou une revue régulière existent-elles ?', 'grcmanager'),
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
            $description = $entry['description'] . "\n\n" . __('Menaces :', 'grcmanager') . ' ' . $entry['threats']
                . "\n\n" . __('Questions pour savoir s\'il s\'applique :', 'grcmanager') . "\n- "
                . implode("\n- ", $entry['questions']);
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

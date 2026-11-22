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

use GlpiPlugin\Grcmanager\Compat\Http;
use GlpiPlugin\Grcmanager\Config\ConfigTabs;
use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Breach\BreachMonitorConfig;
use GlpiPlugin\Grcmanager\Services\Breach\HibpClient;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Cve\NvdConfig;
use GlpiPlugin\Grcmanager\Services\Lifecycle\EndOfLifeStore;
use GlpiPlugin\Grcmanager\IncidentSecurityGroup;
use GlpiPlugin\Grcmanager\Services\Incident\CraReportingConfig;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentModuleConfig;
use GlpiPlugin\Grcmanager\Services\Incident\SentinelOneConfig;
use GlpiPlugin\Grcmanager\SentinelOneAlert;
use GlpiPlugin\Grcmanager\Services\Notification\SensitiveNotification;
use GlpiPlugin\Grcmanager\Services\Policy\PolicyApprovalConfig;
use GlpiPlugin\Grcmanager\Services\Risk\RiskMatrixConfig;

Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, READ);

if (isset($_POST['update_risk_matrix'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    // Issue #706 : grille de la taille configurée (4x4 ou 5x5) ; la taille elle-même ne change
    // que par front/riskscale.php, après validation de la correspondance.
    $probabilities = array_keys(\GlpiPlugin\Grcmanager\Risk::getProbabilities());
    $impacts       = array_keys(\GlpiPlugin\Grcmanager\Risk::getImpacts());
    $levels        = array_keys(\GlpiPlugin\Grcmanager\Risk::getRiskLevels());

    $matrix = [];
    foreach ($probabilities as $probability) {
        foreach ($impacts as $impact) {
            $value = $_POST['matrix_' . $probability . '_' . $impact] ?? 'medium';
            // Closed enumeration, never trusted as free text from the request.
            $matrix[$probability][$impact] = in_array($value, $levels, true) ? $value : 'medium';
        }
    }

    RiskMatrixConfig::save($matrix);

    Html::back();
}

if (isset($_POST['update_risk_scale_labels'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    // Issue #706, lot 2 : libellés des niveaux de probabilité et d'impact. Seules les clés connues
    // sont gardées (RiskScale::sanitizeLabels) ; un champ vide remet le libellé par défaut, traduit.
    // Les libellés des valeurs hors de la taille actuelle sont conservés tels quels.
    $before = RiskMatrixConfig::labels();
    $posted = is_array($_POST['scale_labels'] ?? null) ? $_POST['scale_labels'] : [];
    $after = $before;
    $size = RiskMatrixConfig::size();
    foreach (
        [
            'probability' => \GlpiPlugin\Grcmanager\Services\Risk\RiskScale::probabilityKeys($size),
            'impact'      => \GlpiPlugin\Grcmanager\Services\Risk\RiskScale::impactKeys($size),
        ] as $axis => $keys
    ) {
        foreach ($keys as $key) {
            unset($after[$axis][$key]);
            $value = $posted[$axis][$key] ?? null;
            if (is_string($value)) {
                $after[$axis][$key] = $value;
            }
        }
    }
    RiskMatrixConfig::saveLabels($after);
    $saved = RiskMatrixConfig::labels();
    if ($saved !== $before) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'risk_scale_labels' => ['from' => $before, 'to' => $saved],
        ]);
    }
    Session::addMessageAfterRedirect(__('The level labels have been saved.', 'grcmanager'));

    Html::back();
}

if (isset($_POST['update_securityincident_modules'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    $modules = [
        'securityincident_enabled'           => isset($_POST['securityincident_enabled']),
        'securityincident_cve_enabled'       => isset($_POST['securityincident_cve_enabled']),
        'securityincident_templates_enabled' => isset($_POST['securityincident_templates_enabled']),
        'securityincident_dashboard_enabled' => isset($_POST['securityincident_dashboard_enabled']),
    ];
    $before = SecurityIncidentModuleConfig::load() + [
        'security_groups_id'    => IncidentSecurityGroup::groupId(),
        'cra_reporting_enabled' => CraReportingConfig::isEnabled(),
    ];
    SecurityIncidentModuleConfig::save($modules);
    // Issue #371 : groupe sécurité optionnel.
    IncidentSecurityGroup::save((int) ($_POST['security_groups_id'] ?? 0));
    // Issue #600 : option « signalement CRA ».
    CraReportingConfig::save(isset($_POST['cra_reporting_enabled']));
    $after = $modules + [
        'security_groups_id'    => IncidentSecurityGroup::groupId(),
        'cra_reporting_enabled' => CraReportingConfig::isEnabled(),
    ];
    $changes = array_diff_assoc(array_map('intval', $after), array_map('intval', $before));
    if ($changes !== []) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => $changes]);
    }
    // Issue #371 : module désactivé, alerte immédiate au groupe RSSI.
    if (!empty($before['securityincident_enabled']) && !$modules['securityincident_enabled']) {
        \GlpiPlugin\Grcmanager\IncidentAccessWatch::moduleDisabled();
    }

    Html::back();
}

// Issue #140 : groupe RSSI, audience des documents de tous les audits.
// Registres sensibles : contenu des notifications (risques, risques fournisseurs, actions de
// traitement). Réglage réservé à qui gère ces registres.
if (isset($_POST['update_sensitive_config'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::SENSITIVE_RIGHT, UPDATE);

    SensitiveNotification::setMinimal(!empty($_POST['notifications_minimal']));

    Html::back();
}

// Issue #183 : surveillance des fuites de données (Have I Been Pwned). Registre sensible : réglage
// réservé à qui gère le plugin ET les registres sensibles. La clé n'est jamais renvoyée au navigateur.
if (isset($_POST['update_breach_config']) || isset($_POST['test_breach_config'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::SENSITIVE_RIGHT, UPDATE);

    if (isset($_POST['update_breach_config'])) {
        BreachMonitorConfig::save(
            !empty($_POST['hibp_enabled']),
            (string) ($_POST['hibp_domains'] ?? ''),
            (string) ($_POST['hibp_api_key'] ?? ''),
            !empty($_POST['hibp_clear_key'])
        );
    } else {
        $client = new HibpClient(BreachMonitorConfig::apiKey());
        $response = $client->subscribedDomains();
        // Offre sans recherche par domaine : 401 même avec une clé valide. La clé est alors éprouvée
        // sur un compte de test publié par Have I Been Pwned (aucune adresse réelle n'est envoyée).
        $verified = $response->isOk() ? (array) $response->data : [];
        if (!$response->isOk()) {
            $response = $client->breachedAccount(HibpClient::TEST_ACCOUNT);
        }
        if ($response->isOk()) {
            $lines = [];
            foreach (BreachMonitorConfig::domains() as $domain) {
                $lines[] = in_array($domain, $verified, true)
                    ? sprintf(__('%s: verified domain, one request per run', 'grcmanager'), $domain)
                    : sprintf(__('%s: checked address by address', 'grcmanager'), $domain);
            }
            Session::addMessageAfterRedirect(htmlescape(
                __('Have I Been Pwned key accepted.', 'grcmanager') . ' ' . implode(' ; ', $lines)
            ));
        } else {
            $message = $response->isUnauthorized()
                ? __('Have I Been Pwned refused the API key.', 'grcmanager')
                : sprintf(__('Have I Been Pwned could not be reached (code %d).', 'grcmanager'), $response->status);
            Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);
        }
    }

    Html::back();
}

// Issue #707 : connecteur SentinelOne en lecture seule. Réglage réservé au droit de modifier la
// configuration du plugin ; le jeton n'est jamais renvoyé au navigateur ni écrit dans un journal.
if (
    isset($_POST['update_sentinelone_config'])
    || isset($_POST['test_sentinelone_config'])
    || isset($_POST['fetch_sentinelone_now'])
) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    if (isset($_POST['update_sentinelone_config'])) {
        $wasEnabled = SentinelOneConfig::isEnabled();
        $hadToken = SentinelOneConfig::hasToken();
        $previousUrl = SentinelOneConfig::rawUrl();
        $accepted = SentinelOneConfig::save(
            !empty($_POST['sentinelone_enabled']),
            (string) ($_POST['sentinelone_url'] ?? ''),
            (string) ($_POST['sentinelone_api_token'] ?? ''),
            !empty($_POST['sentinelone_clear_token']),
            (int) ($_POST['sentinelone_entities_id'] ?? 0)
        );
        if (!$accepted) {
            Session::addMessageAfterRedirect(htmlescape(__(
                'The console address was not saved: it must start with https:// and contain no user name or password.',
                'grcmanager'
            )), false, ERROR);
        }
        // La tâche automatique suit l'interrupteur : désactivée tant que le connecteur l'est.
        SentinelOneAlert::syncCronState(SentinelOneConfig::isEnabled());
        $changes = array_filter([
            'sentinelone_enabled' => $wasEnabled !== SentinelOneConfig::isEnabled()
                ? (int) SentinelOneConfig::isEnabled()
                : null,
            'sentinelone_url'     => $previousUrl !== SentinelOneConfig::rawUrl() ? SentinelOneConfig::rawUrl() : null,
            // Jeton : seulement le fait qu'il ait changé, jamais sa valeur.
            'sentinelone_token'   => trim((string) ($_POST['sentinelone_api_token'] ?? '')) !== ''
                || ($hadToken && !SentinelOneConfig::hasToken()) ? 'changed' : null,
        ], static fn ($value): bool => $value !== null);
        if ($changes !== []) {
            \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', [
                'changes' => $changes,
            ]);
        }
    } elseif (!SentinelOneConfig::isConfigured()) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'Enter the console address and the API token first.',
            'grcmanager'
        )), false, ERROR);
    } elseif (isset($_POST['test_sentinelone_config'])) {
        $test = SentinelOneConfig::client()->testConnection();
        if (!$test['ok']) {
            Session::addMessageAfterRedirect(htmlescape(SentinelOneAlert::errorLabel($test['error'])), false, ERROR);
        } elseif ($test['empty']) {
            Session::addMessageAfterRedirect(htmlescape(__(
                'Connection accepted, but the token sees no agent and no threat: check '
                    . 'that its user has the Viewer role on the right accounts or sites.',
                'grcmanager'
            )), false, WARNING);
        } else {
            $message = sprintf(
                __('Connection to SentinelOne succeeded: %1$d agent(s) and %2$s threat(s) visible.', 'grcmanager'),
                (int) $test['agents'],
                $test['threats'] === null ? '?' : (string) $test['threats']
            );
            if ($test['error'] !== '') {
                $message .= ' ' . SentinelOneAlert::errorLabel($test['error']);
            }
            Session::addMessageAfterRedirect(htmlescape($message));
        }
    } else {
        // Issue #1041 : « Récupérer maintenant » est passé sur la page d'import des alertes EDR
        // (Assistance > Incidents de sécurité). Ancien envoi accepté pendant une version : la lecture
        // est faite, puis la page d'exploitation s'ouvre avec un message.
        SentinelOneAlert::fetchNow();
        Session::addMessageAfterRedirect(htmlescape(__(
            'Reading SentinelOne now and reviewing its alerts is done in Assistance > Security '
                . 'incidents > EDR alerts import.',
            'grcmanager'
        )));
        Html::redirect(\GlpiPlugin\Grcmanager\SecurityIncidentEdrImport::getImportURL() . '#grc-sentinelone-review');
    }

    Html::back();
}

// Issue #403 : référentiels de conformité actifs. Rien n'est supprimé : un référentiel désactivé
// est seulement masqué et ignoré des calculs, ses données reviennent à la réactivation.
if (isset($_POST['update_frameworks'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    $before = FrameworkConfig::enabled();
    FrameworkConfig::save((array) ($_POST['frameworks'] ?? []));
    $after = FrameworkConfig::enabled();
    if ($before !== $after) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => [
            'frameworks_enabled'  => array_values(array_diff($after, $before)),
            'frameworks_disabled' => array_values(array_diff($before, $after)),
        ]]);
    }

    Html::back();
}

// Issue #703 : catégorie NIS2 italienne (importante ou essentielle) de chaque entité, qui crée son
// registre ACN. Seules les entités de la session sont modifiables ; rien n'est supprimé.
if (isset($_POST['update_acn_entities'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    $changes = [];
    $created = 0;
    foreach ((array) ($_POST['acn_category'] ?? []) as $entityId => $category) {
        $entityId = (int) $entityId;
        if (!Session::haveAccessToEntity($entityId)) {
            continue;
        }
        $category = \GlpiPlugin\Grcmanager\Services\Acn\AcnRules::normalizeCategory($category);
        if ($category === \GlpiPlugin\Grcmanager\AcnRequirement::categoryOf($entityId)) {
            continue;
        }
        $created += \GlpiPlugin\Grcmanager\AcnRequirement::setCategory($entityId, $category);
        $changes['acn_category_' . $entityId] = $category;
    }
    if ($changes !== []) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => $changes]);
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('Classification saved; %d requirement record(s) created.', 'grcmanager'),
            $created
        )));
    }

    Html::back();
}

// Issue #599 : catégorie NIS2 (importante ou essentielle) de chaque entité pour le ReCyF, qui crée son
// registre ReCyF. Seules les entités de la session sont modifiables ; rien n'est supprimé.
if (isset($_POST['update_recyf_entities'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    $changes = [];
    $created = 0;
    foreach ((array) ($_POST['recyf_category'] ?? []) as $entityId => $category) {
        $entityId = (int) $entityId;
        if (!Session::haveAccessToEntity($entityId)) {
            continue;
        }
        $category = \GlpiPlugin\Grcmanager\Services\Recyf\RecyfRules::normalizeCategory($category);
        if ($category === \GlpiPlugin\Grcmanager\RecyfRequirement::categoryOf($entityId)) {
            continue;
        }
        $created += \GlpiPlugin\Grcmanager\RecyfRequirement::setCategory($entityId, $category);
        $changes['recyf_category_' . $entityId] = $category;
    }
    if ($changes !== []) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', ['changes' => $changes]);
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __('Classification saved; %d measure record(s) created.', 'grcmanager'),
            $created
        )));
    }

    Html::back();
}

// Issue #375 : contact du DPO, repris sur l'export du registre des traitements (article 30).
if (isset($_POST['update_dpo_contact'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    $before = \GlpiPlugin\Grcmanager\ProcessingActivity::dpoContact();
    \GlpiPlugin\Grcmanager\ProcessingActivity::saveDpoContact((string) ($_POST['dpo_contact'] ?? ''));
    if ($before !== \GlpiPlugin\Grcmanager\ProcessingActivity::dpoContact()) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'changes' => ['dpo_contact' => true],
        ]);
    }

    Html::back();
}

// Issue #380 : groupe RSSI prévenu des tests d'efficacité en retard ou proches de l'échéance.
if (isset($_POST['update_controltest_reminder'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    // Énumération fermée : une valeur inconnue revient au réglage par défaut.
    $cisoMode = (string) ($_POST['controltest_reminder_ciso'] ?? '');
    if (\GlpiPlugin\Grcmanager\ControlTestReminder::saveCisoMode($cisoMode)) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'changes' => ['controltest_reminder_ciso' => \GlpiPlugin\Grcmanager\ControlTestReminder::cisoMode()],
        ]);
    }

    Html::back();
}

// Issue #607 : relecture des tests d'efficacité par une seconde personne.
if (isset($_POST['update_controltest_review'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    if (\GlpiPlugin\Grcmanager\ControlTest::saveReviewEnabled($_POST['controltest_review'] ?? '0')) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'changes' => ['controltest_review' => \GlpiPlugin\Grcmanager\ControlTest::isReviewEnabled()],
        ]);
    }

    Html::back();
}

// Issue #378 : niveau d'alerte des indicateurs de risque (rouge seulement, ou dès l'orange).
if (isset($_POST['update_kri_alert'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    // Énumération fermée : une valeur inconnue revient au réglage par défaut.
    if (\GlpiPlugin\Grcmanager\KriAlert::saveLevel((string) ($_POST['kri_alert_level'] ?? ''))) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'changes' => ['kri_alert_level' => \GlpiPlugin\Grcmanager\KriAlert::level()],
        ]);
    }

    Html::back();
}

// Issue #614 : contrôles de cohérence du SMSI, règles actives et seuils.
if (isset($_POST['update_consistency_rules'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    $posted = $_POST['consistency'] ?? [];
    if (\GlpiPlugin\Grcmanager\ConsistencyCheck::saveSettings(is_array($posted) ? $posted : [])) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'changes' => ['consistency_rules' => true],
        ]);
    }

    Html::back();
}

// Issue #664 : durée de vie des liens d'abonnement au calendrier (jeton dédié). S'applique aux liens
// créés ensuite ; ceux déjà créés gardent leur date d'expiration.
if (isset($_POST['update_maturity_scale_labels'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    // Issue #603 : libellés de l'échelle de maturité par mesure (0 à 5). Un champ vide remet le
    // libellé par défaut, traduit ; seules les clés 0 à 5 sont gardées (MaturityGapRules::sanitizeLabels).
    $before = \GlpiPlugin\Grcmanager\MaturityCampaign::customScaleLabels();
    \GlpiPlugin\Grcmanager\MaturityCampaign::saveScaleLabels(
        is_array($_POST['maturity_labels'] ?? null) ? $_POST['maturity_labels'] : []
    );
    $after = \GlpiPlugin\Grcmanager\MaturityCampaign::customScaleLabels();
    if ($after !== $before) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'maturity_scale_labels' => ['from' => $before, 'to' => $after],
        ]);
    }
    Session::addMessageAfterRedirect(__('The level labels have been saved.', 'grcmanager'));

    Html::back();
}

if (isset($_POST['update_calendar_token'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    if (\GlpiPlugin\Grcmanager\CalendarToken::saveLifetime($_POST['calendar_token_lifetime_days'] ?? null)) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'changes' => ['calendar_token_lifetime_days' => \GlpiPlugin\Grcmanager\CalendarToken::lifetimeDays()],
        ]);
    }

    Html::back();
}

// Issue #546 : délai des nouveaux arrivants et poids de chaque composante de la fiche de
// conformité sécurité d'un collaborateur.
if (isset($_POST['update_personnel_compliance'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    $weights = $_POST['personnel_compliance_weights'] ?? [];
    if (
        \GlpiPlugin\Grcmanager\PersonnelCompliance::saveSettings(
            $_POST['personnel_compliance_days'] ?? null,
            is_array($weights) ? $weights : []
        )
    ) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'changes' => [
                'personnel_compliance_days'    => \GlpiPlugin\Grcmanager\PersonnelCompliance::days(),
                'personnel_compliance_weights' => \GlpiPlugin\Grcmanager\PersonnelCompliance::weights(),
            ],
        ]);
    }

    Html::back();
}

// Issue #604, lot 2 : durée de conservation des résultats individuels des simulations
// d'hameçonnage (données personnelles) : droit de modification du plugin et des registres sensibles.
if (isset($_POST['update_phishing_retention'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);
    Session::checkRight(Installer::SENSITIVE_RIGHT, UPDATE);

    if (\GlpiPlugin\Grcmanager\PhishingCampaign::saveRetention($_POST['phishing_retention_months'] ?? null)) {
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'changes' => ['phishing_retention_months' => \GlpiPlugin\Grcmanager\PhishingCampaign::retentionMonths()],
        ]);
    }

    Html::back();
}

// Issue #611 : circuit d'approbation des politiques de sécurité par la direction.
if (isset($_POST['update_policy_approval'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    PolicyApprovalConfig::save(
        !empty($_POST['policy_approval_enabled']),
        PolicyApprovalConfig::fromForm(
            (array) ($_POST['policy_approval_users'] ?? []),
            (array) ($_POST['policy_approval_groups'] ?? [])
        ),
        (int) ($_POST['policy_approval_quorum'] ?? 0)
    );

    Html::back();
}

// Issue #775 : domaines attendus des procédures d'exploitation (A.5.37) : domaines par défaut
// cochés, puis domaines supplémentaires, un par ligne (OperatingProcedureRules::fromForm()).
if (isset($_POST['update_operating_procedures'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    \GlpiPlugin\Grcmanager\OperatingProcedure::saveDomains(
        \GlpiPlugin\Grcmanager\Services\Policy\OperatingProcedureRules::fromForm(
            (array) ($_POST['operating_procedure_domains'] ?? []),
            is_string($_POST['operating_procedure_others'] ?? null) ? $_POST['operating_procedure_others'] : ''
        )
    );

    Html::back();
}

// Issue #733 : types des exercices de reprise : types par défaut cochés, puis types propres à
// l'organisation, un par ligne (RecoveryExerciseRules::fromForm()).
if (isset($_POST['update_recovery_exercise_types'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    \GlpiPlugin\Grcmanager\BusinessProcessRecovery::saveExerciseTypes(
        \GlpiPlugin\Grcmanager\Services\Continuity\RecoveryExerciseRules::fromForm(
            (array) ($_POST['recovery_exercise_types'] ?? []),
            is_string($_POST['recovery_exercise_others'] ?? null) ? $_POST['recovery_exercise_others'] : ''
        )
    );

    Html::back();
}

// Issue #658 : adresses internes pour les appels sortants. Réglage global (toutes les entités),
// réservé au droit de modifier la configuration générale de GLPI.
if (isset($_POST['update_http_config'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);
    Session::checkRight('config', UPDATE);

    $allow = !empty($_POST['http_allow_private_networks']);
    if ($allow !== Http::allowsPrivateNetworks()) {
        Http::setAllowPrivateNetworks($allow);
        \GlpiPlugin\Grcmanager\AuditLog::record('config_change', 'Config', 0, 0, 'success', [
            'changes' => ['http_allow_private_networks' => (int) $allow],
        ]);
    }

    Html::back();
}

if (isset($_POST['update_audit_config'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    \GlpiPlugin\Grcmanager\AuditTeam::setCisoGroupId((int) ($_POST['ciso_groups_id'] ?? 0));

    Html::back();
}

// Issue #656 : groupe Direction de l'appétence au risque (issue #378), déplacé ici depuis la page
// de l'appétence (aucun réglage hors de la page de configuration). Registre sensible.
if (isset($_POST['update_risk_appetite_config'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);
    Session::checkRight(\GlpiPlugin\Grcmanager\RiskAppetite::RIGHTNAME, UPDATE);

    // Journal d'audit tenu par setDirectionGroupId().
    \GlpiPlugin\Grcmanager\RiskAppetite::setDirectionGroupId((int) ($_POST['direction_groups_id'] ?? 0));

    Html::back();
}

// Issue #704 : fin de vie des logiciels, systèmes et firmwares (endoflife.date). Source désactivée
// par défaut ; les correspondances manuelles servent à rapprocher un nom de GLPI d'un produit.
if (
    isset($_POST['update_endoflife_config']) || isset($_POST['refresh_endoflife'])
    || isset($_POST['add_endoflife_mapping']) || isset($_POST['delete_endoflife_mapping'])
) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    if (isset($_POST['update_endoflife_config'])) {
        $before = EndOfLifeStore::settings();
        EndOfLifeStore::save(
            !empty($_POST['endoflife_enabled']),
            is_numeric($_POST['endoflife_warning_days'] ?? null)
                ? (int) $_POST['endoflife_warning_days']
                : EndOfLifeStore::DEFAULT_WARNING_DAYS
        );
        $changes = array_diff_assoc(array_map('intval', EndOfLifeStore::settings()), array_map('intval', $before));
        if ($changes !== []) {
            \GlpiPlugin\Grcmanager\AuditLog::record(
                'config_change',
                'Config',
                0,
                0,
                'success',
                ['changes' => $changes]
            );
        }
    } elseif (isset($_POST['refresh_endoflife'])) {
        // Quelques produits seulement : le reste suit avec la tâche automatique quotidienne.
        $report = \GlpiPlugin\Grcmanager\EndOfLife::refresh(30);
        Session::addMessageAfterRedirect(
            htmlescape(\GlpiPlugin\Grcmanager\EndOfLife::refreshMessage($report)),
            false,
            $report['status'] === 'partial' ? WARNING : INFO
        );
    } elseif (isset($_POST['add_endoflife_mapping'])) {
        $saved = EndOfLifeStore::saveMapping(
            (string) ($_POST['endoflife_kind'] ?? ''),
            (string) ($_POST['endoflife_name'] ?? ''),
            (string) ($_POST['endoflife_product'] ?? '')
        );
        if (!$saved) {
            Session::addMessageAfterRedirect(htmlescape(__(
                'Link not saved: give the exact name shown in GLPI and an endoflife.date product identifier '
                    . '(lower case letters, digits and dashes, for instance "debian").',
                'grcmanager'
            )), false, ERROR);
        }
    } else {
        EndOfLifeStore::deleteMapping((int) $_POST['delete_endoflife_mapping']);
    }

    Html::redirect(\GlpiPlugin\Grcmanager\EndOfLife::getConfigURL());
}

// Issue #782, lot 2 : fin de vie déclarée par le constructeur, saisie à la main par modèle de
// matériel (facultatif). Rien n'est écrit dans les modèles de GLPI.
if (isset($_POST['update_hardware_eol'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    $refused = \GlpiPlugin\Grcmanager\HardwareWarranty::saveModelDates(
        is_array($_POST['hardware_eol'] ?? null) ? $_POST['hardware_eol'] : []
    );
    if ($refused > 0) {
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            _n(
                '%d end of life date not saved: enter a valid date for a hardware model.',
                '%d end of life dates not saved: enter a valid date for a hardware model.',
                $refused,
                'grcmanager'
            ),
            $refused
        )), false, ERROR);
    }

    Html::redirect(\GlpiPlugin\Grcmanager\HardwareWarranty::getConfigURL());
}

if (isset($_POST['update_nvd_config'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

    NvdConfig::save([
        'enable_nvd_enrichment' => isset($_POST['enable_nvd_enrichment']),
        // Never trusted as free text: a non-numeric or out-of-range submission falls back to the
        // documented default rather than storing something a Twig template would then have to
        // guard against when formatting/comparing it.
        'cvss_alert_threshold'  => is_numeric($_POST['cvss_alert_threshold'] ?? null)
            && (float) $_POST['cvss_alert_threshold'] >= 0.0
            && (float) $_POST['cvss_alert_threshold'] <= 10.0
                ? (float) $_POST['cvss_alert_threshold']
                : \GlpiPlugin\Grcmanager\Services\Cve\NvdConfigDefaults::CVSS_ALERT_THRESHOLD,
    ]);
    // Issue #160, phase 2 : seuils des tickets automatiques des vulnérabilités du parc.
    \GlpiPlugin\Grcmanager\ParkVulnerability::saveTicketThresholds($_POST);
    // Issue #601 : délais de correction des vulnérabilités du parc.
    \GlpiPlugin\Grcmanager\ParkRemediation::saveSettings($_POST);
    // Issue #485 : rapport des vulnérabilités du parc par e-mail.
    \GlpiPlugin\Grcmanager\VulnerabilityDigest::saveSettings($_POST);

    Html::back();
}

// Issue #485 : envoi immédiat du rapport des vulnérabilités, pour l'essayer sans attendre la tâche.
if (isset($_POST['send_vulnerability_digest'])) {
    Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);
    if (!\GlpiPlugin\Grcmanager\Services\Cve\ParkVulnerabilitySync::isEnabled()) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'Enable the NVD enrichment first: without it, the report would wrongly say that nothing is exposed.',
            'grcmanager'
        )), false, ERROR);
    } elseif (\GlpiPlugin\Grcmanager\VulnerabilityDigest::send() > 0) {
        Session::addMessageAfterRedirect(htmlescape(__(
            'The report has been queued for sending to its recipients.',
            'grcmanager'
        )));
    } else {
        Session::addMessageAfterRedirect(htmlescape(__(
            'The report could not be sent: check that notifications are enabled in GLPI and that the '
                . 'notification "GRC Manager - Fleet vulnerability report" is active.',
            'grcmanager'
        )), false, ERROR);
    }

    Html::back();
}

// Issue #1036 : les blocs ajoutés ensuite traitent eux-mêmes leur formulaire (ConfigBlock::handle()).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ConfigTabs::handlePost();
}

// Issue #1036 : page en onglets, rangée sous Configuration > Plugins. L'onglet ouvert est dans
// l'adresse (?tab=<clé>) ; seuls les blocs de cet onglet sont calculés et affichés.
$tab = ConfigTabs::current($_GET['tab'] ?? null);
if ($tab === '') {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$page = ConfigTabs::pageData($tab);
Html::header(
    __('Configuration', 'grcmanager') . ' - ' . $page['current_label'],
    $_SERVER['PHP_SELF'],
    'config',
    'plugin'
);
\GlpiPlugin\Grcmanager\PageHelp::show('config');

\Glpi\Application\View\TemplateRenderer::getInstance()->display(
    '@grcmanager/config_form.html.twig',
    $page + [
        'csrf_token'         => Session::getNewCSRFToken(),
        'can_edit_config'    => (bool) Session::haveRight(Installer::RIGHT_NAME, UPDATE),
        'can_edit_sensitive' => (bool) Session::haveRight(Installer::SENSITIVE_RIGHT, UPDATE),
    ]
);

Html::footer();

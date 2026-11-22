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

namespace GlpiPlugin\Grcmanager\Navigation\Section;

use GlpiPlugin\Grcmanager\AccessReview;
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\CrisisContinuity;
use GlpiPlugin\Grcmanager\CrisisExercise;
use GlpiPlugin\Grcmanager\CrisisMessageTemplate;
use GlpiPlugin\Grcmanager\CrisisScenario;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\Services\Incident\SecurityIncidentModuleConfig;
use PluginGrcmanagerSecurityIncident;

/**
 * Issue #1041 : « Assistance > Crise et continuité », juste après les incidents de sécurité. On gère
 * une crise depuis l'Assistance, à côté des incidents : préparer (processus métier et BIA, scénarios
 * de crise), s'entraîner (exercices, programme des tests de reprise), communiquer (modèles de
 * messages de crise). Page d'accueil à cartes groupées (CrisisContinuity).
 *
 * Les exercices, processus et scénarios quittaient la rubrique « Opérations » du menu GRC (option
 * from) ; les modèles de messages, les liens de l'entrée Incidents de sécurité. Il reste deux places
 * pour les plans de continuité et de reprise (issue #1083).
 */
final class CrisisContinuitySection extends AbstractSection
{
    public static function sector(): string
    {
        return 'helpdesk';
    }

    public static function parent(): string
    {
        return CrisisContinuity::class;
    }

    /** Après les incidents de sécurité, ou après « Changements » si le module des incidents est désactivé. */
    public static function after(): string
    {
        return (bool) SecurityIncidentModuleConfig::load()['securityincident_enabled']
            ? PluginGrcmanagerSecurityIncident::class
            : \Change::class;
    }

    public static function pages(): array
    {
        $prepare = __('Prepare', 'grcmanager');
        $train = __('Train', 'grcmanager');

        return [
            // Issue #377 : processus métier et analyses d'impact sur l'activité (BIA).
            BusinessProcess::class => ['from' => AccessReview::class, 'group' => $prepare],
            // Issue #776 : scénarios de crise sécurité de l'information du PCA (A.5.29).
            CrisisScenario::class  => ['from' => AccessReview::class, 'group' => $prepare],
            CrisisExercise::class  => ['from' => AccessReview::class, 'group' => $train],
            // Issue #733 : programme des tests de reprise des processus métier.
            CrisisContinuity::RECOVERY_PROGRAMME => [
                'title'     => __('Recovery test programme', 'grcmanager'),
                'page'      => '/plugins/grcmanager/front/recoveryprogramme.php',
                'icon'      => 'ti ti-calendar-repeat',
                'rights_of' => BusinessProcess::class,
                'group'     => $train,
            ],
            // Issue #616 : modèles de messages de crise et leurs listes de diffusion.
            CrisisMessageTemplate::class => ['group' => __('Communicate', 'grcmanager')],
        ];
    }
}

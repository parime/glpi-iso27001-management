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

use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\AuditProgramme;
use GlpiPlugin\Grcmanager\CertificationFinding;
use GlpiPlugin\Grcmanager\CertificationSampling;
use GlpiPlugin\Grcmanager\CoverageRule;
use GlpiPlugin\Grcmanager\Evidence;
use GlpiPlugin\Grcmanager\EvidencePack;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\Policy;

/**
 * Issue #1038 : les audits et les preuves. Non-conformités, écarts, objectifs et revues de direction
 * sont dans « Amélioration continue » (ImprovementSection).
 */
final class AuditSection extends AbstractSection
{
    public static function parent(): string
    {
        return Audit::class;
    }

    public static function after(): string
    {
        return Policy::class;
    }

    public static function title(): string
    {
        return __('Audits and evidence', 'grcmanager');
    }

    public static function hub(): bool
    {
        return true;
    }

    public static function description(): string
    {
        return __(
            'Prepare and follow the audits, and keep the evidence that the security measures are applied.',
            'grcmanager'
        );
    }

    public static function todoSources(): array
    {
        return ['audit', 'audit_access', 'evidence', 'evidence_request', 'evidence_review'];
    }

    public static function pages(): array
    {
        $audits = __('Audits', 'grcmanager');
        $evidence = __('Evidence', 'grcmanager');

        return [
            // Issue #773 : programme d'audit pluriannuel (ISO 19011).
            AuditProgramme::class => ['group' => $audits],
            // Issue #772 : constats des audits de certification et état de leur levée.
            CertificationFinding::class => ['group' => $audits],
            // Issue #772, lot 2 : plan d'audit de l'organisme (échantillonnage sur le cycle).
            CertificationSampling::class => ['group' => $audits],
            Evidence::class => ['group' => $evidence],
            // Issue #868 : dossier de preuves exportable pour l'auditeur (archive ZIP par périmètre).
            EvidencePack::class => ['group' => $evidence],
            CoverageRule::class => ['group' => $evidence],
        ];
    }
}

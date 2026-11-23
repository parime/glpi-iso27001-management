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

use GlpiPlugin\Grcmanager\MySpace;
use GlpiPlugin\Grcmanager\Navigation\AbstractSection;
use GlpiPlugin\Grcmanager\ReportedRisk;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\RiskAppetite;
use GlpiPlugin\Grcmanager\Waiver;

/**
 * Issue #1038 : le registre des risques et ce qui sert à le tenir (signalements, carte, catalogue,
 * import, appétence, dérogations). Les études et les indicateurs sont dans « Analyses de risques »
 * (RiskAnalysisSection). Les risques fournisseurs sont dans « Gestion > Sécurité des fournisseurs »
 * (SupplierSecuritySection, issue #1040).
 */
final class RiskSection extends AbstractSection
{
    /** Sous-pages sans classe propre : clés de menu de leur page front/. */
    public const HEATMAP = 'riskheatmap';
    public const CATALOG = 'riskcatalog';
    public const IMPORT = 'riskimport';

    public static function parent(): string
    {
        return Risk::class;
    }

    public static function after(): string
    {
        return MySpace::class;
    }

    public static function hub(): bool
    {
        return true;
    }

    public static function description(): string
    {
        return __(
            'Identify, assess and treat the risks of the organisation: the register, the risks reported by staff, '
                . 'the heatmap, the catalogue of typical risks and the accepted risks.',
            'grcmanager'
        );
    }

    public static function todoSources(): array
    {
        return ['risk_review', 'treatment_action'];
    }

    /** Carte du registre : « Risques » seul se confondait avec « Risques signalés ». */
    public static function parentCard(): array
    {
        return [
            'title'       => __('Risk register', 'grcmanager'),
            'description' => __('Assessed risks, their treatment and their follow-up.', 'grcmanager'),
        ];
    }

    public static function pages(): array
    {
        $register = __('Register', 'grcmanager');
        $tools = __('Map and library', 'grcmanager');
        $decisions = __('Decisions', 'grcmanager');

        return [
            // Issue #872 : risques signalés par les collaborateurs, à trier.
            ReportedRisk::class => [
                'group'            => $register,
                'card_title'       => __('Reports to sort', 'grcmanager'),
                'card_description' => __(
                    'Risks raised by staff, to add to the register or to dismiss.',
                    'grcmanager'
                ),
            ],
            self::HEATMAP => [
                'title'     => __('Risk heatmap', 'grcmanager'),
                'page'      => '/plugins/grcmanager/front/riskheatmap.php',
                'icon'      => 'ti ti-grid-dots',
                'rights_of' => Risk::class,
                'group'     => $tools,
            ],
            self::CATALOG => [
                'title'     => __('Catalog of typical risks', 'grcmanager'),
                'page'      => '/plugins/grcmanager/front/riskcatalog.php',
                'icon'      => 'ti ti-books',
                'rights_of' => Risk::class,
                'group'     => $tools,
            ],
            self::IMPORT => [
                'title'     => __('Import and export', 'grcmanager'),
                'page'      => '/plugins/grcmanager/front/riskimport.php',
                'icon'      => 'ti ti-file-import',
                'rights_of' => Risk::class,
                'group'     => $tools,
            ],
            RiskAppetite::class => ['group' => $decisions],
            Waiver::class => ['group' => $decisions],
        ];
    }
}

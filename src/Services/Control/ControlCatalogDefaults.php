<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * The 93 ISO/IEC 27001:2022 Annex A controls (identical list to ISO/IEC 27002:2022), organized in
 * the standard's own 4 themes: Organizational (A.5.1-A.5.37, 37 controls), People (A.6.1-A.6.8, 8
 * controls), Physical (A.7.1-A.7.14, 14 controls), Technological (A.8.1-A.8.34, 34 controls).
 *
 * Deliberately pure PHP with no GLPI dependency (unlike inc/control.class.php, which is the real
 * CommonDBTM this data seeds, see src/Install/Installer.php): only the stable ISO identifier and
 * theme travel here, not the (translatable) title, same separation already used for
 * \GlpiPlugin\Grcmanager\Risk's own category/probability/impact enums, whose *labels* live only in
 * inc/risk.class.php's static getters (__() calls), never baked into the database. See
 * \GlpiPlugin\Grcmanager\Control::getControlTitles() for the corresponding translated titles, and this
 * class' own unit test for the exhaustive count/theme verification (93 total, 37/8/14/34 per
 * theme, matching the real published standard, not an invented placeholder list).
 */
final class ControlCatalogDefaults
{
    /**
     * ISO control code => theme key (organizational/people/physical/technological).
     *
     * @var array<string, string>
     */
    public const CONTROLS = [
        // Organizational controls (37)
        'A.5.1'  => 'organizational',
        'A.5.2'  => 'organizational',
        'A.5.3'  => 'organizational',
        'A.5.4'  => 'organizational',
        'A.5.5'  => 'organizational',
        'A.5.6'  => 'organizational',
        'A.5.7'  => 'organizational',
        'A.5.8'  => 'organizational',
        'A.5.9'  => 'organizational',
        'A.5.10' => 'organizational',
        'A.5.11' => 'organizational',
        'A.5.12' => 'organizational',
        'A.5.13' => 'organizational',
        'A.5.14' => 'organizational',
        'A.5.15' => 'organizational',
        'A.5.16' => 'organizational',
        'A.5.17' => 'organizational',
        'A.5.18' => 'organizational',
        'A.5.19' => 'organizational',
        'A.5.20' => 'organizational',
        'A.5.21' => 'organizational',
        'A.5.22' => 'organizational',
        'A.5.23' => 'organizational',
        'A.5.24' => 'organizational',
        'A.5.25' => 'organizational',
        'A.5.26' => 'organizational',
        'A.5.27' => 'organizational',
        'A.5.28' => 'organizational',
        'A.5.29' => 'organizational',
        'A.5.30' => 'organizational',
        'A.5.31' => 'organizational',
        'A.5.32' => 'organizational',
        'A.5.33' => 'organizational',
        'A.5.34' => 'organizational',
        'A.5.35' => 'organizational',
        'A.5.36' => 'organizational',
        'A.5.37' => 'organizational',

        // People controls (8)
        'A.6.1' => 'people',
        'A.6.2' => 'people',
        'A.6.3' => 'people',
        'A.6.4' => 'people',
        'A.6.5' => 'people',
        'A.6.6' => 'people',
        'A.6.7' => 'people',
        'A.6.8' => 'people',

        // Physical controls (14)
        'A.7.1'  => 'physical',
        'A.7.2'  => 'physical',
        'A.7.3'  => 'physical',
        'A.7.4'  => 'physical',
        'A.7.5'  => 'physical',
        'A.7.6'  => 'physical',
        'A.7.7'  => 'physical',
        'A.7.8'  => 'physical',
        'A.7.9'  => 'physical',
        'A.7.10' => 'physical',
        'A.7.11' => 'physical',
        'A.7.12' => 'physical',
        'A.7.13' => 'physical',
        'A.7.14' => 'physical',

        // Technological controls (34)
        'A.8.1'  => 'technological',
        'A.8.2'  => 'technological',
        'A.8.3'  => 'technological',
        'A.8.4'  => 'technological',
        'A.8.5'  => 'technological',
        'A.8.6'  => 'technological',
        'A.8.7'  => 'technological',
        'A.8.8'  => 'technological',
        'A.8.9'  => 'technological',
        'A.8.10' => 'technological',
        'A.8.11' => 'technological',
        'A.8.12' => 'technological',
        'A.8.13' => 'technological',
        'A.8.14' => 'technological',
        'A.8.15' => 'technological',
        'A.8.16' => 'technological',
        'A.8.17' => 'technological',
        'A.8.18' => 'technological',
        'A.8.19' => 'technological',
        'A.8.20' => 'technological',
        'A.8.21' => 'technological',
        'A.8.22' => 'technological',
        'A.8.23' => 'technological',
        'A.8.24' => 'technological',
        'A.8.25' => 'technological',
        'A.8.26' => 'technological',
        'A.8.27' => 'technological',
        'A.8.28' => 'technological',
        'A.8.29' => 'technological',
        'A.8.30' => 'technological',
        'A.8.31' => 'technological',
        'A.8.32' => 'technological',
        'A.8.33' => 'technological',
        'A.8.34' => 'technological',
    ];
}

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

use GlpiPlugin\Grcmanager\Services\Control\AnssiHygieneCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\CisControlsCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\ControlCatalogDefaults;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Grcmanager\Services\Control\ControlCrosswalkDefaults;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkConfig;
use GlpiPlugin\Grcmanager\Services\Control\FrameworkSelection;
use GlpiPlugin\Grcmanager\Services\Control\Iso27090CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\DoraCatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Iso22301CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\Iso27701CatalogDefaults;
use GlpiPlugin\Grcmanager\Audit;
use GlpiPlugin\Grcmanager\CrisisExercise;
use GlpiPlugin\Grcmanager\ManagementReview;
use GlpiPlugin\Grcmanager\Risk;
use GlpiPlugin\Grcmanager\DataSubjectRequest;
use GlpiPlugin\Grcmanager\Dpia;
use GlpiPlugin\Grcmanager\ProcessingActivity;
use GlpiPlugin\Grcmanager\SupplierRisk;
use GlpiPlugin\Grcmanager\Services\Control\Nis2CatalogDefaults;
use GlpiPlugin\Grcmanager\Services\Control\NistCsfCatalogDefaults;

// Reuses \GlpiPlugin\Grcmanager\Control's own right rather than a dedicated one: this screen only
// displays the same Annexe A catalog (plus two purely-consultative reference libraries), no
// meaningful access boundary of its own: same reasoning as \GlpiPlugin\Grcmanager\SecurityIncidentCve
// reusing PluginGrcmanagerSecurityIncident's right.
Session::checkRight(\GlpiPlugin\Grcmanager\Control::$rightname, READ);

// Issue #403 : seuls les référentiels actifs sont construits et affichés. Sans aucun, la page est
// retirée du menu (Referentials::getMenuContent()) et refusée par son adresse.
$enabled = FrameworkConfig::enabled();
if ($enabled === []) {
    throw new AccessDeniedHttpException();
}
$isEnabled = static fn (string $framework): bool => in_array($framework, $enabled, true);

Html::header(
    __('Reference frameworks', 'grcmanager'),
    $_SERVER['PHP_SELF'],
    'grcmanager',
    \GlpiPlugin\Grcmanager\Control::class,
    \GlpiPlugin\Grcmanager\Referentials::class
);
// Encadré « À quoi sert cette page ? ».
// Rubrique du menu regroupant plusieurs écrans : barre d'onglets commune.
\GlpiPlugin\Grcmanager\Menu::showSection(
    \GlpiPlugin\Grcmanager\Control::class,
    \GlpiPlugin\Grcmanager\Referentials::class
);
\GlpiPlugin\Grcmanager\PageHelp::show('referentiels');

$titles = \GlpiPlugin\Grcmanager\Control::getControlTitles();
$themes = \GlpiPlugin\Grcmanager\Control::getThemes();

global $DB;
$idsByCode = [];
foreach ($DB->request(['SELECT' => ['id', 'code'], 'FROM' => \GlpiPlugin\Grcmanager\Control::getTable()]) as $row) {
    $idsByCode[$row['code']] = (int) $row['id'];
}

$annexA = [];
foreach (ControlCatalogDefaults::CONTROLS as $code => $theme) {
    $bareCode = substr($code, 2); // 'A.5.1' -> '5.1'
    $annexA[] = [
        'code'      => $code,
        'id'        => $idsByCode[$code] ?? null,
        'theme'     => $themes[$theme] ?? $theme,
        'title'     => $titles[$code] ?? $code,
        'crosswalk' => ControlCrosswalkDefaults::CROSSWALK[$bareCode]['nist_csf'] ?? [],
    ];
}

$nistCsf = [];
foreach (NistCsfCatalogDefaults::FUNCTIONS as $functionCode => $functionName) {
    $categories = [];
    foreach (NistCsfCatalogDefaults::CATEGORIES as $categoryCode => $category) {
        if ($category['function'] !== $functionCode) {
            continue;
        }
        $subcategories = [];
        foreach (NistCsfCatalogDefaults::SUBCATEGORIES as $subCode => $subcategory) {
            if ($subcategory['category'] === $categoryCode) {
                $subcategories[] = ['code' => $subCode, 'text' => $subcategory['text']];
            }
        }
        $categories[] = [
            'code'          => $categoryCode,
            'name'          => $category['name'],
            'subcategories' => $subcategories,
        ];
    }
    $nistCsf[] = ['code' => $functionCode, 'name' => $functionName, 'categories' => $categories];
}

$cisControls = [];
foreach (CisControlsCatalogDefaults::CONTROLS as $controlNumber => $controlName) {
    $safeguards = [];
    foreach (CisControlsCatalogDefaults::SAFEGUARDS as $safeguardCode => $safeguard) {
        if ($safeguard['control'] === $controlNumber) {
            $safeguards[] = ['code' => $safeguardCode, 'text' => $safeguard['text'], 'ig' => $safeguard['ig']];
        }
    }
    // Issue #143 : correspondance Annexe A indicative, reliée aux fiches de contrôle.
    $controls = [];
    foreach (CisControlsCatalogDefaults::ANNEX_A[$controlNumber] as $annexACode) {
        $controls[] = ['code' => $annexACode, 'id' => $idsByCode[$annexACode] ?? null];
    }
    $cisControls[] = [
        'number'     => $controlNumber,
        'name'       => $controlName,
        'safeguards' => $safeguards,
        'controls'   => $controls,
    ];
}

// Issue #110 : NIS2 dans la langue de l'utilisateur (versions officielles FR/EN de la directive,
// le français pour toute langue fr_*, l'anglais sinon), correspondance Annexe A indicative reliée
// aux fiches de contrôle quand elles existent.
$nis2Lang = str_starts_with((string) ($_SESSION['glpilanguage'] ?? 'fr_FR'), 'fr') ? 'fr' : 'en';
$nis2 = [];
foreach (Nis2CatalogDefaults::ARTICLES as $article => $articleTitle) {
    $requirements = [];
    foreach (Nis2CatalogDefaults::REQUIREMENTS as $code => $requirement) {
        if ($requirement['article'] !== (string) $article) {
            continue;
        }
        $controls = [];
        foreach ($requirement['annex_a'] as $annexACode) {
            $controls[] = ['code' => $annexACode, 'id' => $idsByCode[$annexACode] ?? null];
        }
        $requirements[] = [
            'reference' => Nis2CatalogDefaults::reference($code),
            'text'      => $requirement[$nis2Lang],
            'summary'   => $requirement['summary'],
            'controls'  => $controls,
        ];
    }
    $nis2[] = ['article' => (string) $article, 'title' => $articleTitle[$nis2Lang], 'requirements' => $requirements];
}

// Issue #379 : DORA dans la langue de l'utilisateur (intitulés officiels FR/EN, exigences résumées
// par ce projet), correspondance Annexe A indicative.
$dora = [];
foreach (DoraCatalogDefaults::ARTICLES as $article => $articleTitle) {
    $requirements = [];
    foreach (DoraCatalogDefaults::REQUIREMENTS as $code => $requirement) {
        if ($requirement['article'] !== (string) $article) {
            continue;
        }
        $controls = [];
        foreach ($requirement['annex_a'] as $annexACode) {
            $controls[] = ['code' => $annexACode, 'id' => $idsByCode[$annexACode] ?? null];
        }
        $requirements[] = [
            'reference' => DoraCatalogDefaults::reference((string) $code),
            'text'      => $requirement[$nis2Lang],
            'summary'   => $requirement['summary'],
            'controls'  => $controls,
        ];
    }
    $dora[] = ['article' => (string) $article, 'title' => $articleTitle[$nis2Lang], 'requirements' => $requirements];
}

// Issue #111 : guide d'hygiène ANSSI : intitulés officiels en français, traduction anglaise
// indicative pour les autres langues ; mesures regroupées par thème.
$anssi = [];
foreach (AnssiHygieneCatalogDefaults::THEMES as $themeCode => $themeTitle) {
    $measures = [];
    foreach (AnssiHygieneCatalogDefaults::MEASURES as $number => $measure) {
        if ($measure['theme'] !== $themeCode) {
            continue;
        }
        $controls = [];
        foreach ($measure['annex_a'] as $annexACode) {
            $controls[] = ['code' => $annexACode, 'id' => $idsByCode[$annexACode] ?? null];
        }
        $measures[] = ['number' => $number, 'text' => $measure[$nis2Lang], 'controls' => $controls];
    }
    $anssi[] = ['code' => $themeCode, 'title' => $themeTitle[$nis2Lang], 'measures' => $measures];
}

// Issue #379 : modules du plugin cités par ISO 27701 et ISO 22301, liés seulement si l'utilisateur
// peut les ouvrir.
$moduleClasses = [
    'processing' => ProcessingActivity::class,
    'dpia'       => Dpia::class,
    'requests'   => DataSubjectRequest::class,
    'incidents'  => PluginGrcmanagerSecurityIncident::class,
    'suppliers'  => SupplierRisk::class,
    'risks'      => Risk::class,
    'exercises'  => CrisisExercise::class,
    'audits'     => Audit::class,
    'reviews'    => ManagementReview::class,
];
/**
 * @param list<string> $keys
 * @return list<array{label: string, url: string}>
 */
$moduleLinks = static function (array $keys) use ($moduleClasses): array {
    $links = [];
    foreach ($keys as $key) {
        $class = $moduleClasses[$key];
        if ($class::canView()) {
            $links[] = ['label' => $class::getTypeName(Session::getPluralNumber()), 'url' => $class::getSearchURL()];
        }
    }

    return $links;
};

// Issue #379 : ISO/IEC 27701:2025 (vie privée), objectifs résumés par ce projet (texte de la norme
// protégé), correspondance Annexe A indicative et liens vers les modules RGPD qui y répondent.
$iso27701 = [];
foreach (Iso27701CatalogDefaults::TABLES as $table => $tableTitle) {
    $objectives = [];
    foreach (Iso27701CatalogDefaults::OBJECTIVES as $code => $objective) {
        if ($objective['table'] !== $table) {
            continue;
        }
        $controls = [];
        foreach ($objective['annex_a'] as $annexACode) {
            $controls[] = ['code' => $annexACode, 'id' => $idsByCode[$annexACode] ?? null];
        }
        $objectives[] = [
            'code'     => $code,
            'text'     => $objective[$nis2Lang],
            'controls' => $controls,
            'modules'  => $moduleLinks($objective['modules']),
        ];
    }
    $iso27701[] = ['table' => $table, 'title' => $tableTitle[$nis2Lang], 'objectives' => $objectives];
}

// Issue #379 : ISO 22301:2019 (continuité d'activité), articles résumés par ce projet (texte de la
// norme protégé), correspondance Annexe A indicative et liens vers les modules qui y répondent.
$iso22301 = [];
foreach (Iso22301CatalogDefaults::CLAUSES as $clause => $clauseTitle) {
    $requirements = [];
    foreach (Iso22301CatalogDefaults::REQUIREMENTS as $code => $requirement) {
        if ($requirement['clause'] !== (string) $clause) {
            continue;
        }
        $controls = [];
        foreach ($requirement['annex_a'] as $annexACode) {
            $controls[] = ['code' => $annexACode, 'id' => $idsByCode[$annexACode] ?? null];
        }
        $requirements[] = [
            'code'     => $code,
            'text'     => $requirement[$nis2Lang],
            'controls' => $controls,
            'modules'  => $moduleLinks($requirement['modules']),
        ];
    }
    $iso22301[] = ['clause' => (string) $clause, 'title' => $clauseTitle[$nis2Lang], 'requirements' => $requirements];
}

// ISO/IEC 27090:2026 (sécurité des systèmes d'IA) : numéros de section et objet résumé par ce
// projet (texte de la norme protégé), correspondance Annexe A indicative.
$iso27090 = [];
foreach (Iso27090CatalogDefaults::CHAPTERS as $chapter => $chapterTitle) {
    $topics = [];
    foreach (Iso27090CatalogDefaults::TOPICS as $code => $topic) {
        if ($topic['chapter'] !== (string) $chapter) {
            continue;
        }
        $controls = [];
        foreach ($topic['annex_a'] as $annexACode) {
            $controls[] = ['code' => $annexACode, 'id' => $idsByCode[$annexACode] ?? null];
        }
        $topics[] = ['code' => (string) $code, 'text' => $topic[$nis2Lang], 'controls' => $controls];
    }
    $iso27090[] = ['chapter' => (string) $chapter, 'title' => $chapterTitle[$nis2Lang], 'topics' => $topics];
}

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@grcmanager/referentiels.html.twig', [
    // Issue #403 : un référentiel désactivé arrive vide, sa carte n'est pas affichée.
    'iso27090'        => $isEnabled(FrameworkSelection::ISO27090) ? $iso27090 : [],
    'iso27090_source' => Iso27090CatalogDefaults::SOURCE_URL,
    'annex_a'      => $isEnabled(FrameworkSelection::ISO27001) ? $annexA : [],
    'show_nist_crosswalk' => $isEnabled(FrameworkSelection::NIST_CSF),
    'nist_csf'     => $isEnabled(FrameworkSelection::NIST_CSF) ? $nistCsf : [],
    'cis_controls' => $isEnabled(FrameworkSelection::CIS_CONTROLS) ? $cisControls : [],
    'nis2'         => $isEnabled(FrameworkSelection::NIS2) ? $nis2 : [],
    'nis2_source'  => Nis2CatalogDefaults::SOURCE_URL,
    'dora'         => $isEnabled(FrameworkSelection::DORA) ? $dora : [],
    'dora_source'  => DoraCatalogDefaults::SOURCE_URL,
    'iso27701'        => $isEnabled(FrameworkSelection::ISO27701) ? $iso27701 : [],
    'iso27701_source' => Iso27701CatalogDefaults::SOURCE_URL,
    'iso22301'        => $isEnabled(FrameworkSelection::ISO22301) ? $iso22301 : [],
    'iso22301_source' => Iso22301CatalogDefaults::SOURCE_URL,
    'anssi'        => $isEnabled(FrameworkSelection::ANSSI) ? $anssi : [],
    'anssi_source' => AnssiHygieneCatalogDefaults::SOURCE_URL,
    'anssi_lang'   => $nis2Lang,
]);

Html::footer();

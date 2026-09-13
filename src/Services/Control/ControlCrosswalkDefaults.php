<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Services\Control;

/**
 * Correspondances entre l'Annexe A ISO 27001 (PluginGrcmanagerControl, code sans le préfixe
 * "A.") et le NIST CSF 2.0 — tableau CREUX : seuls les 67 contrôles Annexe A pour lesquels le
 * NIST publie lui-même une correspondance explicite ont une entrée ici (aucune correspondance
 * approximée). Sourcé sur les "Informative References" officielles du NIST CSF 2.0 Reference
 * Tool, pas reconstruit de mémoire.
 *
 * Pas de clé 'cis_controls' pour l'instant : CIS ne publie sa correspondance officielle vers
 * ISO/IEC 27001:2022 que dans un livre blanc nécessitant la création d'un compte
 * (learn.cisecurity.org) — non récupérable automatiquement, et une correspondance approximée
 * de mémoire irait à l'encontre du principe "aucune donnée inventée" de ce plugin. Le
 * catalogue CIS Controls v8 (CisControlsCatalogDefaults) reste consultable indépendamment.
 */
final class ControlCrosswalkDefaults
{
    /**
     * @var array<string, array{nist_csf?: list<string>}>
     *     Code Annexe A (ex. "5.1") => correspondances groupées par référentiel.
     */
    public const CROSSWALK = [
        '5.1' => ['nist_csf' => [
            'GV.RM-01', 'GV.RM-02', 'GV.RM-03', 'GV.RM-04', 'GV.RM-05', 'GV.RM-06',
            'GV.PO-01', 'GV.PO-02', 'GV.OV-01', 'GV.OV-02', 'GV.OV-03', 'GV.SC-01',
            'GV.SC-03', 'PR.AA-05', 'PR.DS-01',
        ]],
        '5.2' => ['nist_csf' => ['GV.SC-02', 'PR.AT-02']],
        '5.3' => ['nist_csf' => ['GV.OC-05', 'PR.AA-05', 'PR.DS-01', 'PR.DS-02', 'PR.DS-10']],
        '5.4' => ['nist_csf' => ['GV.RR-01', 'GV.SC-02']],
        '5.7' => ['nist_csf' => ['ID.RA-02', 'ID.RA-03', 'ID.RA-05', 'ID.RA-06', 'DE.AE-07']],
        '5.8' => ['nist_csf' => ['ID.AM-08']],
        '5.9' => ['nist_csf' => [
            'ID.AM-01', 'ID.AM-02', 'ID.AM-05', 'ID.AM-07', 'ID.AM-08', 'PR.PS-02',
            'PR.PS-03',
        ]],
        '5.10' => ['nist_csf' => ['PR.DS-01', 'PR.DS-02', 'PR.DS-10']],
        '5.12' => ['nist_csf' => ['ID.AM-05', 'ID.AM-08']],
        '5.13' => ['nist_csf' => ['ID.AM-05', 'ID.AM-08', 'PR.DS-01', 'PR.DS-02', 'PR.DS-10']],
        '5.14' => ['nist_csf' => ['ID.AM-03', 'PR.AA-05', 'PR.DS-01', 'PR.DS-02', 'PR.DS-10']],
        '5.15' => ['nist_csf' => ['PR.AA-01', 'PR.AA-03', 'PR.AA-05', 'PR.DS-01', 'PR.DS-02', 'PR.DS-10']],
        '5.16' => ['nist_csf' => ['PR.AA-03', 'PR.AA-04', 'PR.AA-05']],
        '5.17' => ['nist_csf' => ['PR.AA-03', 'PR.AA-05']],
        '5.18' => ['nist_csf' => ['PR.AA-01', 'PR.AA-03', 'PR.AA-05']],
        '5.19' => ['nist_csf' => [
            'GV.RM-05', 'GV.OV-01', 'GV.OV-02', 'GV.OV-03', 'GV.SC-01', 'GV.SC-03',
            'GV.SC-04', 'GV.SC-05', 'GV.SC-06', 'GV.SC-07', 'GV.SC-09', 'GV.SC-10',
            'ID.AM-08', 'ID.RA-09', 'ID.RA-10', 'ID.IM-02',
        ]],
        '5.20' => ['nist_csf' => [
            'GV.OC-03', 'GV.OC-04', 'GV.OV-03', 'GV.SC-01', 'GV.SC-03', 'GV.SC-05',
            'GV.SC-06', 'GV.SC-07', 'GV.SC-09', 'GV.SC-10', 'ID.RA-09', 'ID.RA-10',
        ]],
        '5.21' => ['nist_csf' => ['GV.SC-01', 'GV.SC-03', 'GV.SC-09', 'GV.SC-10']],
        '5.22' => ['nist_csf' => [
            'GV.SC-01', 'GV.SC-04', 'GV.SC-09', 'GV.SC-10', 'ID.AM-04', 'ID.AM-08',
            'ID.RA-02', 'ID.RA-03', 'ID.RA-09', 'ID.RA-10', 'DE.CM-06',
        ]],
        '5.24' => ['nist_csf' => ['ID.IM-04', 'DE.AE-02', 'RS.MA-02']],
        '5.25' => ['nist_csf' => [
            'DE.AE-02', 'DE.AE-04', 'DE.AE-08', 'RS.MA-03', 'RS.MA-05', 'RS.AN-03',
            'RS.AN-08',
        ]],
        '5.26' => ['nist_csf' => [
            'GV.SC-08', 'ID.IM-04', 'DE.AE-06', 'DE.AE-08', 'RS.MA-01', 'RS.MA-02',
            'RS.MA-04', 'RS.CO-02', 'RS.CO-03', 'RS.MI-01', 'RS.MI-02', 'RC.RP-01',
            'RC.RP-02', 'RC.RP-05',
        ]],
        '5.27' => ['nist_csf' => ['ID.IM-03', 'ID.IM-04', 'RS.MA-01', 'RS.MA-02', 'RS.AN-03', 'RC.RP-06']],
        '5.28' => ['nist_csf' => ['RS.MA-01', 'RS.MA-02', 'RS.AN-06', 'RS.AN-07', 'RC.CO-03']],
        '5.29' => ['nist_csf' => ['PR.IR-03']],
        '5.31' => ['nist_csf' => ['GV.OC-03', 'GV.OC-04', 'GV.SC-05', 'GV.SC-06', 'GV.SC-07']],
        '5.35' => ['nist_csf' => ['ID.IM-01', 'ID.IM-02']],
        '6.1' => ['nist_csf' => ['GV.RR-04', 'PR.DS-01', 'PR.DS-02', 'PR.DS-10']],
        '6.2' => ['nist_csf' => ['GV.RR-04', 'PR.DS-01', 'PR.DS-02', 'PR.DS-10']],
        '6.3' => ['nist_csf' => ['GV.RR-04', 'PR.AT-01', 'PR.AT-02']],
        '6.4' => ['nist_csf' => ['GV.RR-04']],
        '6.5' => ['nist_csf' => ['GV.RR-04', 'PR.DS-01', 'PR.DS-02', 'PR.DS-10']],
        '6.6' => ['nist_csf' => ['GV.RR-04']],
        '6.7' => ['nist_csf' => ['GV.RR-04']],
        '6.8' => ['nist_csf' => ['GV.RR-04', 'RS.MA-02']],
        '7.1' => ['nist_csf' => ['PR.AA-06']],
        '7.2' => ['nist_csf' => ['PR.AA-06']],
        '7.3' => ['nist_csf' => ['PR.AA-06']],
        '7.4' => ['nist_csf' => ['PR.AA-06', 'DE.CM-02', 'DE.CM-03']],
        '7.5' => ['nist_csf' => ['PR.IR-02']],
        '7.7' => ['nist_csf' => ['PR.DS-01']],
        '7.10' => ['nist_csf' => ['ID.AM-08', 'PR.DS-01']],
        '7.12' => ['nist_csf' => ['PR.AA-06']],
        '7.13' => ['nist_csf' => ['ID.AM-08']],
        '7.14' => ['nist_csf' => ['ID.AM-08']],
        '8.2' => ['nist_csf' => ['PR.AA-01', 'PR.AA-02', 'PR.AA-05', 'PR.DS-01', 'PR.DS-02', 'PR.DS-10']],
        '8.3' => ['nist_csf' => ['PR.AA-02', 'PR.AA-05', 'PR.DS-01', 'PR.DS-02', 'PR.DS-10']],
        '8.4' => ['nist_csf' => ['PR.DS-01', 'PR.DS-02', 'PR.DS-10']],
        '8.5' => ['nist_csf' => ['PR.AA-01', 'PR.AA-02', 'PR.AA-03', 'PR.AA-05']],
        '8.6' => ['nist_csf' => ['PR.IR-04']],
        '8.7' => ['nist_csf' => ['PR.DS-01']],
        '8.8' => ['nist_csf' => ['ID.RA-01', 'PR.DS-01']],
        '8.9' => ['nist_csf' => ['PR.PS-01']],
        '8.13' => ['nist_csf' => ['PR.DS-11', 'RC.RP-03', 'RC.RP-06']],
        '8.14' => ['nist_csf' => ['PR.IR-03']],
        '8.15' => ['nist_csf' => ['PR.PS-04', 'DE.AE-03']],
        '8.16' => ['nist_csf' => [
            'ID.RA-02', 'ID.RA-03', 'DE.CM-01', 'DE.CM-03', 'DE.CM-06', 'DE.CM-09',
            'DE.AE-03',
        ]],
        '8.17' => ['nist_csf' => ['PR.DS-01', 'PR.DS-02', 'PR.DS-10', 'PR.PS-04']],
        '8.18' => ['nist_csf' => ['PR.AA-05']],
        '8.19' => ['nist_csf' => ['PR.DS-01', 'PR.PS-05']],
        '8.20' => ['nist_csf' => ['ID.AM-03', 'PR.DS-02', 'PR.IR-01']],
        '8.21' => ['nist_csf' => ['ID.AM-03', 'PR.IR-01']],
        '8.22' => ['nist_csf' => ['ID.AM-03', 'PR.DS-01', 'PR.DS-02', 'PR.DS-10', 'PR.IR-01']],
        '8.25' => ['nist_csf' => ['PR.PS-06']],
        '8.26' => ['nist_csf' => ['PR.DS-01', 'PR.DS-02', 'PR.DS-10']],
        '8.28' => ['nist_csf' => ['PR.PS-06']],
        '8.32' => ['nist_csf' => ['ID.RA-07']],
    ];
}

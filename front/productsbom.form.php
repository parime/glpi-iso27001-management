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

use GlpiPlugin\Grcmanager\ControlTest;
use GlpiPlugin\Grcmanager\ProductSbom;
use GlpiPlugin\Grcmanager\Services\Control\EvidenceUploadRules;
use GlpiPlugin\Grcmanager\Services\Sbom\SbomParser;

// Issue #869 : import d'une nomenclature (SBOM) ou d'un document VEX, déclaration VEX saisie et
// suppressions, depuis l'onglet « SBOM et VEX » d'une application (seul écran qui poste ici).
// Droit : mise à jour du plugin, vérifiée avant toute lecture ; l'application doit être visible
// par la personne (droit natif ET entité, can()). Jeton CSRF vérifié par GLPI.
if (!ProductSbom::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
Session::checkRight(\GlpiPlugin\Grcmanager\Install\Installer::RIGHT_NAME, UPDATE);

/** Application visible par la personne, ou page introuvable. */
$checkAppliance = static function (int $appliancesId): void {
    if ($appliancesId <= 0 || !(new Appliance())->can($appliancesId, READ)) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
};

/** Contenu du fichier envoyé, ou null après un message d'erreur. */
$readUpload = static function (string $field): ?string {
    $file = $_FILES[$field] ?? null;
    $error = is_array($file)
        ? EvidenceUploadRules::uploadError(
            is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE,
            is_int($file['size'] ?? null) ? $file['size'] : 0,
            SbomParser::MAX_BYTES
        )
        : EvidenceUploadRules::ERROR_EMPTY;
    if ($error !== null || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        Session::addMessageAfterRedirect(
            htmlescape(ControlTest::uploadErrorMessage($error ?? EvidenceUploadRules::ERROR_FAILED)),
            false,
            ERROR
        );

        return null;
    }

    return (string) file_get_contents($file['tmp_name']);
};

$appliancesId = (int) ($_POST['appliances_id'] ?? 0);
$version = is_string($_POST['product_version'] ?? null) ? $_POST['product_version'] : '';

if (isset($_POST['import_sbom'])) {
    $checkAppliance($appliancesId);
    $content = $readUpload('sbom_file');
    if ($content !== null) {
        $name = is_string($_FILES['sbom_file']['name'] ?? null) ? $_FILES['sbom_file']['name'] : '';
        $result = ProductSbom::importSbom($appliancesId, $version, $content, $name);
        if ($result['error'] !== '') {
            Session::addMessageAfterRedirect(htmlescape(ProductSbom::errorMessage($result['error'])), false, ERROR);
        } else {
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __(
                    'SBOM imported: %1$d component(s), %2$d vulnerability(ies) cited, %3$d VEX statement(s).',
                    'grcmanager'
                ),
                $result['components'],
                $result['vulnerabilities'],
                $result['statements']
            )));
        }
    }
    Html::back();
}

if (isset($_POST['import_vex'])) {
    $checkAppliance($appliancesId);
    $content = $readUpload('vex_file');
    if ($content !== null) {
        $result = ProductSbom::importVex($appliancesId, $version, $content);
        if ($result['error'] !== '') {
            Session::addMessageAfterRedirect(htmlescape(ProductSbom::errorMessage($result['error'])), false, ERROR);
        } else {
            Session::addMessageAfterRedirect(htmlescape(sprintf(
                __('VEX document imported: %d statement(s).', 'grcmanager'),
                $result['statements']
            )));
        }
    }
    Html::back();
}

if (isset($_POST['declare'])) {
    $checkAppliance($appliancesId);
    $error = ProductSbom::declare($appliancesId, $_POST);
    if ($error !== '') {
        Session::addMessageAfterRedirect(htmlescape(ProductSbom::errorMessage($error)), false, ERROR);
    } else {
        Session::addMessageAfterRedirect(htmlescape(__('VEX statement saved.', 'grcmanager')));
    }
    Html::back();
}

if (isset($_POST['delete_sbom'])) {
    $sbomsId = (int) ($_POST['sboms_id'] ?? 0);
    $checkAppliance(ProductSbom::applianceOfSbom($sbomsId));
    ProductSbom::deleteSbom($sbomsId);
    Session::addMessageAfterRedirect(htmlescape(__('SBOM deleted.', 'grcmanager')));
    Html::back();
}

if (isset($_POST['delete_statement'])) {
    $statementsId = (int) ($_POST['statements_id'] ?? 0);
    $checkAppliance(ProductSbom::applianceOfStatement($statementsId));
    ProductSbom::deleteStatement($statementsId);
    Session::addMessageAfterRedirect(htmlescape(__('VEX statement deleted.', 'grcmanager')));
    Html::back();
}

Html::back();

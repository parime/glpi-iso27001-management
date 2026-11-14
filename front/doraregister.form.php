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

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Grcmanager\BusinessProcess;
use GlpiPlugin\Grcmanager\DoraArrangement;
use GlpiPlugin\Grcmanager\DoraFunction;
use GlpiPlugin\Grcmanager\DoraProvider;
use GlpiPlugin\Grcmanager\DoraRegister;

/**
 * Issue #540 : saisie du registre d'informations DORA. Toutes les actions exigent le droit de
 * modifier le plugin, le référentiel DORA activé et la lecture de l'objet GLPI concerné (contrat,
 * fournisseur, processus métier, dans les entités de la session). Le contrat, le fournisseur et le
 * processus eux-mêmes ne sont jamais modifiés.
 */
Session::checkRight(DoraArrangement::RIGHTNAME, UPDATE);
if (!DoraRegister::canEdit()) {
    throw new AccessDeniedHttpException();
}

/**
 * Objet GLPI lisible par la personne, ou refus.
 *
 * @template T of CommonDBTM
 * @param class-string<T> $class
 * @return T
 */
$readable = static function (string $class, int $id): CommonDBTM {
    $item = new $class();
    if ($id <= 0 || !$item->getFromDB($id)) {
        throw new BadRequestHttpException();
    }
    if (!$item->canViewItem() || (isset($item->fields['is_deleted']) && (int) $item->fields['is_deleted'] === 1)) {
        throw new AccessDeniedHttpException();
    }

    return $item;
};
$saved = static fn () => Session::addMessageAfterRedirect(__('The DORA register has been updated.', 'grcmanager'));

if (isset($_POST['save_settings'])) {
    DoraRegister::saveSettings($_POST);
    $saved();
    Html::back();
}

if (isset($_POST['save_provider'])) {
    $supplier = $readable(Supplier::class, (int) ($_POST['suppliers_id'] ?? 0));
    if (DoraProvider::saveFor((int) $supplier->getID(), $_POST)) {
        $saved();
    }
    Html::back();
}

if (isset($_POST['save_function'])) {
    $process = $readable(BusinessProcess::class, (int) ($_POST['plugin_grcmanager_businessprocesses_id'] ?? 0));
    if (DoraFunction::saveFor((int) $process->getID(), $_POST)) {
        $saved();
    }
    Html::back();
}

$contract = $readable(Contract::class, (int) ($_POST['contracts_id'] ?? 0));
if (!$contract instanceof Contract) {
    throw new BadRequestHttpException();
}
$contractsId = (int) $contract->getID();

if (isset($_POST['include'])) {
    if (DoraArrangement::include($contract)) {
        Session::addMessageAfterRedirect(__('The contract has been added to the DORA register.', 'grcmanager'));
    }
    Html::back();
}

$arrangement = DoraArrangement::forContract($contractsId);
if ($arrangement === []) {
    throw new BadRequestHttpException();
}

if (isset($_POST['exclude'])) {
    DoraArrangement::exclude($contractsId);
    Session::addMessageAfterRedirect(__('The contract has been removed from the DORA register.', 'grcmanager'));
} elseif (isset($_POST['save_arrangement'])) {
    $item = new DoraArrangement();
    $input = DoraArrangement::sanitize($_POST, DoraRegister::contractSupplierIds($contractsId));
    if ($item->update(['id' => (int) $arrangement['id']] + $input)) {
        $saved();
    }
} elseif (isset($_POST['add_function'])) {
    $process = $readable(BusinessProcess::class, (int) ($_POST['plugin_grcmanager_businessprocesses_id'] ?? 0));
    if (DoraArrangement::addFunction($contractsId, (int) $process->getID())) {
        $saved();
    }
} elseif (isset($_POST['remove_function'])) {
    DoraArrangement::removeFunction($contractsId, (int) ($_POST['plugin_grcmanager_businessprocesses_id'] ?? 0));
    $saved();
} elseif (isset($_POST['add_subcontractor'])) {
    $supplier = $readable(Supplier::class, (int) ($_POST['suppliers_id'] ?? 0));
    if (DoraArrangement::addSubcontractor($contractsId, (int) $supplier->getID(), (int) ($_POST['recipient'] ?? 0))) {
        $saved();
    } else {
        Session::addMessageAfterRedirect(
            __('This supplier is already in the subcontracting chain of the contract.', 'grcmanager'),
            false,
            ERROR
        );
    }
} elseif (isset($_POST['remove_subcontractor'])) {
    DoraArrangement::removeSubcontractor($contractsId, (int) ($_POST['suppliers_id'] ?? 0));
    $saved();
}
Html::back();

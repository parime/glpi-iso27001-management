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

namespace GlpiPlugin\Grcmanager\Config\Block;

use GlpiPlugin\Grcmanager\AuditLog;
use GlpiPlugin\Grcmanager\Config\ConfigBlock;
use GlpiPlugin\Grcmanager\Config\ConfigTabs;
use GlpiPlugin\Grcmanager\Config\MovedSettings;
use GlpiPlugin\Grcmanager\Config\Tab\FrameworksTab;
use GlpiPlugin\Grcmanager\CustomFramework;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkCsv;
use GlpiPlugin\Grcmanager\Services\Control\CustomFrameworkSync;
use Html;
use Session;

/**
 * Issue #1047 : création et import des référentiels personnalisés (issue #537), venus de
 * front/customframework.php : définir un référentiel est un réglage. Liste des référentiels
 * importés, modèle de fichier, analyse d'un fichier (lignes rejetées et aperçu des changements),
 * puis import confirmé. Rien n'est enregistré avant la confirmation ; l'analyse est gardée dans la
 * session le temps de confirmer. Droits d'origine : lecture du plugin pour voir, création pour un
 * nouveau référentiel, modification pour une nouvelle version.
 *
 * L'auto-évaluation des exigences (front/customframework.form.php) et l'évaluation par référentiel
 * restent dans la rubrique « Réglementations ».
 */
final class CustomFrameworkImportBlock extends ConfigBlock
{
    public const ANCHOR = 'grc-custom-framework-import';

    private const SESSION_KEY = 'grcmanager_customframework_import';

    public static function tab(): string
    {
        return 'frameworks';
    }

    public static function after(): string
    {
        return 'FrameworksCustom';
    }

    public static function template(): string
    {
        return '@grcmanager/config/blocks/_custom_framework_import.html.twig';
    }

    public static function anchors(): array
    {
        return [self::ANCHOR];
    }

    public static function canView(): bool
    {
        return (bool) Session::haveRight(CustomFramework::RIGHTNAME, READ);
    }

    /** Adresse du bloc ; $target : référentiel dont on importe une nouvelle version. */
    public static function url(int $target = 0): string
    {
        return ConfigTabs::url(FrameworksTab::key()) . ($target > 0 ? '&cf_target=' . $target : '')
            . '#' . self::ANCHOR;
    }

    public static function data(): array
    {
        $target = (int) ($_GET['cf_target'] ?? 0);
        $targetItem = new CustomFramework();
        if ($target > 0 && !$targetItem->getFromDB($target)) {
            $target = 0;
        }
        $pending = $_SESSION[self::SESSION_KEY] ?? null;
        $analysis = is_array($pending) && is_array($pending['view'] ?? null) ? $pending['view'] : null;

        $frameworks = [];
        foreach (CustomFramework::getList() as $row) {
            $frameworks[] = [
                'id'       => (int) $row['id'],
                'name'     => (string) $row['name'],
                'version'  => (string) $row['version'],
                'active'   => (int) $row['active'],
                'removed'  => (int) $row['removed'],
                'enabled'  => (bool) $row['enabled'],
                'imported' => $row['date_import'] !== null ? Html::convDateTime((string) $row['date_import']) : '',
                'new_version_url' => self::url((int) $row['id']),
            ];
        }

        return ['custom_import' => [
            'frameworks'   => $frameworks,
            'analysis'     => $analysis,
            'target'       => $target,
            'target_name'  => $target > 0 ? (string) $targetItem->fields['name'] : '',
            'block_url'    => self::url(),
            'form_url'     => CustomFramework::getFormURL(),
            'template_url' => CustomFramework::getSearchURL() . '?template=1',
            'can_create'   => (bool) Session::haveRight(CustomFramework::RIGHTNAME, CREATE),
            'can_update'   => (bool) Session::haveRight(CustomFramework::RIGHTNAME, UPDATE),
            'max_rows'     => CustomFrameworkCsv::MAX_ROWS,
        ]];
    }

    public static function handle(): bool
    {
        if (isset($_POST['analyse_custom_framework'])) {
            self::analyse();
        }
        if (isset($_POST['confirm_custom_framework'])) {
            self::confirm();
        }
        if (isset($_POST['cancel_custom_framework'])) {
            unset($_SESSION[self::SESSION_KEY]);
            Html::redirect(self::url());
        }

        return false;
    }

    /**
     * Ancien envoi de front/customframework.php (accepté pendant une version) : même traitement,
     * puis le bloc de la configuration, avec un message qui indique le nouvel emplacement.
     */
    public static function handleLegacy(): void
    {
        if (!isset($_POST['analyse']) && !isset($_POST['confirm_import'])) {
            return;
        }
        MovedSettings::notice(FrameworksTab::label());
        if (isset($_POST['analyse'])) {
            self::analyse();
        }
        self::confirm();
    }

    /** Analyse d'un fichier : rien n'est enregistré, le résultat attend la confirmation en session. */
    private static function analyse(): void
    {
        $target = (int) ($_POST['target'] ?? 0);
        $targetItem = new CustomFramework();
        if ($target > 0 && !$targetItem->getFromDB($target)) {
            $target = 0;
        }
        Session::checkRight(CustomFramework::RIGHTNAME, $target > 0 ? UPDATE : CREATE);
        unset($_SESSION[self::SESSION_KEY]);
        $file = $_FILES['csv_file'] ?? null;
        $uploaded = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && is_uploaded_file((string) $file['tmp_name']) && (int) $file['size'] <= 2 * 1024 * 1024;
        $name = trim((string) ($_POST['name'] ?? ''));
        if (!$uploaded) {
            $message = __('Choose a CSV file of 2 MB at most.', 'grcmanager');
            Session::addMessageAfterRedirect(htmlescape($message), false, ERROR);
            Html::redirect(self::url($target));
        }
        if ($target === 0 && $name === '') {
            Session::addMessageAfterRedirect(htmlescape(__('Give the framework a name.', 'grcmanager')), false, ERROR);
            Html::redirect(self::url());
        }
        $content = (string) file_get_contents((string) $file['tmp_name']);
        if (!mb_check_encoding($content, 'UTF-8')) {
            // Fichier enregistré par un tableur en Windows-1252 (Excel en français).
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $parsed = CustomFrameworkCsv::parse($content, CustomFramework::annexACodes());
        unset($content);
        $plan = CustomFrameworkSync::plan(
            $target > 0 ? CustomFramework::getExistingForSync($target) : [],
            $parsed['rows']
        );
        $token = bin2hex(random_bytes(16));
        $errors = [];
        foreach ($parsed['errors'] as $error) {
            $errors[] = $error + ['message' => CustomFramework::errorLabel($error['reason'], $error['detail'])];
        }
        $_SESSION[self::SESSION_KEY] = [
            'token'    => $token,
            'target'   => $target,
            'name'     => mb_substr($name, 0, 255),
            'version'  => mb_substr(trim((string) ($_POST['version'] ?? '')), 0, 255),
            'source'   => mb_substr(trim((string) ($_POST['source'] ?? '')), 0, 255),
            'rows'     => $parsed['rows'],
            'rejected' => count($parsed['errors']),
            'view'     => [
                'token'   => $token,
                'name'    => $target > 0 ? (string) $targetItem->fields['name'] : $name,
                'target'  => $target,
                'valid'   => count($parsed['rows']),
                'errors'  => $errors,
                'steps'   => self::steps($plan),
                'summary' => CustomFrameworkSync::summary($plan),
            ],
        ];
        Html::redirect(self::url($target));
    }

    /** Import confirmé : création d'un référentiel ou nouvelle version d'un existant. */
    private static function confirm(): void
    {
        // Droit vérifié avant tout : création d'un référentiel ou nouvelle version d'un existant.
        Session::checkRightsOr(CustomFramework::RIGHTNAME, [CREATE, UPDATE]);
        $token = (string) ($_POST['import_token'] ?? '');
        $pending = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        if (!is_array($pending) || !hash_equals((string) ($pending['token'] ?? ''), $token)) {
            Session::addMessageAfterRedirect(
                htmlescape(__('This analysis has expired: choose the file again.', 'grcmanager')),
                false,
                ERROR
            );
            Html::redirect(self::url());
        }
        $frameworkId = (int) $pending['target'];
        $framework = new CustomFramework();
        $info = [
            'version'     => (string) $pending['version'],
            'source'      => (string) $pending['source'],
            'users_id'    => Session::getLoginUserID(),
            'date_import' => $_SESSION['glpi_currenttime'],
        ];
        if ($frameworkId > 0) {
            Session::checkRight(CustomFramework::RIGHTNAME, UPDATE);
            if (!$framework->getFromDB($frameworkId)) {
                Html::redirect(self::url());
            }
            // Version et source vides : celles déjà enregistrées restent.
            $info = array_filter($info, static fn ($value): bool => $value !== '');
            $framework->update(['id' => $frameworkId] + $info);
        } else {
            Session::checkRight(CustomFramework::RIGHTNAME, CREATE);
            $frameworkId = (int) $framework->add(['name' => (string) $pending['name']] + $info);
            if ($frameworkId <= 0) {
                Html::redirect(self::url());
            }
        }
        // Plan recalculé sur l'état présent de la base (une autre saisie a pu passer entre-temps).
        $plan = CustomFrameworkSync::plan(CustomFramework::getExistingForSync($frameworkId), $pending['rows']);
        CustomFramework::applyPlan($frameworkId, $plan);
        $summary = CustomFrameworkSync::summary($plan);
        AuditLog::record(
            'custom_framework_import',
            CustomFramework::class,
            $frameworkId,
            0,
            'success',
            ['summary' => $summary, 'rejected' => (int) $pending['rejected']]
        );
        Session::addMessageAfterRedirect(htmlescape(sprintf(
            __(
                'Import finished: %1$d requirements added, %2$d updated, %3$d restored, %4$d marked as removed.',
                'grcmanager'
            ),
            $summary[CustomFrameworkSync::CREATE],
            $summary[CustomFrameworkSync::UPDATE],
            $summary[CustomFrameworkSync::RESTORE],
            $summary[CustomFrameworkSync::REMOVE]
        )));
        Html::redirect(CustomFramework::getFormURL() . '?id=' . $frameworkId);
    }

    /**
     * Lignes de l'aperçu : action, badge et champs modifiés.
     *
     * @param list<array<string, mixed>> $plan
     * @return list<array<string, mixed>>
     */
    private static function steps(array $plan): array
    {
        $actions = [
            CustomFrameworkSync::CREATE    => [
                'bg-green-subtle text-green-emphasis',
                'ti ti-plus',
                __('Added', 'grcmanager'),
            ],
            CustomFrameworkSync::UPDATE    => [
                'bg-blue-subtle text-blue-emphasis',
                'ti ti-pencil',
                __('Changed', 'grcmanager'),
            ],
            CustomFrameworkSync::RESTORE   => [
                'bg-teal-subtle text-teal-emphasis',
                'ti ti-arrow-back-up',
                __('Restored', 'grcmanager'),
            ],
            CustomFrameworkSync::UNCHANGED => [
                'bg-secondary-subtle text-secondary-emphasis',
                'ti ti-equal',
                __('Unchanged', 'grcmanager'),
            ],
            CustomFrameworkSync::REMOVE    => [
                'bg-orange-subtle text-orange-emphasis',
                'ti ti-archive',
                __('Removed (kept with its status)', 'grcmanager'),
            ],
        ];
        $fieldLabels = [
            'theme'       => __('Theme', 'grcmanager'),
            'title'       => _x('label', 'Title', 'grcmanager'),
            'description' => __('Description'),
            'annex_a'     => __('Annex A', 'grcmanager'),
            'code'        => __('Code'),
        ];
        $steps = [];
        foreach ($plan as $step) {
            $steps[] = [
                'line'    => $step['line'],
                'code'    => $step['code'],
                'title'   => $step['title'],
                'annex_a' => isset($step['fields']['annex_a'])
                    ? CustomFrameworkSync::decodeAnnexA((string) $step['fields']['annex_a']) : [],
                'class'   => $actions[$step['action']][0],
                'icon'    => $actions[$step['action']][1],
                'label'   => $actions[$step['action']][2],
                'changed' => implode(', ', array_map(
                    static fn (string $field): string => $fieldLabels[$field] ?? $field,
                    $step['changed']
                )),
            ];
        }

        return $steps;
    }
}

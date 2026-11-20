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

namespace GlpiPlugin\Grcmanager;

use Document_Item;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\CompetenceDeclarationBase;
use GlpiPlugin\Grcmanager\Install\CompetenceTables;
use GlpiPlugin\Grcmanager\Services\Competence\CompetenceRules as Rules;
use GlpiPlugin\Grcmanager\Services\People\PersonnelComplianceProvider as Provider;
use Html;
use Log;
use Session;
use User;

/**
 * Issue #774 : compétence acquise par une personne, déclarée avec sa preuve (certificat,
 * attestation, diplôme, entretien annuel...). La référence de la preuve est obligatoire ; la pièce
 * elle-même se joint dans l'onglet Documents.
 *
 * Données de personnes : comme la fiche de conformité du personnel, la lecture exige le droit du
 * plugin et la lecture des utilisateurs, et la personne doit être un compte habilité dans les
 * entités de la session. La déclaration est rangée dans l'entité de la personne (ou l'entité active
 * si celle-ci n'est pas accessible), jamais dans une entité tirée de la requête.
 */
class CompetenceDeclaration extends CompetenceDeclarationBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTable($classname = null)
    {
        return CompetenceTables::DECLARATIONS;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Declared competence', 'Declared competences', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-file-certificate';
    }

    public static function canView(): bool
    {
        return parent::canView() && (bool) Session::haveRight(User::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return parent::canCreate() && (bool) Session::haveRight(User::$rightname, READ);
    }

    public static function canUpdate(): bool
    {
        return parent::canUpdate() && (bool) Session::haveRight(User::$rightname, READ);
    }

    public static function canPurge(): bool
    {
        return parent::canPurge() && (bool) Session::haveRight(User::$rightname, READ);
    }

    /** @return array<string, string> */
    public static function evidenceLabels(): array
    {
        return [
            'certificate'   => __('Certificate', 'grcmanager'),
            'attestation'   => __('Training certificate', 'grcmanager'),
            'diploma'       => __('Diploma', 'grcmanager'),
            'annual_review' => __('Annual review', 'grcmanager'),
            'experience'    => __('Proven experience', 'grcmanager'),
            'other'         => __('Other', 'grcmanager'),
        ];
    }

    /** Compte actif habilité dans les entités de la session. */
    public static function isVisibleUser(int $usersId): bool
    {
        return $usersId > 0 && Provider::users(['users_id' => [$usersId]]) !== [];
    }

    public function canViewItem(): bool
    {
        return parent::canViewItem() && self::isVisibleUser((int) ($this->fields['users_id'] ?? 0));
    }

    public function canUpdateItem(): bool
    {
        return parent::canUpdateItem() && self::isVisibleUser((int) ($this->fields['users_id'] ?? 0));
    }

    public function canPurgeItem(): bool
    {
        return parent::canPurgeItem() && self::isVisibleUser((int) ($this->fields['users_id'] ?? 0));
    }

    public function getName($options = [])
    {
        if ($this->isNewItem()) {
            return parent::getName($options);
        }
        $competenceId = (int) $this->fields[Competence::FK];
        $competence = Competence::labelsFor([$competenceId])[$competenceId] ?? '';

        return sprintf('%s - %s', $competence, getUserName((int) $this->fields['users_id']));
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
            ->addStandardTab(Document_Item::class, $tabs, $options)
            ->addStandardTab(Log::class, $tabs, $options);

        return $tabs;
    }

    public function prepareInputForAdd($input)
    {
        $usersId = (int) ($input['users_id'] ?? 0);
        if (!self::isVisibleUser($usersId)) {
            Session::addMessageAfterRedirect(__('Choose a person of your entities.', 'grcmanager'), false, ERROR);

            return false;
        }
        $user = new User();
        $user->getFromDB($usersId);
        $entityId = (int) $user->fields['entities_id'];
        $input['entities_id'] = Session::haveAccessToEntity($entityId) ? $entityId : Session::getActiveEntity();
        $input['is_recursive'] = 0;
        $input['users_id_recorder'] = (int) Session::getLoginUserID();

        return $this->normalizeInput($input, []);
    }

    public function prepareInputForUpdate($input)
    {
        // La personne et l'entité d'une déclaration ne changent pas.
        unset($input['users_id'], $input['entities_id'], $input['is_recursive'], $input['users_id_recorder']);

        return $this->normalizeInput($input, $this->fields);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     * @return array<string, mixed>|false
     */
    private function normalizeInput(array $input, array $current)
    {
        $merged = array_merge($current, $input);
        $competenceId = (int) ($merged[Competence::FK] ?? 0);
        $competence = new Competence();
        if ($competenceId <= 0 || !$competence->getFromDB($competenceId)) {
            Session::addMessageAfterRedirect(__('Choose a competence.', 'grcmanager'), false, ERROR);

            return false;
        }
        if (array_key_exists('level', $input)) {
            $input['level'] = Rules::normalizeLevel($input['level']);
        }
        $type = Rules::normalizeEvidenceType($merged['evidence_type'] ?? null);
        if ($type === null) {
            Session::addMessageAfterRedirect(__('Choose the kind of evidence.', 'grcmanager'), false, ERROR);

            return false;
        }
        if (array_key_exists('evidence_type', $input)) {
            $input['evidence_type'] = $type;
        }
        if (array_key_exists('evidence_reference', $input)) {
            $input['evidence_reference'] = trim((string) $input['evidence_reference']);
        }
        if (!Rules::hasEvidence((string) ($input['evidence_reference'] ?? $current['evidence_reference'] ?? ''), 0)) {
            Session::addMessageAfterRedirect(
                __(
                    'Give the reference of the evidence (certificate number, date of the annual review...).',
                    'grcmanager'
                ),
                false,
                ERROR
            );

            return false;
        }
        foreach (['date_acquired', 'date_expiry'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = Rules::normalizeDate($input[$field]);
            }
        }
        $acquired = $input['date_acquired'] ?? ($current['date_acquired'] ?? null);
        $expiry = $input['date_expiry'] ?? ($current['date_expiry'] ?? null);
        if ($acquired !== null && $expiry !== null && $expiry < $acquired) {
            Session::addMessageAfterRedirect(
                __('The end of validity cannot be before the date the competence was acquired.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }

        return $input;
    }

    /**
     * Déclarations des personnes citées, pour les compétences citées (toutes si null).
     *
     * @param list<int> $userIds
     * @param list<int>|null $competenceIds
     * @return list<array<string, mixed>>
     */
    public static function forUsers(array $userIds, ?array $competenceIds = null): array
    {
        global $DB;

        if ($userIds === [] || $competenceIds === []) {
            return [];
        }
        $where = ['users_id' => $userIds];
        if ($competenceIds !== null) {
            $where[Competence::FK] = $competenceIds;
        }

        return iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => $where,
            'ORDER' => ['date_acquired DESC', 'id DESC'],
        ]), false);
    }

    public function rawSearchOptions()
    {
        $table = self::getTable();
        $tab = [['id' => 'common', 'name' => self::getTypeName(1)]];
        $tab[] = ['id' => 2, 'table' => $table, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'itemlink'];
        $tab[] = [
            'id' => 3, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id',
            'name' => User::getTypeName(1), 'datatype' => 'dropdown', 'right' => 'all',
        ];
        $tab[] = [
            'id' => 4, 'table' => Competence::getTable(), 'field' => 'name', 'name' => Competence::getTypeName(1),
            'datatype' => 'dropdown', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 5, 'table' => $table, 'field' => 'evidence_reference', 'name' => __('Evidence', 'grcmanager'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id' => 6, 'table' => $table, 'field' => 'date_acquired', 'name' => __('Acquired on', 'grcmanager'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id' => 7, 'table' => $table, 'field' => 'date_expiry', 'name' => __('Valid until', 'grcmanager'),
            'datatype' => 'date',
        ];
        $tab[] = [
            'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => Entity::getTypeName(1),
            'datatype' => 'dropdown', 'massiveaction' => false,
        ];

        return $tab;
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        if ($this->isNewItem()) {
            // Préremplissage depuis un écart de la matrice : personne et compétence, rien d'autre.
            $this->fields['users_id'] = (int) ($_GET['users_id'] ?? 0);
            $this->fields[Competence::FK] = (int) ($_GET['competences_id'] ?? 0);
            $this->fields['level'] = Rules::normalizeLevel($_GET['level'] ?? 1);
        }
        $competences = [];
        foreach (
            (new Competence())->find(EntityPerimeter::criteria(Competence::getTable())) as $row
        ) {
            $competences[(int) $row['id']] = Competence::label($row);
        }
        asort($competences);

        TemplateRenderer::getInstance()->display('@grcmanager/competence_declaration_form.html.twig', [
            'item'        => $this,
            'params'      => $options,
            'competences' => $competences,
            'levels'      => IsmsFunction::levelLabels(),
            'evidences'   => self::evidenceLabels(),
            'user_label'  => User::getTypeName(1),
            'person'      => $this->isNewItem() ? '' : (string) getUserName((int) $this->fields['users_id']),
            'recorder'    => !$this->isNewItem() && (int) $this->fields['users_id_recorder'] > 0
                ? getUserName((int) $this->fields['users_id_recorder']) : '',
            'created'     => $this->isNewItem() ? '' : Html::convDateTime($this->fields['date_creation']),
        ]);

        return true;
    }
}

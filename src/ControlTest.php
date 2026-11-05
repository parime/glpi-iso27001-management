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

namespace GlpiPlugin\Grcmanager;

use CommonGLPI;
use CronTask;
use Document;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compatibility\Base\ControlTestBase;
use GlpiPlugin\Grcmanager\Services\Control\ControlTestRules;
use GlpiPlugin\Grcmanager\Services\Ui\Badge;
use Html;
use Session;
use User;

/**
 * Issue #380 : test d'efficacité d'une mesure de l'Annexe A. L'état de mise en œuvre dit qu'une
 * mesure est en place ; le test prouve qu'elle fonctionne : date, méthode (inspection,
 * échantillonnage, test technique, entretien), résultat, constat, preuve et échéance du test
 * suivant.
 *
 * - Plan de test : fréquence, méthode et responsable, portés par la mesure elle-même
 *   (glpi_plugin_grcmanager_controls), modifiables avec le droit de modifier la mesure.
 * - Droits : ceux de la mesure. Lire un test exige de pouvoir lire la mesure ; l'enregistrer ou le
 *   supprimer exige de pouvoir la modifier (plus le droit de suppression du plugin pour supprimer).
 * - Preuve : un document joint à la mesure (onglet Documents), donc soumis aux droits et aux
 *   entités du document, comme les validités de preuve (Evidence). Jamais un document quelconque
 *   désigné par son identifiant.
 * - Un test inefficace ouvre une non-conformité (si l'utilisateur a le droit d'en créer une) ;
 *   l'état « vérifié » de la SoA suit le dernier test (ControlTestRules::statusAfterTest()).
 *
 * Un test est un enregistrement : il ne se modifie pas, il se supprime et se ressaisit.
 */
class ControlTest extends ControlTestBase
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return _n('Effectiveness test', 'Effectiveness tests', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-test-pipe';
    }

    /**
     * Sous-page « Tests d'efficacité » de la rubrique « Conformité » (Menu::sections()).
     */
    public static function getMenuContent()
    {
        $menu = parent::getMenuContent();
        if (is_array($menu) && $menu !== []) {
            $menu['title'] = self::getTypeName(Session::getPluralNumber());
            unset($menu['links']['add']);
        }

        return $menu;
    }

    /**
     * Un test se saisit depuis l'onglet « Tests d'efficacité » de la mesure (droit vérifié sur
     * la mesure), jamais comme un objet autonome : pas de bouton « Ajouter » sur la liste.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    public static function getMethods(): array
    {
        return [
            'inspection' => __('Documentary inspection', 'grcmanager'),
            'sampling'   => __('Sampling', 'grcmanager'),
            'technical'  => __('Technical test', 'grcmanager'),
            'interview'  => __('Interview', 'grcmanager'),
        ];
    }

    /**
     * @return array<string, array{label: string, class: string, icon: string}>
     */
    public static function getResults(): array
    {
        return [
            ControlTestRules::RESULT_EFFECTIVE   => [
                'label' => __('Effective', 'grcmanager'), 'class' => 'bg-green-lt', 'icon' => 'ti-circle-check',
            ],
            ControlTestRules::RESULT_PARTIAL     => [
                'label' => __('Partially effective', 'grcmanager'), 'class' => 'bg-yellow-lt',
                'icon'  => 'ti-circle-half-2',
            ],
            ControlTestRules::RESULT_INEFFECTIVE => [
                'label' => __('Ineffective', 'grcmanager'), 'class' => 'bg-red-lt', 'icon' => 'ti-circle-x',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getFrequencies(): array
    {
        $frequencies = [];
        foreach (ControlTestRules::FREQUENCIES as $months) {
            $frequencies[$months] = $months === 0
                ? __('No test plan', 'grcmanager')
                : PeriodicReview::frequencyLabel($months);
        }

        return $frequencies;
    }

    /**
     * @return array{0: string, 1: string} classe du badge, libellé
     */
    public static function dueInfo(?string $nextDate): array
    {
        return match (ControlTestRules::dueStatus($nextDate, date('Y-m-d'))) {
            ControlTestRules::DUE_OVERDUE => ['bg-red-lt', _x('deadline passed', 'Overdue', 'grcmanager')],
            ControlTestRules::DUE_SOON    => ['bg-orange-lt', __('Due soon', 'grcmanager')],
            ControlTestRules::DUE_PLANNED => ['bg-green-lt', _x('masculine', 'Planned', 'grcmanager')],
            default                       => ['bg-secondary-lt', __('No test scheduled', 'grcmanager')],
        };
    }

    public static function canEditControl(int $controlsId): bool
    {
        $control = new Control();

        return $controlsId > 0 && $control->can($controlsId, UPDATE);
    }

    public function canViewItem(): bool
    {
        $control = new Control();

        return $control->getFromDB((int) ($this->fields['plugin_grcmanager_controls_id'] ?? 0))
            && $control->canViewItem();
    }

    public function canCreateItem(): bool
    {
        return self::canEditControl((int) ($this->input['plugin_grcmanager_controls_id'] ?? 0));
    }

    public function canUpdateItem(): bool
    {
        return self::canEditControl((int) ($this->fields['plugin_grcmanager_controls_id'] ?? 0));
    }

    public function canPurgeItem(): bool
    {
        return $this->canUpdateItem();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    public function prepareInputForAdd($input)
    {
        $controlsId = (int) ($input['plugin_grcmanager_controls_id'] ?? 0);
        $control = new Control();
        if (!self::canEditControl($controlsId) || !$control->getFromDB($controlsId)) {
            Session::addMessageAfterRedirect(__('Control not found.', 'grcmanager'), false, ERROR);

            return false;
        }

        $result = (string) ($input['result'] ?? '');
        if (!ControlTestRules::isResult($result)) {
            Session::addMessageAfterRedirect(__('Choose the result of the test.', 'grcmanager'), false, ERROR);

            return false;
        }
        $comment = trim((string) ($input['comment'] ?? ''));
        if ($result !== ControlTestRules::RESULT_EFFECTIVE && $comment === '') {
            Session::addMessageAfterRedirect(
                __('Describe what was found when the control is not fully effective.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }

        $testDate = ControlTestRules::normalizeDate($input['test_date'] ?? '') ?? date('Y-m-d');
        if ($testDate > date('Y-m-d')) {
            Session::addMessageAfterRedirect(__('A test cannot be dated in the future.', 'grcmanager'), false, ERROR);

            return false;
        }
        $explicitNext = ControlTestRules::normalizeDate($input['next_test_date'] ?? '');
        if ($explicitNext !== null && $explicitNext <= $testDate) {
            Session::addMessageAfterRedirect(
                __('The next test must be scheduled after the test date.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }

        // Preuve : un document joint à la mesure ET lisible par l'utilisateur (entité du document).
        $documentsId = max(0, (int) ($input['documents_id'] ?? 0));
        if (
            $documentsId > 0
            && (!Evidence::isDocumentLinked(Control::class, $controlsId, $documentsId)
                || !Evidence::canReadDocument($documentsId))
        ) {
            Session::addMessageAfterRedirect(
                __('The evidence must be a document attached to this control.', 'grcmanager'),
                false,
                ERROR
            );

            return false;
        }

        return [
            'plugin_grcmanager_controls_id' => $controlsId,
            'test_date'                     => $testDate,
            'method'                        => ControlTestRules::normalizeMethod(
                $input['method'] ?? ($control->fields['test_method'] ?? '')
            ),
            'result'                        => $result,
            'users_id'                      => max(0, (int) ($input['users_id'] ?? 0))
                ?: (int) Session::getLoginUserID(),
            'comment'                       => $comment,
            'documents_id'                  => $documentsId,
            'next_test_date'                => ControlTestRules::nextTestDate(
                $testDate,
                $explicitNext,
                (int) ($control->fields['test_frequency_months'] ?? 0)
            ),
            // Rempli par post_addItem(), jamais par la saisie.
            'plugin_grcmanager_nonconformities_id' => 0,
        ];
    }

    /**
     * Un test enregistré ne se modifie pas (valeur probante) : seule la suppression est possible.
     *
     * @param array<string, mixed> $input
     * @return false
     */
    public function prepareInputForUpdate($input)
    {
        return false;
    }

    public function post_addItem()
    {
        $control = new Control();
        if (!$control->getFromDB((int) $this->fields['plugin_grcmanager_controls_id'])) {
            return;
        }
        $result = (string) $this->fields['result'];
        $code = (string) $control->fields['code'];

        // L'état « vérifié » de la SoA s'appuie sur le dernier test.
        $status = ControlTestRules::statusAfterTest((string) $control->fields['implementation_status'], $result);
        if ($status !== null) {
            $updated = $control->update([
                'id'                    => $control->getID(),
                'implementation_status' => $status,
                '_grcmanager_from_test' => true,
            ]);
            if ($updated) {
                Session::addMessageAfterRedirect(sprintf(
                    __('Implementation status of the control set to "%s".', 'grcmanager'),
                    Control::getImplementationStatuses()[$status]
                ));
            }
        }

        if (ControlTestRules::opensNonconformity($result)) {
            $this->openNonconformity($control);
        }

        AuditLog::record('create', self::class, (int) $this->getID(), 0, 'success', [
            'control' => $code,
            'result'  => $result,
            'date'    => (string) $this->fields['test_date'],
        ]);
    }

    /**
     * Non-conformité ouverte par un test inefficace, avec le droit de créer une non-conformité :
     * sans lui, le test est enregistré et l'utilisateur est prévenu.
     */
    private function openNonconformity(Control $control): void
    {
        global $DB;

        if (!Nonconformity::canCreate()) {
            Session::addMessageAfterRedirect(
                __('No non-conformity was opened: you are not allowed to create one.', 'grcmanager'),
                false,
                WARNING
            );

            return;
        }
        $code = (string) $control->fields['code'];
        $methods = self::getMethods();
        $nonconformity = new Nonconformity();
        $ncId = (int) $nonconformity->add([
            'title'         => sprintf(
                __('Ineffective control %1$s: %2$s', 'grcmanager'),
                $code,
                Control::getControlTitle($code)
            ),
            'description'   => sprintf(
                __('Effectiveness test of %1$s (%2$s) on %3$s: %4$s', 'grcmanager'),
                $code,
                $methods[$this->fields['method']] ?? (string) $this->fields['method'],
                Html::convDate((string) $this->fields['test_date']),
                (string) $this->fields['comment']
            ),
            'finding_type'  => 'nonconformity',
            'severity'      => 'major',
            'status'        => 'open',
            'users_id'      => (int) ($control->fields['users_id_tester'] ?? 0) ?: (int) $this->fields['users_id'],
        ]);
        if ($ncId <= 0) {
            return;
        }
        $DB->update(self::getTable(), ['plugin_grcmanager_nonconformities_id' => $ncId], ['id' => $this->getID()]);
        $this->fields['plugin_grcmanager_nonconformities_id'] = $ncId;
        Session::addMessageAfterRedirect(sprintf(
            __('Non-conformity opened: %s', 'grcmanager'),
            (string) $nonconformity->fields['title']
        ));
    }

    public function post_purgeItem()
    {
        AuditLog::record('purge', self::class, (int) $this->getID(), 0, 'success', [
            'control' => (int) $this->fields['plugin_grcmanager_controls_id'],
            'result'  => (string) $this->fields['result'],
            'date'    => (string) $this->fields['test_date'],
        ]);
    }

    /**
     * Tests d'une mesure, du plus récent au plus ancien.
     *
     * @return list<array<string, mixed>>
     */
    public static function getForControl(int $controlsId): array
    {
        return array_values((new self())->find(
            ['plugin_grcmanager_controls_id' => $controlsId],
            ['test_date DESC', 'id DESC']
        ));
    }

    /**
     * Échéance du prochain test d'une mesure : celle du dernier test, sinon la date du dernier test
     * plus la fréquence du plan. Sans aucun test, pas d'échéance (premier test à planifier).
     *
     * @param array<string, mixed>      $control ligne de la mesure
     * @param array<string, mixed>|null $latest  dernier test de la mesure
     */
    public static function nextDueDate(array $control, ?array $latest): ?string
    {
        if ($latest === null) {
            return null;
        }

        return ControlTestRules::nextTestDate(
            (string) $latest['test_date'],
            $latest['next_test_date'] ?? null,
            (int) ($control['test_frequency_months'] ?? 0)
        );
    }

    /**
     * Échéances des tests de toutes les mesures applicables qui en ont une, de la plus proche à la
     * plus lointaine (calendrier de conformité, liste des tests en retard).
     *
     * @return list<array{control: array<string, mixed>, latest: array<string, mixed>, next: string}>
     */
    public static function getSchedule(): array
    {
        global $DB;

        $latest = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'ORDER' => ['test_date DESC', 'id DESC']]) as $row) {
            $latest[(int) $row['plugin_grcmanager_controls_id']] ??= $row;
        }
        if ($latest === []) {
            return [];
        }
        $schedule = [];
        $controls = $DB->request([
            'FROM'  => Control::getTable(),
            'WHERE' => ['id' => array_keys($latest), 'applicability' => ['yes', 'partial']],
        ]);
        foreach ($controls as $row) {
            $next = self::nextDueDate($row, $latest[(int) $row['id']]);
            if ($next !== null) {
                $schedule[] = ['control' => $row, 'latest' => $latest[(int) $row['id']], 'next' => $next];
            }
        }
        usort($schedule, static fn (array $a, array $b): int => strcmp($a['next'], $b['next']));

        return $schedule;
    }

    /**
     * @param string $name
     * @return array<string, string>
     */
    public static function cronInfo($name)
    {
        return $name === ControlTestReminder::CRON_NAME
            ? ['description' => __(
                'Reminds overdue or upcoming effectiveness tests and withdraws the "Verified" status when the last test has expired',
                'grcmanager'
            )]
            : [];
    }

    /**
     * Issue #380 : rappels des tests et retrait de l'état « Vérifié » (ControlTestReminder::run()).
     */
    public static function cronControltestreminder(CronTask $task): int
    {
        $result = ControlTestReminder::run(date('Y-m-d'));
        $task->addVolume($result['notified'] + $result['withdrawn']);
        $task->log(sprintf(
            '%d rappel(s), %d état(s) « Vérifié » retiré(s).',
            $result['notified'],
            $result['withdrawn']
        ));

        return ($result['notified'] + $result['withdrawn']) > 0 ? 1 : 0;
    }

    /**
     * Lien vers l'onglet des tests de la fiche d'une mesure.
     */
    public static function getControlTabURL(int $controlsId): string
    {
        return Control::getFormURLWithID($controlsId) . '&forcetab=' . urlencode(self::class . '$1');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Control || $item->isNewItem() || !self::canView()) {
            return '';
        }
        $count = countElementsInTable(self::getTable(), ['plugin_grcmanager_controls_id' => $item->getID()]);

        return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $count, null, self::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem().
        if (!$item instanceof Control || $item->isNewItem() || !$item->can($item->getID(), READ)) {
            return false;
        }
        self::showForControl($item);

        return true;
    }

    private static function showForControl(Control $control): void
    {
        global $CFG_GLPI;

        $controlsId = (int) $control->getID();
        $canEdit = $control->can($controlsId, UPDATE);
        $tests = self::getForControl($controlsId);
        $canViewNc = Nonconformity::canView();
        $rows = [];
        foreach ($tests as $test) {
            $documentsId = (int) $test['documents_id'];
            $ncId = (int) $test['plugin_grcmanager_nonconformities_id'];
            // Nom et lien de la preuve seulement si le document est lisible (entité du document).
            $readable = $documentsId > 0 && Evidence::canReadDocument($documentsId);
            $rows[] = $test + [
                'user_name'     => (int) $test['users_id'] > 0 ? getUserName((int) $test['users_id']) : '',
                'document_name' => $readable ? Dropdown::getDropdownName('glpi_documents', $documentsId) : '',
                'document_url'  => $readable ? Document::getFormURLWithID($documentsId) : '',
                'nc_url'        => $ncId > 0 && $canViewNc ? Nonconformity::getFormURLWithID($ncId) : '',
                'can_purge'     => $canEdit && self::canPurge(),
            ];
        }
        $latest = $tests[0] ?? null;
        $next = self::nextDueDate($control->fields, $latest);
        [$dueClass, $dueLabel] = self::dueInfo($next);
        $testerId = (int) ($control->fields['users_id_tester'] ?? 0);

        TemplateRenderer::getInstance()->display('@grcmanager/control_test_tab.html.twig', [
            'control'         => $control,
            'can_edit'        => $canEdit,
            'tests'           => $rows,
            'latest'          => $latest,
            'next_date'       => $next,
            'due_class'       => $dueClass,
            'due_label'       => $dueLabel,
            'frequency'       => (int) ($control->fields['test_frequency_months'] ?? 0),
            'plan_method'     => (string) ($control->fields['test_method'] ?? ''),
            'tester_name'     => $testerId > 0 ? getUserName($testerId) : '',
            'frequencies'     => self::getFrequencies(),
            'methods'         => self::getMethods(),
            'results'         => self::getResults(),
            'documents'       => $canEdit ? Evidence::getDocumentsFor($control) : [],
            'documents_url'   => Control::getFormURLWithID($controlsId) . '&forcetab=Document_Item$1',
            'today'           => date('Y-m-d'),
            'form_url'        => $CFG_GLPI['root_doc'] . '/plugins/grcmanager/front/controltest.form.php',
            // Listes déroulantes natives de GLPI (utilisateurs actifs que la session peut voir).
            'tester_dropdown' => $canEdit ? User::dropdown([
                'name'    => 'users_id_tester',
                'value'   => $testerId,
                'right'   => 'all',
                'display' => false,
                'width'   => '100%',
            ]) : '',
            'user_dropdown'   => $canEdit ? User::dropdown([
                'name'    => 'users_id',
                'value'   => (int) Session::getLoginUserID(),
                'right'   => 'all',
                'display' => false,
                'width'   => '100%',
            ]) : '',
        ]);
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(1),
        ];

        $tab[] = [
            'id'            => 1,
            'table'         => Control::getTable(),
            'field'         => 'code',
            'name'          => __('Control', 'grcmanager'),
            'datatype'      => 'itemlink',
            'itemlink_type' => Control::class,
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => 2,
            'table'    => $this->getTable(),
            'field'    => 'test_date',
            'name'     => __('Test date', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 3,
            'table'    => $this->getTable(),
            'field'    => 'method',
            'name'     => __('Method', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 4,
            'table'    => $this->getTable(),
            'field'    => 'result',
            'name'     => __('Result', 'grcmanager'),
            'datatype' => 'specific',
        ];

        $tab[] = [
            'id'       => 5,
            'table'    => User::getTable(),
            'field'    => 'name',
            'name'     => __('Tester', 'grcmanager'),
            'datatype' => 'dropdown',
            'right'    => 'all',
        ];

        $tab[] = [
            'id'       => 6,
            'table'    => $this->getTable(),
            'field'    => 'next_test_date',
            'name'     => __('Next test', 'grcmanager'),
            'datatype' => 'date',
        ];

        $tab[] = [
            'id'       => 7,
            'table'    => $this->getTable(),
            'field'    => 'comment',
            'name'     => __('Finding', 'grcmanager'),
            'datatype' => 'text',
        ];

        $tab[] = [
            'id'            => 8,
            'table'         => Nonconformity::getTable(),
            'field'         => 'title',
            'name'          => Nonconformity::getTypeName(1),
            'datatype'      => 'itemlink',
            'itemlink_type' => Nonconformity::class,
            'massiveaction' => false,
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        switch ($field) {
            case 'method':
                return htmlescape(self::getMethods()[$values[$field] ?? ''] ?? (string) ($values[$field] ?? ''));

            case 'result':
                $result = self::getResults()[$values[$field] ?? ''] ?? null;

                return $result === null ? '' : Badge::html($result['class'], $result['icon'], $result['label']);
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        $options['value']   = $values[$field] ?? '';

        switch ($field) {
            case 'method':
                return Dropdown::showFromArray($name, self::getMethods(), $options);

            case 'result':
                return Dropdown::showFromArray(
                    $name,
                    array_map(static fn (array $r): string => $r['label'], self::getResults()),
                    $options
                );
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}

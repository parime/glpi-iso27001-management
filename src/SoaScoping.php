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

use CommonGLPI;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Install\SoaScopingTables;
use GlpiPlugin\Grcmanager\Services\Control\SoaScopingRules as Rules;
use Html;
use Session;

/**
 * Issue #873 : cadrage guidé de la Déclaration d'Applicabilité (ISO/IEC 27001:2022, 6.1.3 c et d).
 *
 * Un questionnaire court sur le contexte de l'organisation donne, pour chaque mesure de la SoA de
 * l'entité active, une applicabilité et une justification proposées. Rien n'est appliqué sans
 * relecture : la personne accepte ou refuse chaque proposition, une par une ou par lot après un
 * aperçu. Une acceptation passe par la fiche de la mesure (Control::update, avec ses contrôles et
 * son historique) et n'écrase jamais une justification déjà écrite. Chaque décision est journalisée
 * (qui, quand, valeurs avant et proposées) et s'exporte en CSV pour le dossier de l'auditeur.
 *
 * Droits : lecture de la SoA pour consulter ; modification de la SoA pour répondre et décider.
 */
class SoaScoping extends CommonGLPI
{
    public const RIGHTNAME = 'plugin_grcmanager';

    public static function getTypeName($nb = 0)
    {
        return __('SoA scoping', 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-message-question';
    }

    public static function canView(): bool
    {
        return Control::canView();
    }

    public static function canDecide(): bool
    {
        return Control::canView() && (bool) Session::haveRight(Control::RIGHTNAME, UPDATE);
    }

    public static function getSearchURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/soascoping.php';
    }

    /** Sous-page de la rubrique « Conformité » (Menu::sections()). */
    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getTypeName(),
            'page'  => self::getSearchURL(false),
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * Questions : libellé, aide, et le fait retenu dans la justification selon la réponse.
     *
     * @return array<string, array{label: string, help: string, yes: string, no: string}>
     */
    public static function questions(): array
    {
        $q = [
            'remote_work' => [
                __('Do staff work remotely (from home or while travelling)?', 'grcmanager'),
                __('Telework, nomadic work, access to the information system from outside the premises.', 'grcmanager'),
                __('staff work remotely', 'grcmanager'),
                __('no staff member works remotely', 'grcmanager'),
            ],
            'software_development' => [
                __('Does the organisation develop or customise software in-house?', 'grcmanager'),
                __('Applications, scripts, websites, integrations or significant configuration code.', 'grcmanager'),
                __('the organisation develops or customises software in-house', 'grcmanager'),
                __('the organisation does not develop or customise software in-house', 'grcmanager'),
            ],
            'outsourced_development' => [
                __('Is software development outsourced to a provider?', 'grcmanager'),
                __('A developer, integrator or agency builds or changes software for you.', 'grcmanager'),
                __('software development is outsourced', 'grcmanager'),
                __('no software development is outsourced', 'grcmanager'),
            ],
            'suppliers' => [
                __('Do suppliers or subcontractors access your information or provide ICT services?', 'grcmanager'),
                __('Outsourcing, maintenance, hosting, managed services, telecoms.', 'grcmanager'),
                __('suppliers access information or provide ICT services', 'grcmanager'),
                __('no supplier accesses information or provides ICT services', 'grcmanager'),
            ],
            'cloud_services' => [
                __('Does the organisation use cloud services (SaaS, IaaS, PaaS)?', 'grcmanager'),
                __('Online office suite, hosted applications, cloud storage or servers.', 'grcmanager'),
                __('the organisation uses cloud services', 'grcmanager'),
                __('the organisation uses no cloud service', 'grcmanager'),
            ],
            'own_premises' => [
                __('Does the organisation occupy premises whose security it manages?', 'grcmanager'),
                __('Offices, sites or shops: perimeter, entry, protection against physical threats.', 'grcmanager'),
                __('the organisation manages the security of its own premises', 'grcmanager'),
                __('the organisation manages no premises (security handled by the landlord or host)', 'grcmanager'),
            ],
            'onsite_servers' => [
                __('Are servers or network equipment hosted in your own premises?', 'grcmanager'),
                __('Server room, network cabinet, power supply and cabling under your responsibility.', 'grcmanager'),
                __('servers or network equipment are hosted on the premises', 'grcmanager'),
                __('no server or network equipment is hosted on the premises', 'grcmanager'),
            ],
            'personal_data' => [
                __('Does the organisation process personal data?', 'grcmanager'),
                __('Staff, customers, prospects, patients, users: almost always yes.', 'grcmanager'),
                __('the organisation processes personal data', 'grcmanager'),
                __('the organisation processes no personal data', 'grcmanager'),
            ],
        ];
        $questions = [];
        foreach (array_keys(Rules::QUESTIONS) as $key) {
            [$label, $help, $yes, $no] = $q[$key];
            $questions[$key] = ['label' => $label, 'help' => $help, 'yes' => $yes, 'no' => $no];
        }

        return $questions;
    }

    /**
     * @return array<string, array{label: string, class: string, icon: string}>
     */
    public static function statusStyles(): array
    {
        return [
            Rules::STATUS_WAITING  => [
                'label' => __('Waiting for answers', 'grcmanager'),
                'class' => 'bg-secondary-subtle text-secondary-emphasis',
                'icon'  => 'ti-hourglass',
            ],
            Rules::STATUS_DIFFERS  => [
                'label' => __('To review', 'grcmanager'),
                'class' => 'bg-warning-subtle text-warning-emphasis',
                'icon'  => 'ti-alert-triangle',
            ],
            Rules::STATUS_MATCHES  => [
                'label' => __('Matches the SoA', 'grcmanager'),
                'class' => 'bg-info-subtle text-info-emphasis',
                'icon'  => 'ti-equal',
            ],
            Rules::STATUS_ACCEPTED => [
                'label' => __('Accepted', 'grcmanager'),
                'class' => 'bg-success-subtle text-success-emphasis',
                'icon'  => 'ti-circle-check',
            ],
            Rules::STATUS_REJECTED => [
                'label' => __('Rejected', 'grcmanager'),
                'class' => 'bg-danger-subtle text-danger-emphasis',
                'icon'  => 'ti-circle-x',
            ],
        ];
    }

    /** Entité dont la SoA est cadrée : celle que suit l'entité active. */
    public static function entity(): int
    {
        return EntityPerimeter::soaEntity();
    }

    /**
     * @return array<string, string> question => 'yes' | 'no'
     */
    public static function answers(int $entityId): array
    {
        global $DB;

        $answers = [];
        foreach ($DB->request(['FROM' => SoaScopingTables::ANSWERS, 'WHERE' => ['entities_id' => $entityId]]) as $row) {
            $answer = Rules::normalizeAnswer($row['answer']);
            if ($answer !== '' && isset(Rules::QUESTIONS[$row['question']])) {
                $answers[(string) $row['question']] = $answer;
            }
        }

        return $answers;
    }

    /**
     * Enregistre les réponses envoyées (une question sans réponse efface la réponse enregistrée).
     *
     * @param array<string, mixed> $input question => 'yes' | 'no' | ''
     */
    public static function saveAnswers(int $entityId, array $input): void
    {
        global $DB;

        foreach (array_keys(Rules::QUESTIONS) as $question) {
            $answer = Rules::normalizeAnswer($input[$question] ?? '');
            $where = ['entities_id' => $entityId, 'question' => $question];
            if ($answer === '') {
                $DB->delete(SoaScopingTables::ANSWERS, $where);
                continue;
            }
            $DB->updateOrInsert(SoaScopingTables::ANSWERS, [
                'answer'   => $answer,
                'users_id' => (int) Session::getLoginUserID(),
                'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ], $where);
        }
    }

    /**
     * Justification proposée, dans la langue de la personne qui accepte.
     *
     * @param array{applicability: string, basis: string} $proposal
     */
    public static function proposedJustification(array $proposal): string
    {
        if ($proposal['basis'] === Rules::BASIS_BASELINE) {
            return __(
                'Applicable: baseline control for any organisation within the ISMS scope; no scoping answer excludes it.',
                'grcmanager'
            );
        }
        $question = self::questions()[$proposal['basis']] ?? null;
        if ($question === null) {
            return '';
        }
        if ($proposal['applicability'] === 'yes') {
            return sprintf(__('Applicable: %s (SoA scoping questionnaire).', 'grcmanager'), $question['yes']);
        }

        return sprintf(
            __('Not applicable: %s (SoA scoping questionnaire). To be reviewed if this changes.', 'grcmanager'),
            $question['no']
        );
    }

    /**
     * Dernière décision de chaque mesure de la SoA.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function lastDecisions(int $entityId): array
    {
        global $DB;

        $last = [];
        foreach (
            $DB->request([
                'FROM'  => SoaScopingTables::DECISIONS,
                'WHERE' => ['entities_id' => $entityId],
                'ORDER' => ['id DESC'],
            ]) as $row
        ) {
            $last[(int) $row['plugin_grcmanager_controls_id']] ??= $row;
        }

        return $last;
    }

    /**
     * Une ligne par mesure de la SoA : état actuel, proposition, état et dernière décision.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(int $entityId): array
    {
        global $DB;

        $answers = self::answers($entityId);
        $decisions = self::lastDecisions($entityId);
        $questions = self::questions();
        $applicabilities = Control::getApplicabilities();
        $rows = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'code', 'applicability', 'justification', 'exclusion_category'],
                'FROM'   => Control::getTable(),
                'WHERE'  => ['entities_id' => $entityId],
            ]) as $control
        ) {
            $code = (string) $control['code'];
            $proposal = Rules::proposal($code, $answers);
            $decision = $decisions[(int) $control['id']] ?? null;
            $current = (string) $control['applicability'];
            $justification = (string) ($control['justification'] ?? '');
            $proposedJustification = $proposal !== null ? self::proposedJustification($proposal) : '';
            $rows[] = [
                'id'                     => (int) $control['id'],
                'code'                   => $code,
                'title'                  => Control::getControlTitle($code),
                'url'                    => Control::getFormURLWithID((int) $control['id']),
                'applicability'          => $current,
                'applicability_label'    => $applicabilities[$current] ?? $current,
                'justification'          => $justification,
                'proposal'               => $proposal,
                'proposal_label'         => $proposal !== null ? ($applicabilities[$proposal['applicability']] ?? '') : '',
                'basis_label'            => $proposal === null ? ''
                    : ($questions[$proposal['basis']]['label'] ?? __('Baseline control', 'grcmanager')),
                'proposed_justification' => $proposedJustification,
                'changes'                => $proposal !== null
                    ? Rules::changes($proposal['applicability'], $proposedJustification, $control) : [],
                'status'                 => Rules::status(
                    $proposal,
                    $current,
                    $justification,
                    $decision === null ? null : [
                        'decision'               => (string) $decision['decision'],
                        'proposed_applicability' => (string) $decision['proposed_applicability'],
                    ]
                ),
                'decision'               => $decision === null ? null : [
                    'decision' => (string) $decision['decision'],
                    'user'     => getUserName((int) $decision['users_id']),
                    'date'     => (string) Html::convDateTime((string) $decision['date_creation']),
                ],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strnatcmp($a['code'], $b['code']));

        return $rows;
    }

    /**
     * Applique une décision aux mesures choisies (mesures de cette SoA ayant une proposition).
     * Acceptée : la mesure est mise à jour par sa fiche ; refusée : rien ne change dans la SoA.
     * Les deux sont journalisées. Renvoie le nombre de mesures traitées.
     *
     * @param list<int> $controlIds
     */
    public static function decide(int $entityId, array $controlIds, string $decision): int
    {
        global $DB;

        if (!in_array($decision, [Rules::DECISION_ACCEPTED, Rules::DECISION_REJECTED], true)) {
            return 0;
        }
        $wanted = array_flip(array_map('intval', $controlIds));
        $done = 0;
        foreach (self::rows($entityId) as $row) {
            if (!isset($wanted[$row['id']]) || $row['proposal'] === null) {
                continue;
            }
            if ($decision === Rules::DECISION_ACCEPTED) {
                $control = new Control();
                if (!$control->update(['id' => $row['id']] + $row['changes'])) {
                    continue;
                }
            }
            $DB->insert(SoaScopingTables::DECISIONS, [
                'entities_id'                   => $entityId,
                'plugin_grcmanager_controls_id' => $row['id'],
                'code'                          => $row['code'],
                'basis'                         => $row['proposal']['basis'],
                'decision'                      => $decision,
                'proposed_applicability'        => $row['proposal']['applicability'],
                'proposed_justification'        => $row['proposed_justification'],
                'previous_applicability'        => $row['applicability'],
                'previous_justification'        => $row['justification'],
                'users_id'                      => (int) Session::getLoginUserID(),
                'date_creation'                 => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);
            $done++;
        }

        return $done;
    }

    /**
     * Journal des décisions de la SoA, le plus récent d'abord.
     *
     * @return list<array<string, string>>
     */
    public static function journal(int $entityId, int $limit = 0): array
    {
        global $DB;

        $applicabilities = Control::getApplicabilities();
        $statuses = self::statusStyles();
        $criteria = [
            'FROM'  => SoaScopingTables::DECISIONS,
            'WHERE' => ['entities_id' => $entityId],
            'ORDER' => ['id DESC'],
        ];
        if ($limit > 0) {
            $criteria['LIMIT'] = $limit;
        }
        $journal = [];
        foreach ($DB->request($criteria) as $row) {
            $previous = (string) $row['previous_applicability'];
            $proposed = (string) $row['proposed_applicability'];
            $journal[] = [
                'date'          => (string) $row['date_creation'],
                'user'          => getUserName((int) $row['users_id']),
                'code'          => (string) $row['code'],
                'decision'      => $statuses[$row['decision']]['label'] ?? (string) $row['decision'],
                'decision_key'  => (string) $row['decision'],
                'previous'      => $applicabilities[$previous] ?? $previous,
                'proposed'      => $applicabilities[$proposed] ?? $proposed,
                'justification' => (string) ($row['proposed_justification'] ?? ''),
            ];
        }

        return $journal;
    }

    /**
     * Export CSV : les réponses au questionnaire, puis une ligne par mesure, puis le journal.
     *
     * @return list<list<string>>
     */
    public static function csvRows(int $entityId): array
    {
        $statuses = self::statusStyles();
        $answerLabels = [Rules::ANSWER_YES => __('Yes'), Rules::ANSWER_NO => __('No')];
        $answers = self::answers($entityId);
        $lines = [[__('Question', 'grcmanager'), __('Answer', 'grcmanager')]];
        foreach (self::questions() as $key => $question) {
            $lines[] = [$question['label'], $answerLabels[$answers[$key] ?? ''] ?? __('Not answered', 'grcmanager')];
        }
        $lines[] = [];
        $lines[] = [
            __('Control', 'grcmanager'), __('Title'), __('Applicability', 'grcmanager'),
            __('Justification', 'grcmanager'), __('Proposed applicability', 'grcmanager'),
            __('Basis of the proposal', 'grcmanager'), __('Proposed justification', 'grcmanager'),
            __('Status'), __('Decided by', 'grcmanager'), __('Decision date', 'grcmanager'),
        ];
        foreach (self::rows($entityId) as $row) {
            $lines[] = [
                $row['code'], $row['title'], $row['applicability_label'], $row['justification'],
                $row['proposal_label'], $row['basis_label'], $row['proposed_justification'],
                $statuses[$row['status']]['label'] ?? $row['status'],
                $row['decision']['user'] ?? '', $row['decision']['date'] ?? '',
            ];
        }
        $lines[] = [];
        $lines[] = [
            __('Date'), __('User'), __('Control', 'grcmanager'), __('Decision', 'grcmanager'),
            __('Previous applicability', 'grcmanager'), __('Proposed applicability', 'grcmanager'),
            __('Proposed justification', 'grcmanager'),
        ];
        foreach (self::journal($entityId) as $entry) {
            $lines[] = [
                $entry['date'], $entry['user'], $entry['code'], $entry['decision'],
                $entry['previous'], $entry['proposed'], $entry['justification'],
            ];
        }

        // Neutralise les formules des tableurs (une cellule commençant par = + - @).
        return array_map(
            static fn (array $line): array => array_map(
                static fn (string $cell): string => preg_match('/^[=+\-@]/', $cell) === 1 ? "'" . $cell : $cell,
                $line
            ),
            $lines
        );
    }

    /**
     * Page : questionnaire, propositions (avec l'aperçu d'un lot si demandé) et journal.
     *
     * @param list<int> $previewIds
     */
    public static function showPage(int $entityId, array $previewIds = []): void
    {
        $rows = self::rows($entityId);
        $preview = [];
        if ($previewIds !== []) {
            $wanted = array_flip($previewIds);
            $preview = array_values(array_filter(
                $rows,
                static fn (array $row): bool => isset($wanted[$row['id']]) && $row['proposal'] !== null
            ));
        }
        TemplateRenderer::getInstance()->display('@grcmanager/soa_scoping.html.twig', [
            'action'     => self::getSearchURL(),
            'can_decide' => self::canDecide(),
            'questions'  => self::questions(),
            'answers'    => self::answers($entityId),
            'rows'       => $rows,
            'counts'     => Rules::countByStatus(array_column($rows, 'status')),
            'statuses'   => self::statusStyles(),
            'preview'    => $preview,
            'journal'    => self::journal($entityId, 20),
            'entity'     => Dropdown::getDropdownName('glpi_entities', $entityId),
            'soa_url'    => Control::getSearchURL(),
        ]);
    }
}

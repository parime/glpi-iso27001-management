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

use CommonDBTM;
use CommonGLPI;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Grcmanager\Compat\Base\CrisisCommunicationBase;
use GlpiPlugin\Grcmanager\Install\CrisisCommunicationTables;
use GlpiPlugin\Grcmanager\Services\Crisis\CrisisCommunicationRules as Rules;
use Html;
use ITILFollowup;
use PluginGrcmanagerSecurityIncident;
use PluginGrcmanagerSecurityIncident_Item;
use Session;

/**
 * Issue #616 : communication de crise faite depuis un incident de sécurité ou une violation de
 * données. L'onglet « Communication de crise » prépare le message à partir d'un modèle (variables
 * remplies depuis l'incident, liste de diffusion du modèle), la personne le copie ou l'exporte et
 * l'envoie elle-même : **le plugin n'envoie aucun courriel**. Elle trace ensuite « communication
 * faite » : date, auteur, destinataires, objet et texte envoyés. Sur un incident, la trace est
 * aussi écrite dans le journal de crise (suivi privé de l'incident, sans notification).
 *
 * Droits : ceux de l'incident ou de la violation, jamais d'autres. Lire l'objet suffit pour voir
 * l'onglet et les communications faites ; tracer une communication exige de pouvoir le modifier.
 * La trace est conservée telle quelle (preuve) : elle ne se modifie pas.
 *
 * Issue #771, lot 3 : un message envoyé à un contact officiel peut être tracé en même temps comme
 * prise de contact (onglet « Contacts officiels »), si la personne laisse la case cochée.
 */
class CrisisCommunication extends CrisisCommunicationBase
{
    public const RIGHTNAME = PluginGrcmanagerSecurityIncident::RIGHTNAME;

    public const ITEM_TYPES = [PluginGrcmanagerSecurityIncident::class, DataBreach::class];

    /** Mesure et clause dont la communication de crise est une preuve. */
    public const CONTROL_CODE = 'A.5.26';
    public const CLAUSE_CODE = '7.4';

    public static function getTable($classname = null)
    {
        return CrisisCommunicationTables::COMMUNICATIONS;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Crisis communication', 'Crisis communications', $nb, 'grcmanager');
    }

    public static function getIcon()
    {
        return 'ti ti-speakerphone';
    }

    public static function getFormURL($full = true)
    {
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/grcmanager/front/crisiscommunication.form.php';
    }

    /** Lecture : droit de lire les incidents ou les violations (jamais un droit propre). */
    public static function canView(): bool
    {
        return PluginGrcmanagerSecurityIncident::canView() || DataBreach::canView();
    }

    public static function canCreate(): bool
    {
        return PluginGrcmanagerSecurityIncident::canUpdate() || DataBreach::canUpdate();
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    public function canViewItem(): bool
    {
        $item = self::parentOf((string) ($this->fields['itemtype'] ?? ''), (int) ($this->fields['items_id'] ?? 0));

        return $item !== null && $item->canViewItem();
    }

    /**
     * Incident ou violation lu en base, null si le type n'est pas l'un des deux ou si l'objet
     * n'existe pas.
     */
    public static function parentOf(string $itemtype, int $id): ?CommonDBTM
    {
        if (!in_array($itemtype, self::ITEM_TYPES, true) || $id <= 0) {
            return null;
        }
        $item = new $itemtype();

        return $item->getFromDB($id) ? $item : null;
    }

    private static function isBreach(CommonDBTM $item): bool
    {
        return $item instanceof DataBreach;
    }

    /**
     * Incident d'où viennent la référence et les systèmes touchés : l'incident lui-même, ou celui
     * de la violation s'il existe et que la personne peut le lire.
     */
    private static function incidentOf(CommonDBTM $item): ?PluginGrcmanagerSecurityIncident
    {
        if ($item instanceof PluginGrcmanagerSecurityIncident) {
            return $item;
        }
        $incidentId = (int) ($item->fields[DataBreach::INCIDENT_FK] ?? 0);
        $incident = new PluginGrcmanagerSecurityIncident();

        return $incidentId > 0 && $incident->getFromDB($incidentId) && $incident->canViewItem() ? $incident : null;
    }

    /**
     * Éléments liés à l'incident que la personne peut lire (dix au plus).
     */
    private static function affectedSystems(PluginGrcmanagerSecurityIncident $incident): string
    {
        $names = [];
        foreach (
            (new PluginGrcmanagerSecurityIncident_Item())->find(
                ['plugin_grcmanager_securityincidents_id' => $incident->getID()],
                ['id ASC']
            ) as $row
        ) {
            $linked = getItemForItemtype((string) $row['itemtype']);
            if ($linked === false || !$linked->getFromDB((int) $row['items_id']) || !$linked->canViewItem()) {
                continue;
            }
            $names[] = (string) $linked->getName();
            if (count($names) === 10) {
                break;
            }
        }

        return implode(', ', $names);
    }

    /**
     * Valeurs des variables pour un incident ou une violation (texte brut, jamais du HTML).
     *
     * @return array<string, string>
     */
    public static function valuesFor(CommonDBTM $item): array
    {
        $incident = self::incidentOf($item);
        $date = static fn ($value): string => empty($value) ? '' : (string) Html::convDateTime((string) $value);
        $values = [
            'title'   => (string) ($item->fields['name'] ?? ''),
            'entity'  => (string) Dropdown::getDropdownName('glpi_entities', (int) ($item->fields['entities_id'] ?? 0)),
            'author'  => (string) getUserName((int) Session::getLoginUserID()),
            'today'   => $date(date('Y-m-d H:i:s')),
            'next_update' => '',
        ];
        if (self::isBreach($item)) {
            $values += [
                'incident_type'  => __('Personal data breach', 'grcmanager'),
                'severity'       => DataBreach::getRiskLevels()[(string) ($item->fields['risk_level'] ?? '')] ?? '',
                'detection_date' => $date($item->fields['date_awareness'] ?? null),
            ];
        } else {
            $categories = PluginGrcmanagerSecurityIncident::getCategories();
            $severities = PluginGrcmanagerSecurityIncident::getSeverities();
            $values += [
                'incident_type'  => $categories[(string) ($item->fields['category'] ?? '')] ?? '',
                'severity'       => $severities[(string) ($item->fields['severity'] ?? '')] ?? '',
                'detection_date' => $date($item->fields['date'] ?? null),
            ];
        }
        $values['reference'] = $incident !== null ? (string) ($incident->fields['reference'] ?? '') : '';
        $values['affected_systems'] = $incident !== null ? self::affectedSystems($incident) : '';

        return $values;
    }

    private static function missingMarker(): string
    {
        return __('[to be completed]', 'grcmanager');
    }

    /**
     * Messages prêts à copier, un par modèle actif : ceux de la situation de l'objet d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public static function prepared(CommonDBTM $item): array
    {
        $situation = Rules::situationFor((string) ($item->fields['category'] ?? ''), self::isBreach($item));
        $templates = [];
        $rows = [];
        foreach (CrisisMessageTemplate::activeTemplates() as $row) {
            $rows[(int) $row['id']] = $row;
            $templates[] = [
                'id'        => (int) $row['id'],
                'name'      => (string) $row['name'],
                'situation' => (string) $row['situation'],
                'stage'     => (string) $row['stage'],
            ];
        }
        $values = self::valuesFor($item);
        $missing = self::missingMarker();
        $situations = CrisisMessageTemplate::getSituations();
        $stages = CrisisMessageTemplate::getStages();
        $audiences = CrisisMessageTemplate::getAudiences();
        $entity = (int) $item->fields['entities_id'];
        $messages = [];
        foreach (Rules::sortTemplates($templates, $situation) as $template) {
            $row = $rows[$template['id']];
            $list = CrisisMessageRecipient::listFor($template['id'], $entity);
            $messages[] = [
                'id'          => $template['id'],
                'name'        => $template['name'],
                'recommended' => $template['recommended'],
                'situation'   => $situations[$template['situation']] ?? '',
                'stage'       => $stages[$template['stage']] ?? '',
                'audience'    => $audiences[(string) $row['audience']] ?? '',
                'subject'     => Rules::subject(Rules::fill((string) $row['subject'], $values, $missing)),
                'body'        => Rules::fill((string) $row['body'], $values, $missing),
                'recipients'  => $list['recipients'],
                'hidden'      => $list['hidden'],
            ];
        }

        return $messages;
    }

    /**
     * Communications faites pour cet objet, les plus récentes d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public static function historyFor(CommonDBTM $item): array
    {
        $entries = [];
        foreach (
            (new self())->find(
                ['itemtype' => $item::class, 'items_id' => $item->getID()],
                ['date_communication DESC', 'id DESC']
            ) as $row
        ) {
            $entries[] = [
                'id'         => (int) $row['id'],
                'date'       => Html::convDateTime((string) $row['date_communication']),
                'author'     => getUserName((int) $row['users_id']),
                'template'   => (string) $row['template_name'],
                'subject'    => (string) $row['subject'],
                'body'       => (string) $row['body'],
                'recipients' => array_values(array_filter(explode("\n", (string) $row['recipients']))),
            ];
        }

        return $entries;
    }

    /**
     * Trace une communication faite. La personne doit pouvoir modifier l'incident ou la violation ;
     * les destinataires cochés ne sont retenus que s'ils étaient proposés (liste du modèle, filtrée
     * par ses droits et l'entité de l'objet). Renvoie l'identifiant de la trace, ou un message
     * d'erreur.
     *
     * @param array<string, mixed> $input
     */
    public static function record(CommonDBTM $item, array $input): int|string
    {
        if ($item->isNewItem() || !$item->can($item->getID(), UPDATE)) {
            return __('You are not allowed to record a communication on this item.', 'grcmanager');
        }
        $templateId = (int) ($input[CrisisMessageRecipient::TEMPLATE_FK] ?? 0);
        $template = new CrisisMessageTemplate();
        if ($templateId <= 0 || !$template->getFromDB($templateId) || empty($template->fields['is_active'])) {
            return __('Choose an active message template.', 'grcmanager');
        }
        $subject = Rules::subject((string) ($input['subject'] ?? ''));
        $body = Rules::body((string) ($input['body'] ?? ''));
        if ($subject === '' || $body === '') {
            return __('The subject and the message are required.', 'grcmanager');
        }
        $list = CrisisMessageRecipient::listFor($templateId, (int) $item->fields['entities_id']);
        $byKey = [];
        foreach ($list['recipients'] as $recipient) {
            $byKey[$recipient['key']] = $recipient;
        }
        $labels = [];
        $contactIds = [];
        foreach (Rules::selectedRecipients($input['recipients'] ?? [], array_keys($byKey)) as $key) {
            $recipient = $byKey[$key];
            $labels[] = $recipient['type_label'] . ' : ' . $recipient['name'];
            if ($recipient['type'] === Rules::RECIPIENT_CONTACT) {
                $contactIds[] = (int) $recipient['items_id'];
            }
        }
        $labels = array_merge($labels, Rules::otherRecipients((string) ($input['other_recipients'] ?? '')));
        if ($labels === []) {
            return __('Tick or type at least one recipient.', 'grcmanager');
        }
        $now = date('Y-m-d H:i:s');
        $date = Rules::communicationDate((string) ($input['date_communication'] ?? ''), $now);
        if ($date === null) {
            return __('The date of the communication is invalid or in the future.', 'grcmanager');
        }
        $id = (int) (new self())->add([
            'entities_id'        => (int) $item->fields['entities_id'],
            'itemtype'           => $item::class,
            'items_id'           => $item->getID(),
            CrisisMessageRecipient::TEMPLATE_FK => $templateId,
            'template_name'      => (string) $template->fields['name'],
            'subject'            => $subject,
            'body'               => $body,
            'recipients'         => implode("\n", $labels),
            'users_id'           => (int) Session::getLoginUserID(),
            'date_communication' => $date,
        ]);
        if ($id <= 0) {
            return __('The communication could not be recorded.', 'grcmanager');
        }
        if ($item instanceof PluginGrcmanagerSecurityIncident) {
            self::writeJournal($item, $subject, $labels, $date);
        }
        // Issue #771, lot 3 : le message envoyé à un contact officiel est aussi sa prise de contact
        // (case cochée par la personne, rien d'automatique sans elle).
        if (!empty($input['record_official_contacts']) && $contactIds !== []) {
            OfficialContactNotification::recordFromCommunication($item, $id, $contactIds, $date, $subject);
        }

        return $id;
    }

    /**
     * Ligne du journal de crise (suivi privé de l'incident, sans notification : rien ne part).
     *
     * @param list<string> $labels
     */
    private static function writeJournal(
        PluginGrcmanagerSecurityIncident $incident,
        string $subject,
        array $labels,
        string $date
    ): void {
        $paragraphs = [
            sprintf(
                __('Crisis communication made on %1$s: "%2$s".', 'grcmanager'),
                Html::convDateTime($date),
                $subject
            ),
            sprintf(__('Recipients: %s', 'grcmanager'), implode(', ', $labels)),
        ];
        (new ITILFollowup())->add([
            'itemtype'      => PluginGrcmanagerSecurityIncident::class,
            'items_id'      => $incident->getID(),
            'is_private'    => 1,
            // Paragraphes échappés par le gabarit (aucun HTML écrit en PHP).
            'content'       => TemplateRenderer::getInstance()->render(
                '@grcmanager/crisis_communication_journal.html.twig',
                ['paragraphs' => $paragraphs]
            ),
            '_disablenotif' => true,
        ]);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            !in_array($item::class, self::ITEM_TYPES, true)
            || !$item instanceof CommonDBTM
            || $item->isNewItem()
            || !$item->canViewItem()
        ) {
            return '';
        }

        return self::createTabEntry(
            self::getTypeName(1),
            countElementsInTable(self::getTable(), ['itemtype' => $item::class, 'items_id' => $item->getID()]),
            null,
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        // ajax/common.tabs.php sert le contenu sans passer par getTabNameForItem() : mêmes contrôles.
        if (
            !in_array($item::class, self::ITEM_TYPES, true)
            || !$item instanceof CommonDBTM
            || $item->isNewItem()
            || !$item->can($item->getID(), READ)
        ) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@grcmanager/crisis_communication_tab.html.twig', [
            'itemtype'      => $item::class,
            'items_id'      => $item->getID(),
            'messages'      => self::prepared($item),
            'history'       => self::historyFor($item),
            'can_record'    => $item->canUpdateItem(),
            'form_url'      => self::getFormURL(),
            'library_url'   => CrisisMessageTemplate::canView() ? CrisisMessageTemplate::getSearchURL() : '',
            'now'           => date('Y-m-d\TH:i'),
            'drill'         => !empty($item->fields['is_exercise']),
        ]);

        return true;
    }

    // ------------------------------------------------------------------
    // Preuve de la mesure A.5.26 et de la clause 7.4
    // ------------------------------------------------------------------

    /**
     * Préparation de la communication de crise : modèles actifs, modèles sans destinataire,
     * situations sans modèle et communications tracées depuis 12 mois (incidents et violations que
     * la personne peut tous lire, dans ses entités). Null sans droit sur les modèles.
     *
     * @return array<string, mixed>|null
     */
    public static function readiness(): ?array
    {
        if (!CrisisMessageTemplate::canView()) {
            return null;
        }
        $templates = [];
        foreach (CrisisMessageTemplate::activeTemplates() as $row) {
            $templates[] = [
                'situation'  => (string) $row['situation'],
                'recipients' => countElementsInTable(
                    CrisisMessageRecipient::getTable(),
                    [CrisisMessageRecipient::TEMPLATE_FK => (int) $row['id']]
                ),
            ];
        }
        $summary = Rules::readiness($templates);
        $situations = CrisisMessageTemplate::getSituations();
        $types = [];
        // Incidents : seulement avec la lecture de tous les incidents (pas « mes incidents »).
        $incidentRight = PluginGrcmanagerSecurityIncident::RIGHTNAME;
        if (Session::haveRight($incidentRight, PluginGrcmanagerSecurityIncident::READALL)) {
            $types[] = PluginGrcmanagerSecurityIncident::class;
        }
        if (DataBreach::canView()) {
            $types[] = DataBreach::class;
        }
        $communications = null;
        if ($types !== []) {
            $communications = countElementsInTable(self::getTable(), [
                'itemtype'           => $types,
                'date_communication' => ['>=', date('Y-m-d H:i:s', strtotime('-12 months'))],
            ] + getEntitiesRestrictCriteria(self::getTable(), '', '', false));
        }

        return [
            'templates'          => $summary['templates'],
            'without_recipients' => $summary['without_recipients'],
            'missing_situations' => array_map(
                static fn (string $key): string => $situations[$key] ?? $key,
                $summary['missing_situations']
            ),
            'communications'     => $communications,
            'url'                => CrisisMessageTemplate::getSearchURL(),
        ];
    }

    /**
     * Preuve affichée sur la fiche de la mesure A.5.26 (null pour les autres mesures).
     *
     * @return array<string, mixed>|null
     */
    public static function evidence(string $code): ?array
    {
        return $code === self::CONTROL_CODE ? self::readiness() : null;
    }

    /**
     * Preuve affichée sur l'exigence 7.4 du SMSI (null pour les autres exigences).
     *
     * @return array<string, mixed>|null
     */
    public static function clauseReadiness(string $code): ?array
    {
        return $code === self::CLAUSE_CODE ? self::readiness() : null;
    }
}

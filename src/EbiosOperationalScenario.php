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

use GlpiPlugin\Grcmanager\Services\Risk\EbiosRules;
use Session;

/**
 * Issue #376, atelier 4 : scénario opérationnel, le mode opératoire qui réalise un scénario stratégique
 * sur un bien support, avec sa vraisemblance (V1 à V4). Atelier 5 : décision de traitement et passage au
 * registre des risques, à la demande. Le risque créé vit ensuite sa vie : il n'est jamais modifié d'ici.
 */
class EbiosOperationalScenario extends EbiosElement
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_grcmanager_ebiosoperationalscenarios';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Operational scenario', 'Operational scenarios', $nb, 'grcmanager');
    }

    protected static function allowedFields(): array
    {
        return [
            'name', 'attack_sequence', 'likelihood', 'treatment', 'justification',
            EbiosStrategicScenario::getForeignKeyField(),
            EbiosSupport::getForeignKeyField(),
        ];
    }

    protected function normalize(array $input)
    {
        if (!$this->hasStudyLink($input, EbiosStrategicScenario::class)) {
            return false;
        }
        if (isset($input['likelihood'])) {
            $input['likelihood'] = EbiosRules::level($input['likelihood']);
        }
        if (isset($input['treatment']) && !array_key_exists((string) $input['treatment'], Risk::getTreatments())) {
            unset($input['treatment']);
        }
        // Le bien support est facultatif.
        $supportKey = EbiosSupport::getForeignKeyField();
        if ((int) ($input[$supportKey] ?? 0) <= 0) {
            unset($input[$supportKey]);
            if ($this->isNewItem()) {
                $input[$supportKey] = 0;
            }
        } elseif (!$this->hasStudyLink($input, EbiosSupport::class)) {
            return false;
        }

        return $input;
    }

    /**
     * Crée le risque de ce scénario dans le registre, une seule fois. Le registre calcule son niveau et
     * applique ses propres contrôles, comme l'appétence au risque (refus possible : renvoie 0).
     */
    public function sendToRegister(): int
    {
        global $DB;

        if (!Risk::canCreate()) {
            return 0;
        }
        $linked = (int) $this->fields[Risk::getForeignKeyField()];
        if ($linked > 0 && (new Risk())->getFromDB($linked)) {
            return $linked;
        }
        $strategic = new EbiosStrategicScenario();
        if (!$strategic->getFromDB((int) $this->fields[EbiosStrategicScenario::getForeignKeyField()])) {
            return 0;
        }
        $event = new EbiosFearedEvent();
        if (!$event->getFromDB((int) $strategic->fields[EbiosFearedEvent::getForeignKeyField()])) {
            return 0;
        }
        $study = new EbiosStudy();
        $study->getFromDB((int) $this->fields[self::STUDY_FK]);
        $stakeholderId = (int) $strategic->fields[EbiosStakeholder::getForeignKeyField()];

        $riskId = (new Risk())->add([
            'title'         => (string) $this->fields['name'],
            'description'   => $this->registerDescription($study, $strategic, $event),
            'category'      => $stakeholderId > 0 ? 'third_party' : 'technical',
            'probability'   => EbiosRules::likelihoodToProbability((int) $this->fields['likelihood']),
            'impact'        => EbiosRules::severityToImpact((int) $event->fields['severity']),
            'treatment'     => (string) $this->fields['treatment'],
            'justification' => (string) $this->fields['justification'],
            'users_id'      => (int) ($study->fields['users_id'] ?? 0),
        ]);
        if ($riskId === false || (int) $riskId <= 0) {
            return 0;
        }
        $DB->update(self::getTable(), [Risk::getForeignKeyField() => (int) $riskId], ['id' => $this->getID()]);
        $this->fields[Risk::getForeignKeyField()] = (int) $riskId;
        Session::addMessageAfterRedirect(__('The risk has been added to the register.', 'grcmanager'));

        return (int) $riskId;
    }

    /**
     * L'origine du risque, pour le comprendre depuis le registre.
     */
    private function registerDescription(
        EbiosStudy $study,
        EbiosStrategicScenario $strategic,
        EbiosFearedEvent $event
    ): string {
        $source = new EbiosRiskSource();
        $source->getFromDB((int) $strategic->fields[EbiosRiskSource::getForeignKeyField()]);
        $stakeholder = new EbiosStakeholder();
        $path = $stakeholder->getFromDB((int) $strategic->fields[EbiosStakeholder::getForeignKeyField()])
            ? sprintf(__('Through: %s', 'grcmanager'), (string) $stakeholder->fields['name'])
            : __('Direct attack', 'grcmanager');

        $lines = [
            EbiosStudy::getTypeName(1)                => (string) ($study->fields['name'] ?? ''),
            EbiosStrategicScenario::getTypeName(1)    => (string) $strategic->fields['name'],
            EbiosRiskSource::getTypeName(1)           => (string) ($source->fields['name'] ?? ''),
            __('Target objective', 'grcmanager')      => (string) ($source->fields['objective'] ?? ''),
            __('Attack path', 'grcmanager')           => $path,
            EbiosFearedEvent::getTypeName(1)          => (string) $event->fields['name'],
            __('Attack sequence', 'grcmanager')       => (string) $this->fields['attack_sequence'],
        ];
        $text = [];
        foreach ($lines as $label => $value) {
            if ($value !== '') {
                $text[] = sprintf(__('%1$s: %2$s'), $label, $value);
            }
        }

        return implode("\n", $text);
    }
}

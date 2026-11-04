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

namespace GlpiPlugin\Grcmanager\Tests\Integration;

use GlpiPlugin\Grcmanager\EbiosElement;
use GlpiPlugin\Grcmanager\EbiosFearedEvent;
use GlpiPlugin\Grcmanager\EbiosOperationalScenario;
use GlpiPlugin\Grcmanager\EbiosRiskSource;
use GlpiPlugin\Grcmanager\EbiosStakeholder;
use GlpiPlugin\Grcmanager\EbiosStrategicScenario;
use GlpiPlugin\Grcmanager\EbiosStudy;
use GlpiPlugin\Grcmanager\EbiosSupport;
use GlpiPlugin\Grcmanager\EbiosValue;
use GlpiPlugin\Grcmanager\Risk;

/**
 * Issue #376 : étude EBIOS Risk Manager, ateliers 1 à 5 (données fictives).
 */
final class EbiosStudyTest extends GrcmanagerIntegrationTestCase
{
    public function testWorkshopsAreFilledAndCleanedWithTheStudy(): void
    {
        $study = new EbiosStudy();
        $studyId = (int) $study->add(['name' => 'PHPUnit étude EBIOS', 'scope' => 'Portail client']);
        $this->assertGreaterThan(0, $studyId);
        $fk = [EbiosElement::STUDY_FK => $studyId];

        $valueId = (int) (new EbiosValue())->add($fk + ['name' => 'Prise de commande']);
        $link = $fk + ['plugin_grcmanager_ebiosvalues_id' => $valueId];
        $event = new EbiosFearedEvent();
        $eventId = (int) $event->add($link + ['name' => 'Commandes indisponibles', 'severity' => 9]);
        $event->getFromDB($eventId);
        $this->assertSame(4, (int) $event->fields['severity'], 'Gravité ramenée à G4.');
        $this->assertGreaterThan(0, (int) (new EbiosSupport())->add($link + ['name' => 'Équipe commerciale']));

        $source = new EbiosRiskSource();
        $sourceId = (int) $source->add($fk + [
            'name' => 'Crime organisé', 'objective' => 'Rançon', 'motivation' => 4, 'resources' => 3, 'activity' => 3,
        ]);
        $this->assertTrue($source->update(['id' => $sourceId, 'is_retained' => 0, 'justification' => 'Peu probable']));

        // Nom obligatoire, et l'étude d'un élément ne change pas.
        $this->assertFalse((new EbiosValue())->add($fk + ['name' => '  ']));
        $source->update(['id' => $sourceId, EbiosElement::STUDY_FK => $studyId + 1]);
        $source->getFromDB($sourceId);
        $this->assertSame($studyId, (int) $source->fields[EbiosElement::STUDY_FK]);
        $this->assertSame(0, (int) $source->fields['is_retained']);

        $this->assertSame([1 => 3, 2 => 1, 3 => 0, 4 => 0, 5 => 0], EbiosStudy::countElements($studyId));

        // Retirer la valeur métier retire ce qui en dépend.
        (new EbiosValue())->delete(['id' => $valueId], true);
        $this->assertSame([1 => 0, 2 => 1, 3 => 0, 4 => 0, 5 => 0], EbiosStudy::countElements($studyId));

        // Supprimer l'étude supprime tout.
        $study->delete(['id' => $studyId], true);
        $this->assertSame([1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0], EbiosStudy::countElements($studyId));
    }

    public function testScenariosFollowWhatTheyAreBuiltOn(): void
    {
        $study = new EbiosStudy();
        $studyId = (int) $study->add(['name' => 'PHPUnit étude scénarios']);
        $fk = [EbiosElement::STUDY_FK => $studyId];
        $sourceKey = EbiosRiskSource::getForeignKeyField();
        $eventKey = EbiosFearedEvent::getForeignKeyField();
        $stakeholderKey = EbiosStakeholder::getForeignKeyField();
        $strategicKey = EbiosStrategicScenario::getForeignKeyField();

        $valueId = (int) (new EbiosValue())->add($fk + ['name' => 'Prise de commande']);
        $onValue = $fk + ['plugin_grcmanager_ebiosvalues_id' => $valueId];
        $eventId = (int) (new EbiosFearedEvent())->add($onValue + ['name' => 'Arrêt', 'severity' => 4]);
        $supportId = (int) (new EbiosSupport())->add($onValue + ['name' => 'Serveur']);
        $sourceId = (int) (new EbiosRiskSource())->add($fk + ['name' => 'Crime organisé']);
        $stakeholder = new EbiosStakeholder();
        $stakeholderId = (int) $stakeholder->add($fk + [
            'name' => 'Hébergeur fictif', 'dependence' => 9, 'category' => 'inconnue',
        ]);
        $stakeholder->getFromDB($stakeholderId);
        $this->assertSame(4, (int) $stakeholder->fields['dependence'], 'Échelle ramenée à 4.');
        $this->assertSame('other', $stakeholder->fields['category']);

        $links = $fk + [$sourceKey => $sourceId, $eventKey => $eventId];
        $strategicId = (int) (new EbiosStrategicScenario())->add($links + [
            $stakeholderKey => $stakeholderId, 'name' => 'Par l\'hébergeur',
        ]);
        $directId = (int) (new EbiosStrategicScenario())->add($links + ['name' => 'Directe']);
        $this->assertGreaterThan(0, $strategicId);
        $this->assertGreaterThan(0, $directId);
        $operational = new EbiosOperationalScenario();
        $operationalId = (int) $operational->add($fk + [
            $strategicKey => $strategicId, EbiosSupport::getForeignKeyField() => $supportId,
            'name' => 'Hameçonnage', 'likelihood' => 7,
        ]);
        $operational->getFromDB($operationalId);
        $this->assertSame(4, (int) $operational->fields['likelihood'], 'Vraisemblance ramenée à V4.');
        $this->assertTrue($operational->update(['id' => $operationalId, 'likelihood' => 1]));
        $operational->getFromDB($operationalId);
        $this->assertSame($strategicId, (int) $operational->fields[$strategicKey], 'Le lien reste à la modification.');
        $this->assertSame([1 => 3, 2 => 1, 3 => 3, 4 => 1, 5 => 0], EbiosStudy::countElements($studyId));

        // Un lien vers une autre étude, ou manquant, est refusé.
        $otherStudyId = (int) (new EbiosStudy())->add(['name' => 'PHPUnit autre étude']);
        $other = [EbiosElement::STUDY_FK => $otherStudyId];
        $crossed = $other + ['name' => 'Croisé'];
        $crossedLinks = $crossed + [$sourceKey => $sourceId, $eventKey => $eventId];
        $this->assertFalse((new EbiosStrategicScenario())->add($crossedLinks));
        $this->assertFalse((new EbiosStrategicScenario())->add($fk + [$sourceKey => $sourceId, 'name' => 'Incomplet']));
        $this->assertFalse((new EbiosOperationalScenario())->add($crossed + [$strategicKey => $strategicId]));
        $this->assertFalse((new EbiosFearedEvent())->add($crossed + ['plugin_grcmanager_ebiosvalues_id' => $valueId]));
        $this->assertFalse((new EbiosSupport())->add($crossed + ['plugin_grcmanager_ebiosvalues_id' => $valueId]));

        // Retirer un bien support garde le scénario, sans lien.
        (new EbiosSupport())->delete(['id' => $supportId], true);
        $operational->getFromDB($operationalId);
        $this->assertSame(0, (int) $operational->fields[EbiosSupport::getForeignKeyField()]);

        // Retirer la partie prenante retire son scénario et le scénario opérationnel, pas l'attaque directe.
        (new EbiosStakeholder())->delete(['id' => $stakeholderId], true);
        $this->assertSame([1 => 2, 2 => 1, 3 => 1, 4 => 0, 5 => 0], EbiosStudy::countElements($studyId));

        // Retirer la valeur métier retire l'événement, donc le scénario restant, en cascade.
        (new EbiosValue())->delete(['id' => $valueId], true);
        $this->assertSame([1 => 0, 2 => 1, 3 => 0, 4 => 0, 5 => 0], EbiosStudy::countElements($studyId));

        $study->delete(['id' => $studyId], true);
        (new EbiosStudy())->delete(['id' => $otherStudyId], true);
    }

    public function testOperationalScenarioFeedsTheRiskRegisterOnce(): void
    {
        $study = new EbiosStudy();
        $studyId = (int) $study->add(['name' => 'PHPUnit étude registre']);
        $fk = [EbiosElement::STUDY_FK => $studyId];
        $valueId = (int) (new EbiosValue())->add($fk + ['name' => 'Facturation fictive']);
        $eventId = (int) (new EbiosFearedEvent())->add($fk + [
            'plugin_grcmanager_ebiosvalues_id' => $valueId, 'name' => 'Arrêt fictif', 'severity' => 4,
        ]);
        $sourceId = (int) (new EbiosRiskSource())->add($fk + ['name' => 'Source fictive', 'objective' => 'Rançon']);
        $stakeholderId = (int) (new EbiosStakeholder())->add($fk + ['name' => 'Prestataire fictif']);
        $strategicId = (int) (new EbiosStrategicScenario())->add($fk + [
            EbiosRiskSource::getForeignKeyField() => $sourceId, EbiosFearedEvent::getForeignKeyField() => $eventId,
            EbiosStakeholder::getForeignKeyField() => $stakeholderId, 'name' => 'Chemin fictif',
        ]);
        $scenario = new EbiosOperationalScenario();
        $scenarioId = (int) $scenario->add($fk + [
            EbiosStrategicScenario::getForeignKeyField() => $strategicId,
            'name' => 'Scénario vers le registre', 'attack_sequence' => 'Hameçonnage fictif', 'likelihood' => 3,
        ]);
        $this->assertSame(0, EbiosStudy::countElements($studyId)[5]);

        // Décision saisie : l'atelier 5 est commencé, rien n'est encore créé dans le registre.
        $decision = ['id' => $scenarioId, 'treatment' => 'mitigate', 'justification' => 'Clause'];
        $this->assertTrue($scenario->update($decision));
        $scenario->update(['id' => $scenarioId, 'treatment' => 'inconnu']);
        $scenario->getFromDB($scenarioId);
        $this->assertSame('mitigate', $scenario->fields['treatment']);
        $this->assertSame(1, EbiosStudy::countElements($studyId)[5]);
        $this->assertSame(0, (int) $scenario->fields[Risk::getForeignKeyField()]);

        $riskId = $scenario->sendToRegister();
        $this->assertGreaterThan(0, $riskId);
        $risk = new Risk();
        $risk->getFromDB($riskId);
        $this->assertSame('Scénario vers le registre', $risk->fields['title']);
        $this->assertSame('third_party', $risk->fields['category'], 'Le chemin passe par une partie prenante.');
        $this->assertSame('probable', $risk->fields['probability']);
        $this->assertSame('critical', $risk->fields['impact']);
        $this->assertSame('mitigate', $risk->fields['treatment']);
        $this->assertStringContainsString('Hameçonnage fictif', (string) $risk->fields['description']);
        $this->assertStringContainsString('Prestataire fictif', (string) $risk->fields['description']);

        // Une seule création, et le risque n'est pas modifié quand le scénario change ensuite.
        $this->assertSame($riskId, $scenario->sendToRegister());
        $this->assertTrue($scenario->update(['id' => $scenarioId, 'likelihood' => 1]));
        $risk->getFromDB($riskId);
        $this->assertSame('probable', $risk->fields['probability']);

        // L'écart est signalé dans l'atelier 5.
        $study->getFromDB($studyId);
        ob_start();
        EbiosStudy::displayTabContentForItem($study, 5);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Scénario vers le registre', $html);
        $this->assertStringContainsString('ti-alert-triangle', $html);
        $this->assertStringNotContainsString('name="send_to_register"', $html);

        // Rapport complet et rapport d'un seul atelier.
        $full = $study->renderReport();
        $this->assertStringContainsString('Scénario vers le registre', $full);
        $this->assertStringContainsString('Facturation fictive', $full);
        $this->assertStringContainsString('Prestataire fictif', $full);
        $onlyTwo = $study->renderReport(2);
        $this->assertStringContainsString('Source fictive', $onlyTwo);
        $this->assertStringNotContainsString('Facturation fictive', $onlyTwo);
        $this->assertStringNotContainsString('Scénario vers le registre', $onlyTwo);

        // Retirer le scénario garde le risque ; un risque retiré du registre peut être recréé.
        $otherId = (int) (new EbiosOperationalScenario())->add($fk + [
            EbiosStrategicScenario::getForeignKeyField() => $strategicId, 'name' => 'Autre scénario',
        ]);
        $other = new EbiosOperationalScenario();
        $other->getFromDB($otherId);
        $otherRiskId = $other->sendToRegister();
        (new Risk())->delete(['id' => $otherRiskId], true);
        $this->assertGreaterThan($otherRiskId, $other->sendToRegister());

        $study->delete(['id' => $studyId], true);
        $this->assertTrue((new Risk())->getFromDB($riskId), 'Le registre garde le risque après l\'étude.');
        (new Risk())->delete(['id' => $riskId], true);
        (new Risk())->delete(['id' => (int) $other->fields[Risk::getForeignKeyField()]], true);
    }

    public function testWithoutTheRightToCreateARiskTheScenarioStaysUnlinked(): void
    {
        $study = new EbiosStudy();
        $studyId = (int) $study->add(['name' => 'PHPUnit étude sans droit']);
        $fk = [EbiosElement::STUDY_FK => $studyId];
        $valueId = (int) (new EbiosValue())->add($fk + ['name' => 'Valeur fictive']);
        $eventId = (int) (new EbiosFearedEvent())->add($fk + [
            'plugin_grcmanager_ebiosvalues_id' => $valueId, 'name' => 'Arrêt',
        ]);
        $sourceId = (int) (new EbiosRiskSource())->add($fk + ['name' => 'Source fictive']);
        $strategicId = (int) (new EbiosStrategicScenario())->add($fk + [
            EbiosRiskSource::getForeignKeyField() => $sourceId, EbiosFearedEvent::getForeignKeyField() => $eventId,
            'name' => 'Direct',
        ]);
        $scenario = new EbiosOperationalScenario();
        $scenarioId = (int) $scenario->add($fk + [
            EbiosStrategicScenario::getForeignKeyField() => $strategicId, 'name' => 'Sans création',
        ]);
        $scenario->getFromDB($scenarioId);
        $saved = $_SESSION['glpiactiveprofile'][Risk::RIGHTNAME];
        try {
            $_SESSION['glpiactiveprofile'][Risk::RIGHTNAME] = READ;
            $this->assertSame(0, $scenario->sendToRegister());
        } finally {
            $_SESSION['glpiactiveprofile'][Risk::RIGHTNAME] = $saved;
        }
        $scenario->getFromDB($scenarioId);
        $this->assertSame(0, (int) $scenario->fields[Risk::getForeignKeyField()]);
        $study->delete(['id' => $studyId], true);
    }

    public function testWorkshopTabsRender(): void
    {
        $study = new EbiosStudy();
        $studyId = (int) $study->add(['name' => 'PHPUnit étude affichage']);
        $fk = [EbiosElement::STUDY_FK => $studyId];
        $valueId = (int) (new EbiosValue())->add($fk + ['name' => 'Facturation fictive']);
        $eventId = (int) (new EbiosFearedEvent())->add($fk + [
            'plugin_grcmanager_ebiosvalues_id' => $valueId, 'name' => 'Arrêt fictif', 'severity' => 4,
        ]);
        $sourceId = (int) (new EbiosRiskSource())->add($fk + ['name' => 'Source fictive']);
        $stakeholderId = (int) (new EbiosStakeholder())->add($fk + [
            'name' => 'Partenaire fictif', 'dependence' => 4, 'penetration' => 4, 'maturity' => 1, 'trust' => 1,
        ]);
        $strategicId = (int) (new EbiosStrategicScenario())->add($fk + [
            EbiosRiskSource::getForeignKeyField() => $sourceId, EbiosFearedEvent::getForeignKeyField() => $eventId,
            EbiosStakeholder::getForeignKeyField() => $stakeholderId, 'name' => 'Chemin fictif',
        ]);
        (new EbiosOperationalScenario())->add($fk + [
            EbiosStrategicScenario::getForeignKeyField() => $strategicId,
            'name' => 'Mode opératoire fictif', 'likelihood' => 3,
        ]);

        $study->getFromDB($studyId);
        ob_start();
        EbiosStudy::displayTabContentForItem($study, 3);
        $three = (string) ob_get_clean();
        $this->assertStringContainsString('Partenaire fictif', $three);
        $this->assertStringContainsString('16', $three, 'Niveau de menace (4 x 4) / (1 x 1).');
        $this->assertStringContainsString('Chemin fictif', $three);

        ob_start();
        EbiosStudy::displayTabContentForItem($study, 4);
        $four = (string) ob_get_clean();
        $this->assertStringContainsString('Mode opératoire fictif', $four);
        $this->assertStringContainsString('bg-red-lt fw-bold', $four, 'G4 x V3 : case critique occupée.');

        // En lecture seule : les données restent visibles, sans aucun formulaire de modification.
        $saved = $_SESSION['glpiactiveprofile'][EbiosElement::RIGHTNAME];
        try {
            $_SESSION['glpiactiveprofile'][EbiosElement::RIGHTNAME] = READ;
            foreach ([3 => 'Chemin fictif', 4 => 'Mode opératoire fictif'] as $tab => $expected) {
                ob_start();
                EbiosStudy::displayTabContentForItem($study, $tab);
                $html = (string) ob_get_clean();
                $this->assertStringContainsString($expected, $html);
                $this->assertStringNotContainsString('<form', $html);
            }
        } finally {
            $_SESSION['glpiactiveprofile'][EbiosElement::RIGHTNAME] = $saved;
        }

        $study->delete(['id' => $studyId], true);
    }

    public function testElementsRequireTheRightToEditTheStudy(): void
    {
        $studyId = (int) (new EbiosStudy())->add(['name' => 'PHPUnit étude droits']);
        $saved = $_SESSION['glpiactiveprofile'][EbiosElement::RIGHTNAME];
        try {
            $_SESSION['glpiactiveprofile'][EbiosElement::RIGHTNAME] = READ;
            $this->assertFalse((new EbiosValue())->add([
                EbiosElement::STUDY_FK => $studyId,
                'name' => 'Refusée en lecture seule',
            ]));
        } finally {
            $_SESSION['glpiactiveprofile'][EbiosElement::RIGHTNAME] = $saved;
        }
    }
}

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
use GlpiPlugin\Grcmanager\EbiosRiskSource;
use GlpiPlugin\Grcmanager\EbiosStudy;
use GlpiPlugin\Grcmanager\EbiosSupport;
use GlpiPlugin\Grcmanager\EbiosValue;

/**
 * Issue #376 : étude EBIOS Risk Manager, ateliers 1 et 2 (données fictives).
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

        $this->assertSame([1 => 3, 2 => 1], EbiosStudy::countElements($studyId));

        // Retirer la valeur métier retire ce qui en dépend.
        (new EbiosValue())->delete(['id' => $valueId], true);
        $this->assertSame([1 => 0, 2 => 1], EbiosStudy::countElements($studyId));

        // Supprimer l'étude supprime tout.
        $study->delete(['id' => $studyId], true);
        $this->assertSame([1 => 0, 2 => 0], EbiosStudy::countElements($studyId));
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

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

use PluginGrcmanagerSecurityIncidentTemplate;
use PluginGrcmanagerSecurityIncidentTemplatePredefinedField;

/**
 * Issue #796 : un gabarit d'incident de sécurité avec des champs prédéfinis (statut, priorité,
 * impact) se charge sans « Unknown ITIL type », sur GLPI 11 comme sur GLPI 12 : c'est ce chargement
 * qui affiche l'aperçu du gabarit et applique le gabarit à un nouvel incident.
 */
final class SecurityIncidentTemplateTest extends GrcmanagerIntegrationTestCase
{
    public function testTemplateWithPredefinedFieldsLoads(): void
    {
        $template = new PluginGrcmanagerSecurityIncidentTemplate();
        $templateId = (int) $template->add(['name' => 'PHPUnit gabarit 796', 'entities_id' => 0]);
        $this->assertGreaterThan(0, $templateId);

        // Numéros des options de recherche des objets ITIL : 12 statut, 3 priorité, 11 impact.
        $predefined = new PluginGrcmanagerSecurityIncidentTemplatePredefinedField();
        foreach ([12 => 2, 3 => 4, 11 => 3] as $num => $value) {
            $this->assertGreaterThan(0, (int) $predefined->add([
                'securityincidenttemplates_id' => $templateId,
                'num'   => $num,
                'value' => $value,
            ]));
        }

        $fields = (new PluginGrcmanagerSecurityIncidentTemplatePredefinedField())
            ->getPredefinedFields($templateId, true);
        $this->assertSame('2', (string) ($fields['status'] ?? ''));
        $this->assertSame('4', (string) ($fields['priority'] ?? ''));
        $this->assertSame('3', (string) ($fields['impact'] ?? ''));

        // Chargement complet utilisé par l'aperçu et par un nouvel incident.
        $loaded = new PluginGrcmanagerSecurityIncidentTemplate();
        $this->assertTrue($loaded->getFromDBWithData($templateId, true));
        $this->assertSame('2', (string) ($loaded->predefined['status'] ?? ''));
    }

    /**
     * Issue #951 : la liste des gabarits (bouton « Modèle » des incidents de sécurité) plantait dès
     * qu'un gabarit existait : le lien du nom cherche la classe depuis la table, raccourcie, que
     * le cœur ne savait pas résoudre.
     */
    public function testTemplateListRendersWithRows(): void
    {
        $dbu = new \DbUtils();
        foreach (\GlpiPlugin\Grcmanager\Compat\ShortTableItemtypes::MAP as $table => $class) {
            $this->assertSame($table, $class::getTable());
            $this->assertSame($class, $dbu->getItemTypeForTable($table));
        }

        $template = new PluginGrcmanagerSecurityIncidentTemplate();
        $this->assertGreaterThan(0, (int) $template->add(['name' => 'PHPUnit gabarit 951', 'entities_id' => 0]));

        $session = [$_SESSION['glpiactiveentities_string'] ?? null, $_SESSION['glpiparententities'] ?? null];
        $_SESSION['glpiactiveentities_string'] = "'" . implode("','", $_SESSION['glpiactiveentities']) . "'";
        $_SESSION['glpiparententities'] = [];
        try {
            $data = \Glpi\Search\SearchEngine::getData(PluginGrcmanagerSecurityIncidentTemplate::class, ['criteria' => []]);
        } finally {
            [$_SESSION['glpiactiveentities_string'], $_SESSION['glpiparententities']] = $session;
        }
        $this->assertGreaterThan(0, $data['data']['totalcount']);
        $this->assertStringContainsString(
            'securityincidenttemplate.form.php?id=',
            json_encode($data['data']['rows'], JSON_UNESCAPED_SLASHES) ?: ''
        );
    }
}

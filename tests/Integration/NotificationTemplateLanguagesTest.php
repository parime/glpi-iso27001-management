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

use GlpiPlugin\Grcmanager\Install\Installer;
use GlpiPlugin\Grcmanager\Services\Notification\MoCatalog;
use GlpiPlugin\Grcmanager\Services\Notification\NotificationTemplateDefaults;

/**
 * Issue #582 : modèles de notification multilingues (anglais par défaut, une traduction par langue)
 * et migration des modèles créés en français par les versions précédentes. Indépendant de la langue
 * de l'instance : les noms attendus sont calculés dans cette langue.
 */
final class NotificationTemplateLanguagesTest extends GrcmanagerIntegrationTestCase
{
    public function testEveryPluginTemplateHasAnEnglishDefaultAndEveryLanguage(): void
    {
        foreach (NotificationTemplateDefaults::definitions() as $key => $definition) {
            $templateId = $this->templateId($key);
            $rows = $this->translations($templateId);
            foreach (array_merge([''], NotificationTemplateDefaults::LANGUAGES) as $language) {
                $this->assertArrayHasKey($language, $rows, "$key [$language]");
            }
            $english = NotificationTemplateDefaults::build($key, MoCatalog::translator(''));
            $this->assertTrue(
                NotificationTemplateDefaults::sameContent($rows[''], $english),
                "$key : traduction par défaut en anglais"
            );
            $this->assertNotSame(
                $rows['']['subject'] . $rows['']['content_text'],
                $rows['it_IT']['subject'] . $rows['it_IT']['content_text'],
                "$key : traduction italienne distincte de l'anglais"
            );
        }
    }

    public function testOriginalFrenchTemplateIsMigratedAndMissingLanguagesAdded(): void
    {
        global $DB;

        $key = 'evidence_expiring';
        $definition = NotificationTemplateDefaults::definitions()[$key];
        $templateId = $this->templateId($key);
        $legacy = NotificationTemplateDefaults::build($key, NotificationTemplateDefaults::legacyTranslator());
        $this->assertStringContainsString('Valide jusqu\'au', $legacy['content_text']);

        // Installation d'une version précédente : une seule traduction par défaut, en français.
        $DB->delete('glpi_notificationtemplatetranslations', ['notificationtemplates_id' => $templateId]);
        $DB->insert('glpi_notificationtemplatetranslations', [
            'notificationtemplates_id' => $templateId,
            'language'                 => '',
        ] + $legacy);
        $DB->update('glpi_notificationtemplates', [
            'name'    => $definition['legacy_name'],
            'comment' => $definition['legacy_comment'],
        ], ['id' => $templateId]);

        $this->migrate();
        $this->migrate(); // idempotent

        $rows = $this->translations($templateId);
        $this->assertSame(
            array_merge([''], NotificationTemplateDefaults::LANGUAGES),
            array_values(array_intersect(array_merge([''], NotificationTemplateDefaults::LANGUAGES), array_keys($rows)))
        );
        $this->assertCount(1 + count(NotificationTemplateDefaults::LANGUAGES), $rows, 'aucun doublon');
        $this->assertTrue(NotificationTemplateDefaults::sameContent(
            $rows[''],
            NotificationTemplateDefaults::build($key, MoCatalog::translator(''))
        ), 'traduction française d\'origine remplacée par l\'anglais');
        $this->assertSame(
            NotificationTemplateDefaults::build($key, MoCatalog::translator('it_IT'))['content_text'],
            $rows['it_IT']['content_text']
        );

        $names = MoCatalog::translator($this->instanceLanguage());
        $template = $DB->request(['FROM' => 'glpi_notificationtemplates', 'WHERE' => ['id' => $templateId]])->current();
        $this->assertSame(NotificationTemplateDefaults::NAME_PREFIX . $names($definition['name']), $template['name']);
        $this->assertSame($names($definition['comment']), $template['comment']);
    }

    public function testTranslationsChangedByTheAdministratorAreKept(): void
    {
        global $DB;

        $key = 'policy_review';
        $templateId = $this->templateId($key);
        $DB->delete('glpi_notificationtemplatetranslations', ['notificationtemplates_id' => $templateId]);
        $custom = ['' => 'Revue de la politique ##policy.title##', 'it_IT' => 'Testo di prova ##policy.title##'];
        foreach ($custom as $language => $text) {
            $DB->insert('glpi_notificationtemplatetranslations', [
                'notificationtemplates_id' => $templateId,
                'language'                 => $language,
                'subject'                  => $text,
                'content_text'             => $text,
                'content_html'             => '',
            ]);
        }
        $DB->update('glpi_notificationtemplates', ['name' => 'Modèle renommé (test)'], ['id' => $templateId]);

        $this->migrate();

        $rows = $this->translations($templateId);
        $this->assertSame($custom[''], $rows['']['content_text'], 'défaut modifié conservé');
        $this->assertSame($custom['it_IT'], $rows['it_IT']['content_text'], 'traduction modifiée conservée');
        $this->assertArrayHasKey('de_DE', $rows, 'langue manquante ajoutée');
        $this->assertArrayNotHasKey('fr_FR', $rows, 'pas de français par-dessus un défaut modifié');
        $template = $DB->request(['FROM' => 'glpi_notificationtemplates', 'WHERE' => ['id' => $templateId]])->current();
        $this->assertSame('Modèle renommé (test)', $template['name'], 'nom modifié conservé');
    }

    public function testEveryTemplateTextIsTranslatedInItalian(): void
    {
        $italian = MoCatalog::messages('it_IT');
        $missing = [];
        $record = static function (string $msgid) use ($italian, &$missing): string {
            if (!isset($italian[$msgid])) {
                $missing[$msgid] = true;
            }

            return $msgid;
        };
        foreach (NotificationTemplateDefaults::definitions() as $key => $definition) {
            NotificationTemplateDefaults::build($key, $record);
            foreach (['name', 'comment', 'notification_comment'] as $field) {
                if ($definition[$field] !== '') {
                    $record($definition[$field]);
                }
            }
        }
        $this->assertSame([], array_keys($missing));
        $this->assertSame([], array_diff(
            array_keys(NotificationTemplateDefaults::LEGACY_FR),
            array_keys(MoCatalog::messages('fr_FR'))
        ), 'chaque texte d\'origine a sa traduction française');
    }

    private function migrate(): void
    {
        (new \ReflectionMethod(Installer::class, 'migrateNotificationTemplateLanguages'))->invoke(new Installer());
    }

    private function templateId(string $key): int
    {
        global $DB;

        $definition = NotificationTemplateDefaults::definitions()[$key];
        $row = $DB->request([
            'SELECT'     => 'nt.notificationtemplates_id',
            'FROM'       => 'glpi_notifications AS n',
            'INNER JOIN' => [
                'glpi_notifications_notificationtemplates AS nt' => [
                    'ON' => ['nt' => 'notifications_id', 'n' => 'id'],
                ],
            ],
            'WHERE'      => ['n.itemtype' => $definition['itemtype'], 'n.event' => $definition['event']],
        ])->current();
        $this->assertIsArray($row, $key);

        return (int) $row['notificationtemplates_id'];
    }

    /** @return array<string, array<string, mixed>> */
    private function translations(int $templateId): array
    {
        global $DB;

        $rows = [];
        $translations = $DB->request([
            'FROM'  => 'glpi_notificationtemplatetranslations',
            'WHERE' => ['notificationtemplates_id' => $templateId],
        ]);
        foreach ($translations as $row) {
            $rows[(string) $row['language']] = $row;
        }

        return $rows;
    }

    private function instanceLanguage(): string
    {
        global $CFG_GLPI;

        return (string) ($CFG_GLPI['language'] ?? 'en_GB');
    }
}

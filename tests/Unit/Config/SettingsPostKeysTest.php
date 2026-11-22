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

namespace GlpiPlugin\Grcmanager\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * Issue #1047 (navigation simplifiée #1035) : un réglage se fait dans la page de configuration du
 * plugin, jamais dans une page d'exploitation. Contrôle statique :
 * - les clés d'envoi des réglages (celles que front/config.php et les blocs de src/Config/ testent
 *   avec isset($_POST[...])) ne sont lues nulle part ailleurs, sauf l'ancien envoi d'une page
 *   d'exploitation accepté pendant une version, qui passe la main au bloc ;
 * - aucun gabarit hors de templates/config/ n'affiche de bouton de réglage ;
 * - une page d'exploitation ne traite pas elle-même un envoi nommé « ..._config » ou
 *   « ..._settings » (un réglage oublié lors du rangement).
 */
final class SettingsPostKeysTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /**
     * Actions d'exploitation encore acceptées par front/config.php depuis leur ancien bouton : ce
     * ne sont pas des réglages, leur écran d'exploitation les traite légitimement.
     */
    private const OPERATION_KEYS = [
        // Fin de support (endoflife.date) : lecture immédiate et correspondances, Logiciels à surveiller (#1039).
        'refresh_endoflife',
        'add_endoflife_mapping',
        'delete_endoflife_mapping',
        // Saisie groupée de la fin de vie des modèles de matériel (#1039).
        'update_hardware_eol',
        // Envoi immédiat du rapport des vulnérabilités (#1039).
        'send_vulnerability_digest',
        // Noms génériques de l'import d'un référentiel personnalisé, partagés avec l'import des risques.
        'analyse',
        'confirm_import',
    ];

    /** Marques d'un ancien envoi qui passe la main au bloc de la configuration (accepté une version). */
    private const HANDOVER = ['Config\\Block\\', 'SettingsLink::redirectMoved', 'MovedSettings::', 'Block::handle'];

    /** @return list<string> */
    private static function files(string $dir, string $extension): array
    {
        $root = self::ROOT . '/' . $dir;
        if (!is_dir($root)) {
            return [];
        }
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === $extension) {
                $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen(self::ROOT) + 1));
            }
        }
        sort($found);

        return $found;
    }

    private static function read(string $path): string
    {
        $content = file_get_contents(self::ROOT . '/' . $path);
        self::assertIsString($content, $path);

        return $content;
    }

    /** @return list<string> */
    private static function postedKeys(string $content): array
    {
        preg_match_all('/isset\(\s*\$_POST\[\'([a-z0-9_]+)\'\]\s*\)/', $content, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return list<string> clés d'envoi des réglages */
    private static function settingsKeys(): array
    {
        $keys = self::postedKeys(self::read('front/config.php'));
        foreach (self::files('src/Config', 'php') as $path) {
            $keys = array_merge($keys, self::postedKeys(self::read($path)));
        }
        $keys = array_values(array_diff(array_unique($keys), self::OPERATION_KEYS));
        sort($keys);

        return $keys;
    }

    private static function isSettingsFile(string $path): bool
    {
        return $path === 'front/config.php'
            || str_starts_with($path, 'src/Config/')
            || str_starts_with($path, 'templates/config/')
            || $path === 'templates/config_form.html.twig';
    }

    /**
     * Le traitement de l'envoi $key commence ligne $line : passe-t-il la main au bloc ? Lu jusqu'à la
     * fin du bloc « if » (accolade fermante en début de ligne).
     *
     * @param list<string> $lines
     */
    private static function handsOver(array $lines, int $line): bool
    {
        $body = '';
        for ($i = $line; $i < count($lines) && $i < $line + 15; $i++) {
            $body .= $lines[$i] . "\n";
            if ($i > $line && str_starts_with($lines[$i], '}')) {
                break;
            }
        }
        foreach (self::HANDOVER as $mark) {
            if (str_contains($body, $mark)) {
                return true;
            }
        }

        return false;
    }

    public function testSettingsKeysAreFound(): void
    {
        $keys = self::settingsKeys();
        // Quelques réglages connus : la lecture des clés fonctionne.
        foreach (['update_risk_matrix', 'update_frameworks', 'save_zabbix', 'update_delegation_config'] as $known) {
            $this->assertContains($known, $keys);
        }
    }

    public function testSettingsKeysAreOnlyHandledByTheConfigurationPage(): void
    {
        $keys = self::settingsKeys();
        $offenders = [];
        $paths = array_merge(
            self::files('front', 'php'),
            self::files('src', 'php'),
            self::files('ajax', 'php'),
            ['hook.php', 'setup.php']
        );
        foreach ($paths as $path) {
            if (self::isSettingsFile($path) || !is_file(self::ROOT . '/' . $path)) {
                continue;
            }
            $lines = explode("\n", self::read($path));
            foreach ($lines as $number => $text) {
                foreach ($keys as $key) {
                    if (!str_contains($text, "\$_POST['$key']")) {
                        continue;
                    }
                    if (str_starts_with($path, 'front/') && self::handsOver($lines, $number)) {
                        continue;
                    }
                    $offenders[] = "$path:" . ($number + 1) . " ($key)";
                }
            }
        }
        $this->assertSame([], $offenders, 'Réglage traité hors de la configuration du plugin.');
    }

    public function testNoSettingsButtonOutsideTheConfigurationTemplates(): void
    {
        $keys = self::settingsKeys();
        $offenders = [];
        foreach (self::files('templates', 'twig') as $path) {
            if (self::isSettingsFile($path)) {
                continue;
            }
            $content = self::read($path);
            foreach ($keys as $key) {
                if (preg_match('/name="' . preg_quote($key, '/') . '"/', $content) === 1) {
                    $offenders[] = "$path ($key)";
                }
            }
            if (preg_match_all('/name="([a-z0-9_]+_(?:config|settings))"/', $content, $matches) > 0) {
                foreach ($matches[1] as $key) {
                    $offenders[] = "$path ($key)";
                }
            }
        }
        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            'Formulaire de réglage dans un écran d\'exploitation.'
        );
    }

    public function testOperationPagesDoNotHandleTheirOwnSettings(): void
    {
        $offenders = [];
        foreach (self::files('front', 'php') as $path) {
            if (self::isSettingsFile($path)) {
                continue;
            }
            $lines = explode("\n", self::read($path));
            foreach ($lines as $number => $text) {
                if (preg_match('/isset\(\s*\$_POST\[\'([a-z0-9_]+_(?:config|settings))\'\]/', $text, $match) !== 1) {
                    continue;
                }
                if (!self::handsOver($lines, $number)) {
                    $offenders[] = "$path:" . ($number + 1) . " ({$match[1]})";
                }
            }
        }
        $this->assertSame(
            [],
            $offenders,
            'Page d\'exploitation qui enregistre un réglage : le ranger dans un bloc de la configuration.'
        );
    }
}

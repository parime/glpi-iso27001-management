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

namespace GlpiPlugin\Grcmanager\Services\Lifecycle;

/**
 * Issue #704 : produit endoflife.date d'un logiciel, d'un système d'exploitation ou d'un firmware
 * du parc. PHP pur, sans GLPI.
 *
 * - Correspondance manuelle d'abord (onglet de la configuration) : nom exact dans GLPI, sans
 *   tenir compte de la casse, vers un produit ; un produit vide veut dire « ne pas suivre »
 *   (correspondance automatique écartée).
 * - Sinon correspondance automatique, seulement quand elle est sûre : le nom, ramené à sa forme
 *   simple (minuscules, précisions entre parenthèses et numéros de version retirés), doit
 *   correspondre EN ENTIER à l'une des formes connues ci-dessous. Jamais de ressemblance ni de
 *   recherche approchée : un nom inconnu reste « non rapproché ».
 */
final class ProductMatcher
{
    public const KIND_SOFTWARE = 'software';
    public const KIND_OS = 'os';
    public const KIND_FIRMWARE = 'firmware';

    public const KINDS = [self::KIND_SOFTWARE, self::KIND_OS, self::KIND_FIRMWARE];

    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_AUTO = 'auto';
    public const ORIGIN_NONE = 'none';

    /**
     * Formes sûres, par type d'élément : expression entière sur le nom simplifié => produit.
     *
     * @var array<string, array<string, string>>
     */
    public const AUTO_RULES = [
        self::KIND_SOFTWARE => [
            '/^(mozilla )?firefox( esr)?$/'                         => 'firefox',
            '/^google chrome$/'                                     => 'chrome',
            '/^libreoffice$/'                                       => 'libreoffice',
            '/^notepad\+\+$/'                                       => 'notepad-plus-plus',
            '/^python$/'                                            => 'python',
            '/^php$/'                                               => 'php',
            '/^node\.?js$/'                                         => 'nodejs',
            '/^postgresql( server)?$/'                              => 'postgresql',
            '/^mysql server$/'                                      => 'mysql',
            '/^mariadb( server)?$/'                                 => 'mariadb',
            '/^nginx$/'                                             => 'nginx',
            '/^(apache2|httpd|apache http server)$/'                => 'apache-http-server',
            '/^(apache )?tomcat$/'                                  => 'tomcat',
            '/^(oracle )?(vm )?virtualbox$/'                        => 'virtualbox',
            '/^wireshark$/'                                         => 'wireshark',
            '/^microsoft office( (standard|professional|professional plus|home and business))? \d{4}$/'
                                                                    => 'office',
            '/^microsoft sql server \d{4}$/'                        => 'mssqlserver',
            '/^openssl$/'                                           => 'openssl',
            '/^openvpn$/'                                           => 'openvpn',
            '/^git$/'                                               => 'git',
            '/^(powershell|powershell 7)$/'                         => 'powershell',
            '/^redis( server)?$/'                                   => 'redis',
            '/^samba$/'                                             => 'samba',
            '/^zabbix agent 2?$/'                                   => 'zabbix',
            '/^veeam backup (&|and) replication$/'                  => 'veeam-backup-and-replication',
        ],
        self::KIND_OS => [
            '/^microsoft windows (10|11)( .*)?$/'                   => 'windows',
            '/^microsoft windows server \d{4}( .*)?$/'              => 'windows-server',
            '/^debian( gnu\/linux)?$/'                              => 'debian',
            '/^ubuntu( server)?$/'                                  => 'ubuntu',
            '/^(red hat enterprise linux|rhel)( server| workstation)?$/' => 'rhel',
            '/^centos( linux)?$/'                                   => 'centos',
            '/^centos stream$/'                                     => 'centos-stream',
            '/^rocky linux$/'                                       => 'rocky-linux',
            '/^almalinux$/'                                         => 'almalinux',
            '/^alpine linux$/'                                      => 'alpine-linux',
            '/^fedora( linux)?$/'                                   => 'fedora',
            '/^linux mint$/'                                        => 'linuxmint',
            '/^(macos|mac os x|os x)$/'                             => 'macos',
            '/^suse linux enterprise server$/'                      => 'sles',
            '/^oracle linux( server)?$/'                            => 'oracle-linux',
            '/^freebsd$/'                                           => 'freebsd',
            '/^proxmox (ve|virtual environment)$/'                  => 'proxmox-ve',
            '/^(vmware )?esxi$/'                                    => 'esxi',
        ],
        self::KIND_FIRMWARE => [
            '/^fortios$/'                                           => 'fortios',
            '/^routeros$/'                                          => 'routeros',
            '/^pan-?os$/'                                           => 'panos',
            '/^cisco ios xe$/'                                      => 'cisco-ios-xe',
            '/^opnsense$/'                                          => 'opnsense',
        ],
    ];

    /**
     * Forme simple d'un nom : minuscules, parenthèses et crochets retirés, numéros de version en
     * fin de nom retirés (« Python 3.11.4 (64-bit) » : « python »), espaces réduits. Un numéro qui
     * nomme le produit (« Windows 11 », « Office 2019 ») est gardé : seuls les numéros pointés et
     * les mots d'architecture en fin de nom partent.
     */
    public static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = (string) preg_replace('/\([^)]*\)|\[[^\]]*\]/u', ' ', $name);
        $name = (string) preg_replace('/\s+/u', ' ', trim($name));
        do {
            $before = $name;
            $name = (string) preg_replace(
                '/\s+(v?\d+(\.\d+)+[a-z0-9.+~-]*|x64|x86|amd64|arm64|64-bit|32-bit|64 bits|32 bits)$/u',
                '',
                $name
            );
        } while ($name !== $before);

        return trim($name);
    }

    /**
     * Clé d'une correspondance manuelle (type d'élément et nom exact, sans casse).
     */
    public static function key(string $kind, string $name): string
    {
        return $kind . "\0" . mb_strtolower(trim($name));
    }

    /**
     * Produit d'un élément du parc, et origine de la correspondance.
     *
     * @param array<string, string> $manual Clé (key()) => produit ('' : ne pas suivre).
     * @return array{product: string, origin: string}
     */
    public static function resolve(string $kind, string $name, array $manual): array
    {
        $key = self::key($kind, $name);
        if (array_key_exists($key, $manual)) {
            $product = $manual[$key];

            return [
                'product' => $product,
                'origin'  => $product === '' ? self::ORIGIN_NONE : self::ORIGIN_MANUAL,
            ];
        }
        $auto = self::automatic($kind, $name);

        return ['product' => $auto, 'origin' => $auto === '' ? self::ORIGIN_NONE : self::ORIGIN_AUTO];
    }

    /**
     * Correspondance automatique sûre, '' sinon.
     */
    public static function automatic(string $kind, string $name): string
    {
        $simple = self::normalize($name);
        if ($simple === '') {
            return '';
        }
        foreach (self::AUTO_RULES[$kind] ?? [] as $pattern => $product) {
            if (preg_match($pattern, $simple) === 1) {
                return $product;
            }
        }

        return '';
    }
}

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

namespace GlpiPlugin\Grcmanager\Services\Network;

/**
 * Issue #144, phase 3 : politique de filtrage d'un pare-feu Stormshield (Politique de sécurité >
 * Filtrage et NAT > Exporter, fichier CSV dont l'en-tête commence par « #type_slot ») convertie
 * en lignes d'import de la matrice des flux, même forme que NetworkFlowCsv::parse().
 *
 * Seules les règles de filtrage actives qui autorisent un flux (« pass ») sont reprises ; les
 * séparateurs de section et les règles de NAT sont ignorés (la redirection d'une publication est
 * lue dans la règle de filtrage elle-même, colonne nat_to_target). L'export ne donne que le nom
 * des objets : les services courants (https, ssh, ntp…) sont traduits en ports, un service propre
 * à la configuration est signalé et la règle ignorée plutôt qu'importée avec de faux ports.
 *
 * Côté Internet sans l'export des objets : chaque côté d'une règle est classé d'après les objets
 * prédéfinis (Internet, Network_internals, Network_<interface>, Firewall_<interface>), l'interface
 * d'entrée ou de sortie (celle côté Internet est choisie à l'import, déduite sinon des
 * publications), le filtrage par pays ou réputation (réservé aux adresses publiques) et une adresse
 * IP écrite dans le nom de l'objet. Une règle dont un côté reste inconnu est importée comme flux
 * interne et signalée, plutôt que classée en silence. Les noms des objets propres à la
 * configuration ne sont jamais interprétés.
 */
final class NetworkFlowStormshield
{
    /** Classement d'un côté de règle. */
    private const SIDE_INTERNET = 'internet';
    private const SIDE_INTERNAL = 'internal';
    private const SIDE_ANY = 'any';
    private const SIDE_UNKNOWN = 'unknown';

    /** Plages non routées sur Internet : privées, CGNAT, lien local, boucle locale. */
    private const NON_PUBLIC_RANGES = [
        ['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16], ['100.64.0.0', 10], ['169.254.0.0', 16],
        ['127.0.0.0', 8],
    ];

    /** Services prédéfinis de Stormshield (nom d'objet en minuscules) et leurs ports. */
    private const SERVICES = [
        'ftp' => '21', 'ssh' => '22', 'telnet' => '23', 'smtp' => '25', 'dns' => '53', 'domain' => '53',
        'tftp' => '69', 'http' => '80', 'kerberos' => '88', 'pop3' => '110', 'ntp' => '123',
        'microsoft-ds' => '445', 'netbios-ssn' => '139', 'imap' => '143', 'snmp' => '161', 'ldap' => '389',
        'https' => '443', 'smtps' => '465', 'isakmp' => '500', 'syslog' => '514', 'submission' => '587',
        'ldaps' => '636', 'imaps' => '993', 'pop3s' => '995', 'openvpn' => '1194', 'mssql' => '1433',
        'ms-sql-s' => '1433', 'radius' => '1812', 'mysql' => '3306', 'rdp' => '3389',
        'ms-wbt-server' => '3389', 'isakmp_natt' => '4500', 'sip' => '5060', 'postgresql' => '5432',
        'vnc' => '5900', 'http_proxy' => '8080',
    ];

    public static function isStormshieldExport(string $content): bool
    {
        return str_starts_with(ltrim((string) preg_replace('/^\xEF\xBB\xBF/', '', $content)), '#type_slot');
    }

    /**
     * @param string $outside interface côté Internet (nom de l'export, « out » par défaut sur un
     *     boîtier) ; vide : celle des publications, sinon « out » ou « wan » si elle existe
     * @return array{rows: list<array{line: int, name: string, flow_type: string, source_label: string,
     *     destination_label: string, protocol: string, ports: string, justification: string, owner: string,
     *     date_end: ?string}>, errors: list<array{line: int, message: string}>, merged: int,
     *     interfaces: list<string>, outside: string, unclassified: list<int>}
     */
    public static function parse(string $content, string $outside = ''): array
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) preg_replace('/^\xEF\xBB\xBF/', '', $content)) ?: [];
        $header = array_map(
            static fn (string $name): string => ltrim(strtolower(trim($name)), '#'),
            str_getcsv((string) array_shift($lines), ',', '"', '')
        );
        $column = array_flip($header);
        foreach (['type_slot', 'state', 'action', 'from_src', 'to_dest', 'to_port'] as $required) {
            if (!isset($column[$required])) {
                return [
                    'rows' => [], 'errors' => [['line' => 1, 'message' => 'missing_columns']], 'merged' => 0,
                    'interfaces' => [], 'outside' => '', 'unclassified' => [],
                ];
            }
        }

        // Règles de filtrage actives qui autorisent ; séparateurs (sans action) et NAT ignorés.
        $rules = [];
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, ',', '"', '');
            $get = static fn (string $name): string => trim((string) ($cells[$column[$name] ?? -1] ?? ''));
            if ($get('type_slot') === 'local_filter_slot' && $get('state') === 'on' && $get('action') === 'pass') {
                $rules[$index + 2] = $get;
            }
        }

        // Interfaces nommées dans les règles, et celle côté Internet.
        $interfaces = [];
        $publishedOn = [];
        foreach ($rules as $get) {
            foreach (['from_if', 'to_if'] as $name) {
                $interface = $get($name);
                if ($interface !== '' && strtolower($interface) !== 'any') {
                    $interfaces[strtolower($interface)] = $interface;
                }
            }
            $natTarget = $get('nat_to_target');
            if ($natTarget !== '' && $natTarget !== 'any' && $get('from_if') !== '') {
                $publishedOn[strtolower($get('from_if'))] = ($publishedOn[strtolower($get('from_if'))] ?? 0) + 1;
            }
        }
        $outside = strtolower(trim($outside));
        if (!isset($interfaces[$outside])) {
            arsort($publishedOn);
            $outside = (string) (array_key_first($publishedOn)
                ?? (isset($interfaces['out']) ? 'out' : (isset($interfaces['wan']) ? 'wan' : 'out')));
        }
        $interfaceNames = array_map('strtolower', array_keys($interfaces));

        $rows = [];
        $errors = [];
        $seen = [];
        $merged = 0;
        $unclassified = [];
        foreach ($rules as $number => $get) {
            $ports = self::ports($get('to_port'), $unknown);
            if ($ports === null) {
                $errors[] = ['line' => $number, 'message' => 'unknown_service:' . implode(', ', $unknown)];
                continue;
            }
            $natTarget = $get('nat_to_target');
            $published = $natTarget !== '' && $natTarget !== 'any';
            $source = self::objects($get('from_src'));
            $destination = $published ? self::objects($natTarget) : self::objects($get('to_dest'));

            $sourceSide = self::side($get('from_src'), $get('from_if'), $outside, $interfaceNames, [
                $get('from_geo'), $get('from_ip_rep'), $get('from_host_rep'),
            ]);
            $destinationSide = $published
                ? self::SIDE_INTERNAL
                : self::side($get('to_dest'), $get('to_if'), $outside, $interfaceNames, [
                    $get('to_geo'), $get('to_ip_rep'), $get('to_host_rep'),
                ]);
            $flowType = match (true) {
                $published                                         => 'internet_publication',
                strtolower($get('via')) === 'ipsec'                => 'site_tunnel',
                $sourceSide === self::SIDE_INTERNET               => 'internet_publication',
                $destinationSide === self::SIDE_INTERNET,
                $destinationSide === self::SIDE_ANY                => 'internet_outbound',
                default                                            => 'internal',
            };
            if (
                $flowType === 'internal'
                && ($sourceSide === self::SIDE_UNKNOWN || $destinationSide === self::SIDE_UNKNOWN)
            ) {
                $unclassified[] = $number;
            }
            $source = $source === 'any'
                ? ($sourceSide === self::SIDE_INTERNET || $published ? 'Internet' : 'Tous')
                : $source;
            $destination = $destination === 'any'
                ? ($flowType === 'internet_outbound' ? 'Internet' : 'Tous')
                : $destination;
            $protocol = NetworkFlowRules::normalizeProtocol(($get('ip_proto') ?: $get('proto')) ?: 'any');

            $key = implode("\0", [$flowType, $source, $destination, $protocol, $ports]);
            if (isset($seen[$key])) {
                $merged++;
                continue;
            }
            $seen[$key] = true;
            $comment = $get('comment');
            // Commentaire généré par l'interface (« Created on… », « Créé le… ») : pas un nom.
            if (preg_match('/^(created on|créé le|cree le)\b/iu', $comment) === 1) {
                $comment = '';
            }
            $rows[] = [
                'line'              => $number,
                'name'              => $comment !== ''
                    ? mb_substr($comment, 0, 255)
                    : sprintf('Stormshield : %s → %s', $source, $destination),
                'flow_type'         => $flowType,
                'source_label'      => mb_substr($source, 0, 255),
                'destination_label' => mb_substr($destination, 0, 255),
                'protocol'          => $protocol,
                'ports'             => $ports,
                'justification'     => '',
                'owner'             => '',
                'date_end'          => null,
            ];
        }

        return [
            'rows'         => $rows,
            'errors'       => $errors,
            'merged'       => $merged,
            'interfaces'   => array_values($interfaces),
            'outside'      => $interfaces[$outside] ?? $outside,
            'unclassified' => $unclassified,
        ];
    }

    /**
     * Côté d'une règle : Internet, interne, « any » ou inconnu. Le filtrage par pays ou réputation
     * (réservé aux adresses publiques) puis l'interface priment sur les objets.
     *
     * @param list<string> $interfaces interfaces nommées dans l'export, en minuscules
     * @param list<string> $publicOnly colonnes de pays et de réputation de ce côté
     */
    private static function side(
        string $objects,
        string $interface,
        string $outside,
        array $interfaces,
        array $publicOnly
    ): string {
        foreach ($publicOnly as $value) {
            if ($value !== '' && strtolower($value) !== 'any') {
                return self::SIDE_INTERNET;
            }
        }
        $interface = strtolower($interface);
        if ($interface !== '' && $interface !== 'any') {
            return $interface === $outside ? self::SIDE_INTERNET : self::SIDE_INTERNAL;
        }
        if (self::objects($objects) === 'any') {
            return self::SIDE_ANY;
        }
        $sides = [];
        foreach (array_filter(array_map('trim', explode(',', $objects)), static fn ($v) => $v !== '') as $object) {
            $sides[self::objectSide($object, $outside, $interfaces)] = true;
        }

        return count($sides) === 1 ? (string) array_key_first($sides) : self::SIDE_UNKNOWN;
    }

    /**
     * Objet prédéfini (Internet, Network_internals, Network_<interface>, Firewall_<interface>) ou
     * nom contenant une adresse IPv4 ; tout autre nom reste inconnu.
     *
     * @param list<string> $interfaces
     */
    private static function objectSide(string $object, string $outside, array $interfaces): string
    {
        $name = strtolower($object);
        if ($name === 'internet') {
            return self::SIDE_INTERNET;
        }
        if (preg_match('/^(?:network|firewall)_(.+)$/', $name, $m) === 1) {
            return match (true) {
                $m[1] === $outside || $m[1] === 'out'                       => self::SIDE_INTERNET,
                $m[1] === 'internals' || $m[1] === 'all'
                    || in_array($m[1], $interfaces, true)
                    || preg_match('/^(in|dmz\d*|bridge)$/', $m[1]) === 1    => self::SIDE_INTERNAL,
                default                                                    => self::SIDE_UNKNOWN,
            };
        }
        if (preg_match('/(?<![\d.])(\d{1,3}(?:\.\d{1,3}){3})(?!\d)/', $name, $m) === 1) {
            $ip = ip2long($m[1]);
            if ($ip !== false) {
                foreach (self::NON_PUBLIC_RANGES as [$range, $bits]) {
                    $mask = (-1 << (32 - $bits)) & 0xFFFFFFFF;
                    if (($ip & $mask) === ((int) ip2long($range) & $mask)) {
                        return self::SIDE_INTERNAL;
                    }
                }

                return self::SIDE_INTERNET;
            }
        }

        return self::SIDE_UNKNOWN;
    }

    /**
     * Liste d'objets « A,B » en « A, B » ; « any » ou vide : « any ».
     */
    private static function objects(string $value): string
    {
        $items = array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($v) => $v !== ''));
        if ($items === [] || in_array(strtolower($items[0]), ['any', 'all'], true)) {
            return 'any';
        }

        return implode(', ', $items);
    }

    /**
     * Services « http,https,dns » ou ports « 2222,8000-8100 » en ports canoniques ; null si un nom
     * de service n'est pas connu (il est alors ajouté à $unknown).
     *
     * @param-out list<string> $unknown
     */
    private static function ports(string $value, mixed &$unknown = null): ?string
    {
        $unknown = [];
        $ports = [];
        $items = array_filter(array_map('trim', explode(',', strtolower($value))), static fn ($v) => $v !== '');
        foreach ($items as $item) {
            if (in_array($item, ['any', 'all'], true)) {
                return 'any';
            }
            if (preg_match('/^\d{1,5}(-\d{1,5})?$/', $item) === 1) {
                $ports[] = $item;
            } elseif (isset(self::SERVICES[$item])) {
                $ports[] = self::SERVICES[$item];
            } else {
                $unknown[] = $item;
            }
        }
        if ($unknown !== []) {
            return null;
        }

        return NetworkFlowRules::normalizePorts(implode(',', $ports)) ?? 'any';
    }
}

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
 * Issue #144, phase 2 : règles de filtrage d'une sauvegarde pfSense (config.xml, Diagnostics >
 * Backup & Restore) converties en lignes d'import de la matrice des flux, même forme que
 * NetworkFlowCsv::parse(). Seules les règles actives qui autorisent un flux (« pass ») sont
 * reprises ; les alias d'hôtes, de réseaux et de ports sont résolus. Aucune justification ni
 * aucun propriétaire n'est déduit : ils restent à renseigner dans la matrice.
 *
 * Phase 3 : zones nommées d'après les interfaces, interface côté Internet choisie à l'import,
 * « tout sauf les réseaux privés » lu comme Internet, règles identiques fusionnées.
 *
 * Sécurité : une sauvegarde complète contient tous les secrets du pare-feu. Seules les sections
 * interfaces, gateways (nom et interface), aliases (nom, type, adresses) et filter sont lues ;
 * la présence de secrets est seulement signalée, aucune valeur n'en est reprise.
 */
final class NetworkFlowPfsense
{
    /** Balises de secrets d'une sauvegarde complète : leur présence (non vide) est signalée. */
    private const SECRET_TAGS = 'pre-shared-key|prv|bcrypt-hash|radius_secret|varusersmotpinitsecret'
        . '|varuserspassword|accountkey|encryption_password|privatekey|password|ipsecpsk|tls|authorizedkeys';

    /** Plages privées (RFC 1918) : « tout sauf elles » désigne Internet. */
    private const PRIVATE_RANGES = [['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16]];

    /**
     * @param string $outside interface côté Internet (clé pfSense : wan, lan, optN) ; vide : déduite
     *     de la passerelle par défaut, sinon « wan »
     * @return array{rows: list<array{line: int, name: string, flow_type: string, source_label: string,
     *     destination_label: string, protocol: string, ports: string, justification: string, owner: string,
     *     date_end: ?string}>, errors: list<array{line: int, message: string}>,
     *     interfaces: array<string, string>, outside: string, secrets: bool, merged: int}
     */
    public static function parse(string $content, string $outside = ''): array
    {
        $empty = [
            'rows' => [], 'errors' => [], 'interfaces' => [], 'outside' => 'wan', 'secrets' => false, 'merged' => 0,
        ];
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false || $xml->getName() !== 'pfsense') {
            return ['errors' => [['line' => 1, 'message' => 'not_pfsense']]] + $empty;
        }

        $zones = self::zones($xml);
        $outside = strtolower($outside);
        if (!isset($zones[$outside])) {
            $outside = self::defaultOutside($xml, $zones);
        }

        $aliases = [];
        foreach ($xml->aliases->alias ?? [] as $alias) {
            $aliases[(string) $alias->name] = [
                'type'    => (string) $alias->type,
                'address' => trim((string) $alias->address),
            ];
        }

        $rows = [];
        $seen = [];
        $merged = 0;
        $errors = [];
        $number = 0;
        foreach ($xml->filter->rule ?? [] as $rule) {
            $number++;
            if ((string) $rule->type !== 'pass' || isset($rule->disabled)) {
                continue;
            }
            $interface = strtolower((string) $rule->interface);
            $source = self::endpoint($rule->source, $aliases, $zones);
            $destination = self::endpoint($rule->destination, $aliases, $zones);
            $portsInput = (string) ($rule->destination->port ?? '');
            if (isset($aliases[$portsInput]) && $aliases[$portsInput]['type'] === 'port') {
                $portsInput = $aliases[$portsInput]['address'];
            }
            $ports = NetworkFlowRules::normalizePorts(str_replace(':', '-', $portsInput));
            if ($ports === null) {
                $errors[] = ['line' => $number, 'message' => 'invalid_ports'];
                continue;
            }
            $protocol = NetworkFlowRules::normalizeProtocol((string) ($rule->protocol ?? '') ?: 'any');
            $description = trim((string) $rule->descr);
            $flowType = self::flowType($interface === $outside, $source, $destination);
            // « any » : Internet du côté extérieur du pare-feu, « Tous » sinon.
            $source = $source === 'any' ? ($interface === $outside ? 'Internet' : 'Tous') : $source;
            $destination = $destination === 'any'
                ? ($flowType === 'internet_outbound' ? 'Internet' : 'Tous')
                : $destination;
            // La même règle saisie plusieurs fois ne donne qu'un flux.
            $key = implode("\0", [$flowType, $source, $destination, $protocol, $ports]);
            if (isset($seen[$key])) {
                $merged++;
                continue;
            }
            $seen[$key] = true;
            $rows[] = [
                'line'              => $number,
                'name'              => $description !== ''
                    ? $description
                    : sprintf('pfSense %s : %s → %s', self::interfaceName($interface, $zones), $source, $destination),
                'flow_type'         => $flowType,
                'source_label'      => $source,
                'destination_label' => $destination,
                'protocol'          => $protocol,
                'ports'             => $ports,
                'justification'     => '',
                'owner'             => '',
                'date_end'          => null,
            ];
        }
        if ($number === 0) {
            $errors[] = ['line' => 1, 'message' => 'no_rule'];
        }

        return [
            'rows'       => $rows,
            'errors'     => $errors,
            'interfaces' => array_map(static fn (array $zone): string => $zone['name'], $zones),
            'outside'    => $outside,
            'secrets'    => self::containsSecrets($content),
            'merged'     => $merged,
        ];
    }

    /**
     * Interfaces de la sauvegarde : nom (description, sinon clé en majuscules) et réseau IPv4.
     *
     * @return array<string, array{name: string, ip: string, network: string}>
     */
    private static function zones(\SimpleXMLElement $xml): array
    {
        $zones = [];
        foreach ($xml->interfaces->children() ?? [] as $key => $interface) {
            $key = strtolower((string) $key);
            if (preg_match('/^(wan|lan|opt\d+)$/', $key) !== 1) {
                continue;
            }
            $description = trim((string) $interface->descr);
            $ip = trim((string) $interface->ipaddr);
            $bits = (int) $interface->subnet;
            $network = '';
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && $bits >= 0 && $bits <= 32) {
                $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
                $network = long2ip((int) ip2long($ip) & $mask) . '/' . $bits;
            } else {
                $ip = '';
            }
            $zones[$key] = [
                'name'    => $description !== '' ? $description : strtoupper($key),
                'ip'      => $ip,
                'network' => $network,
            ];
        }
        if (!isset($zones['wan'])) {
            $zones['wan'] = ['name' => 'WAN', 'ip' => '', 'network' => ''];
        }

        return $zones;
    }

    /**
     * Interface portant la passerelle par défaut (IPv4), sinon la première interface avec une
     * passerelle, sinon « wan ».
     *
     * @param array<string, array{name: string, ip: string, network: string}> $zones
     */
    private static function defaultOutside(\SimpleXMLElement $xml, array $zones): string
    {
        $default = trim((string) ($xml->gateways->defaultgw4 ?? ''));
        foreach ($xml->gateways->gateway_item ?? [] as $gateway) {
            $interface = strtolower((string) $gateway->interface);
            if ($default !== '' && (string) $gateway->name === $default && isset($zones[$interface])) {
                return $interface;
            }
        }
        foreach ($xml->interfaces->children() ?? [] as $key => $interface) {
            if (trim((string) $interface->gateway) !== '' && isset($zones[strtolower((string) $key)])) {
                return strtolower((string) $key);
            }
        }

        return 'wan';
    }

    /**
     * @param array<string, array{name: string, ip: string, network: string}> $zones
     */
    private static function interfaceName(string $interface, array $zones): string
    {
        return match (true) {
            isset($zones[$interface]) => $zones[$interface]['name'],
            $interface === 'openvpn'  => 'OpenVPN',
            $interface === 'enc0'     => 'IPsec',
            default                   => strtoupper($interface),
        };
    }

    /**
     * « any », réseau ou adresse d'une interface (nommés d'après sa description), adresse ou alias
     * résolu, avec « non » devant une négation ; « tout sauf les réseaux privés » donne « Internet ».
     *
     * @param array<string, array{type: string, address: string}> $aliases
     * @param array<string, array{name: string, ip: string, network: string}> $zones
     */
    private static function endpoint(?\SimpleXMLElement $side, array $aliases, array $zones): string
    {
        if ($side === null || isset($side->any)) {
            return 'any';
        }
        if (isset($side->network)) {
            $network = strtolower((string) $side->network);
            if (preg_match('/^(wan|lan|opt\d+)ip$/', $network, $m) === 1) {
                $zone = $zones[$m[1]] ?? null;
                $label = $zone !== null && $zone['name'] !== strtoupper($m[1])
                    ? 'IP ' . $zone['name'] . ($zone['ip'] !== '' ? ' (' . $zone['ip'] . ')' : '')
                    : 'IP ' . strtoupper($m[1]);
            } elseif (preg_match('/^(wan|lan|opt\d+)$/', $network) === 1) {
                $zone = $zones[$network] ?? null;
                $label = $zone !== null && $zone['name'] !== strtoupper($network)
                    ? $zone['name'] . ($zone['network'] !== '' ? ' (' . $zone['network'] . ')' : '')
                    : 'Réseau ' . strtoupper($network);
            } else {
                $label = $network;
            }
        } else {
            $address = (string) ($side->address ?? '');
            $resolved = isset($aliases[$address]) ? $aliases[$address]['address'] : $address;
            if (isset($side->not) && self::isPrivateOnly($resolved)) {
                return 'Internet';
            }
            $label = isset($aliases[$address]) && $aliases[$address]['address'] !== ''
                ? $address . ' (' . str_replace(' ', ', ', $aliases[$address]['address']) . ')'
                : $address;
        }
        if (isset($side->not)) {
            $label = 'non ' . $label;
        }

        return $label;
    }

    /**
     * Toutes les adresses ou réseaux IPv4 de la liste sont dans les plages privées.
     */
    private static function isPrivateOnly(string $addresses): bool
    {
        $items = preg_split('/\s+/', trim($addresses)) ?: [];
        if ($items === [] || $items === ['']) {
            return false;
        }
        foreach ($items as $item) {
            [$ip, $bits] = array_pad(explode('/', $item, 2), 2, '32');
            $valid = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            if (!$valid || !ctype_digit($bits) || (int) $bits > 32) {
                return false;
            }
            $inside = false;
            foreach (self::PRIVATE_RANGES as [$range, $rangeBits]) {
                $mask = (-1 << (32 - $rangeBits)) & 0xFFFFFFFF;
                if ((int) $bits >= $rangeBits && ((int) ip2long($ip) & $mask) === ((int) ip2long($range) & $mask)) {
                    $inside = true;
                    break;
                }
            }
            if (!$inside) {
                return false;
            }
        }

        return true;
    }

    /**
     * Règle sur l'interface extérieure depuis n'importe où : publication sur Internet ; ailleurs,
     * vers n'importe où ou vers Internet : accès à Internet ; sinon flux interne.
     */
    private static function flowType(bool $onOutside, string $source, string $destination): string
    {
        if ($onOutside && ($source === 'any' || $source === 'Internet')) {
            return 'internet_publication';
        }
        if (!$onOutside && ($destination === 'any' || $destination === 'Internet')) {
            return 'internet_outbound';
        }

        return 'internal';
    }

    /**
     * Le fichier contient au moins une balise de secret non vide (sauvegarde complète).
     */
    public static function containsSecrets(string $content): bool
    {
        return preg_match('#<(' . self::SECRET_TAGS . ')>\s*(?:<!\[CDATA\[)?\s*[^<\s\]]#', $content) === 1;
    }
}

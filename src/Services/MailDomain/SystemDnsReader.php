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

namespace GlpiPlugin\Grcmanager\Services\MailDomain;

/**
 * Issue #855 : lecture DNS par le résolveur du serveur GLPI (fonction dns_get_record de PHP), et
 * question DS envoyée au premier serveur DNS du système (/etc/resolv.conf) pour DNSSEC. Rien d'autre
 * que des serveurs DNS n'est interrogé.
 */
final class SystemDnsReader implements DnsReader
{
    /** Délai d'attente de la question DS (secondes). */
    private const TIMEOUT = 3;

    public function __construct(private readonly string $resolvConf = '/etc/resolv.conf')
    {
    }

    public function txt(string $name): array
    {
        $texts = [];
        foreach ($this->records($name, DNS_TXT) as $record) {
            if (isset($record['entries']) && is_array($record['entries'])) {
                $texts[] = implode('', array_map(static fn (mixed $part): string => (string) $part, $record['entries']));
            } elseif (isset($record['txt'])) {
                $texts[] = (string) $record['txt'];
            }
        }

        return $texts;
    }

    public function mx(string $name): array
    {
        $records = $this->records($name, DNS_MX);
        usort($records, static fn (array $a, array $b): int => (int) ($a['pri'] ?? 0) <=> (int) ($b['pri'] ?? 0));
        $hosts = [];
        foreach ($records as $record) {
            $host = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
            if ($host !== '' && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    public function exists(string $name): bool
    {
        foreach ([DNS_SOA, DNS_NS, DNS_MX, DNS_A, DNS_AAAA] as $type) {
            if ($this->records($name, $type) !== []) {
                return true;
            }
        }

        return false;
    }

    public function hasDs(string $name): ?bool
    {
        $server = $this->resolver();
        if ($server === null) {
            return null;
        }
        $socket = @stream_socket_client('udp://' . $server . ':53', $errno, $error, self::TIMEOUT);
        if ($socket === false) {
            return null;
        }
        stream_set_timeout($socket, self::TIMEOUT);
        $id = random_int(1, 0xFFFF);
        $answer = false;
        if (@fwrite($socket, DnsWire::query($id, $name, DnsWire::TYPE_DS)) !== false) {
            $answer = @fread($socket, DnsWire::MAX_BYTES);
        }
        fclose($socket);
        $parsed = is_string($answer) ? DnsWire::answers($answer, $id, DnsWire::TYPE_DS) : null;
        if ($parsed === null) {
            return null;
        }

        // 0 : réponse ; 3 : nom inexistant. Toute autre erreur laisse la question sans réponse.
        return match ($parsed['rcode']) {
            0       => $parsed['count'] > 0,
            3       => false,
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(string $name, int $type): array
    {
        $records = @dns_get_record($name, $type);

        return is_array($records) ? array_values(array_filter($records, 'is_array')) : [];
    }

    /**
     * Premier serveur DNS déclaré par le système.
     */
    private function resolver(): ?string
    {
        if (!is_readable($this->resolvConf)) {
            return null;
        }
        foreach (file($this->resolvConf, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (
                preg_match('/^\s*nameserver\s+(\S+)/', $line, $m) === 1
                && filter_var($m[1], FILTER_VALIDATE_IP) !== false
            ) {
                return str_contains($m[1], ':') ? '[' . $m[1] . ']' : $m[1];
            }
        }

        return null;
    }
}

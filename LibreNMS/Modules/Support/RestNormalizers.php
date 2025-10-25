<?php
namespace LibreNMS\Modules\Support;

class RestNormalizers
{
    // Pure Storage
    public static function normalizePureHosts(array $payload): array { /* map hosts to inventory + sensors */ } // <sup><a href="null" class="markdown-link" target="_blank">1</a></sup>
    public static function normalizePureHardware(array $payload): array { /* temp, psu voltage/state, fans; inventory */ } // <sup><a href="null" class="markdown-link" target="_blank">2</a></sup>
    public static function normalizePureNetworkInterfaces(array $payload): array { /* interfaces -> ports */ } // <sup><a href="null" class="markdown-link" target="_blank">3</a></sup>
    public static function normalizePureNetworkPerformance(array $payload, int $pollIntervalSec): array { /* per-port rates -> counters */ } // <sup><a href="null" class="markdown-link" target="_blank">4</a></sup>
    public static function normalizePurePortOptics(array $payload): array { /* optics sensors & thresholds */ } // <sup><a href="null" class="markdown-link" target="_blank">5</a></sup>
    public static function normalizePureArraySensors(array $arrayPayload, array $perfPayload): array { /* array perf & capacity */ } // <sup><a href="null" class="markdown-link" target="_blank">6</a></sup>
    public static function normalizePureVolumes(array $volumesPayload, array $volPerfPayload = []): array { /* per-volume sensors */ } // <sup><a href="null" class="markdown-link" target="_blank">6</a></sup>

    // Proxmox
    public static function normalizeProxmoxNodeStatus(array $payload): array { /* sensors + processors + mempools */ } // <sup><a href="null" class="markdown-link" target="_blank">7</a></sup>
    public static function normalizeProxmoxNodeNetwork(array $payload): array { /* NICs -> ports */ } // <sup><a href="null" class="markdown-link" target="_blank">4</a></sup>
    public static function normalizeProxmoxNodeStorage(array $payload): array { /* pools -> inventory + storage sensors */ } // <sup><a href="null" class="markdown-link" target="_blank">4</a></sup>
    public static function normalizeProxmoxClusterStatus(array $payload): array { /* quorum + node online sensors + inventory */ } // <sup><a href="null" class="markdown-link" target="_blank">7</a></sup>
    public static function normalizeProxmoxClusterResources(array $payload): array { /* optional resource sensors/inventory */ } // <sup><a href="null" class="markdown-link" target="_blank">4</a></sup>

    // Helpers
    protected static function toStatus($v): string { /* normalize up/down/testing/unknown */ }
    protected static function stableIndexFromName(string $name): int { /* crc32 or similar */ }
}
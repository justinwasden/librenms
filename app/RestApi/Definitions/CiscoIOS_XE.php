<?php
// File: app/Services/Api/Definitions/Cisco.php

namespace App\Services\Api\Definitions;

return [
    "name" => "Cisco IOS XE (RESTCONF)",
    "vendor" => "Cisco",
    "description" => "Common RESTCONF API endpoints for Cisco IOS XE devices (JSON based). Requires Basic Auth.",
    "template_data" => [
        "connections" => [
            [
                "name" => "Primary Connection",
                "base_url" => "https://{device_hostname}",
                "rate_limit" => 60,
                "endpoints" => [
                    [
                        "name" => "CPU Utilization",
                        "path" => "/restconf/data/Cisco-IOS-XE-process-cpu-oper:cpu-usage/cpu-utilization",
                        "method" => "GET",
                        "resource_type" => "processor",
                        "metric_map" => [
                            "five_sec"     => "cpu_5sec",
                            "one_min"      => "cpu_1min",
                            "five_min"     => "cpu_5min"
                        ]
                    ],
                    [
                        "name" => "Memory Statistics",
                        "path" => "/restconf/data/Cisco-IOS-XE-memory-oper:memory-statistics",
                        "method" => "GET",
                        "resource_type" => "mempool",
                        "metric_map" => [
                            "total_bytes"   => "total_memory",
                            "used_bytes"    => "used_memory",
                            "free_bytes"    => "free_memory",
                            "used_percent"  => "percent_used"
                        ]
                    ],
                    [
                        "name" => "Interface Statistics",
                        "path" => "/restconf/data/Cisco-IOS-XE-interfaces-oper:interfaces-state/interface",
                        "method" => "GET",
                        "resource_type" => "port",
                        "metric_map" => [
                            "name"          => "ifName",
                            "admin-status"  => "adminStatus",
                            "oper-status"   => "operStatus",
                            "in-octets"     => "ifInOctets",
                            "out-octets"    => "ifOutOctets",
                            "in-errors"     => "ifInErrors",
                            "out-errors"    => "ifOutErrors",
                            "in-discards"   => "ifInDiscards",
                            "out-discards"  => "ifOutDiscards"
                        ]
                    ],
                    [
                        "name" => "ARP Table",
                        "path" => "/restconf/data/Cisco-IOS-XE-ipv4-arp-oper:arp-data",
                        "method" => "GET",
                        "resource_type" => "custom",
                        "metric_map" => [
                            "ip_address"   => "ipAddress",
                            "mac_address"  => "macAddress",
                            "interface"    => "ifName",
                            "type"         => "entryType"
                        ]
                    ],
                    [
                        "name" => "Device General",
                        "path" => "/restconf/data/Cisco-IOS-XE-platform-oper:platform-software-oper",
                        "method" => "GET",
                        "resource_type" => "device",
                        "metric_map" => [
                            "hostname"       => "hostname",
                            "model"          => "model",
                            "serial_number"  => "serial",
                            "os_version"     => "version",
                            "uptime_seconds" => "uptime"
                        ]
                    ]
                ]
            ]
        ]
    ]
];

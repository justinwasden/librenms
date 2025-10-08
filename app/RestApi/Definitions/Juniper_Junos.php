<?php
// File: app/Services/Api/Definitions/Juniper.php

namespace App\Services\Api\Definitions;

return [
    "name" => "Juniper Junos (REST)",
    "vendor" => "Juniper Networks",
    "description" => "Common REST API endpoints for Juniper Junos devices (JSON based). Requires Basic Auth.",
    "template_data" => [
        "connections" => [
            [
                "name" => "Primary Connection",
                "base_url" => "https://{device_hostname}",
                "rate_limit" => 60,
                "endpoints" => [
                    [
                        "name" => "System Uptime",
                        "path" => "/rpc/get-system-uptime-information",
                        "method" => "GET",
                        "resource_type" => "device",
                        "metric_map" => [
                            "host_name"        => "hostname",
                            "uptime_seconds"   => "uptime",
                            "os_version"       => "version",
                            "model"            => "model",
                            "serial_number"    => "serial"
                        ]
                    ],
                    [
                        "name" => "Interface Statistics",
                        "path" => "/api-json/op/show-interfaces-statistics",
                        "method" => "GET",
                        "resource_type" => "port",
                        "metric_map" => [
                            "name"          => "ifName",
                            "admin-status"  => "adminStatus",
                            "oper-status"   => "operStatus",
                            "input-bytes"   => "ifInOctets",
                            "output-bytes"  => "ifOutOctets",
                            "input-errors"  => "ifInErrors",
                            "output-errors" => "ifOutErrors",
                            "input-drops"   => "ifInDiscards",
                            "output-drops"  => "ifOutDiscards"
                        ]
                    ],
                    [
                        "name" => "CPU Utilization",
                        "path" => "/rpc/get-system-cpu-information",
                        "method" => "GET",
                        "resource_type" => "processor",
                        "metric_map" => [
                            "user"   => "cpu_user",
                            "system" => "cpu_system",
                            "idle"   => "cpu_idle"
                        ]
                    ],
                    [
                        "name" => "Memory Statistics",
                        "path" => "/rpc/get-system-memory-information",
                        "method" => "GET",
                        "resource_type" => "mempool",
                        "metric_map" => [
                            "total"        => "total_memory",
                            "used"         => "used_memory",
                            "free"         => "free_memory",
                            "used_percent" => "percent_used"
                        ]
                    ],
                    [
                        "name" => "ARP Table",
                        "path" => "/rpc/get-arp-table-information",
                        "method" => "GET",
                        "resource_type" => "custom",
                        "metric_map" => [
                            "ip_address"   => "ipAddress",
                            "mac_address"  => "macAddress",
                            "interface"    => "ifName",
                            "entry_type"   => "type"
                        ]
                    ],
                    [
                        "name" => "Routing Table",
                        "path" => "/rpc/get-route-information",
                        "method" => "GET",
                        "resource_type" => "custom",
                        "metric_map" => [
                            "destination" => "dest",
                            "gateway"     => "gateway",
                            "interface"   => "ifName",
                            "protocol"    => "protocol",
                            "metric"      => "metric"
                        ]
                    ]
                ]
            ]
        ]
    ]
];

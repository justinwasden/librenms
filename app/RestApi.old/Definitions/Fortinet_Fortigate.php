<?php
// File: app/Services/Api/Definitions/Fortinet.php

namespace App\Services\Api\Definitions;

return [
    "name" => "Fortinet FortiGate",
    "vendor" => "Fortinet",
    "description" => "Common API endpoints for Fortinet FortiGate devices (JSON based). Requires Token/Bearer Auth.",
    "template_data" => [
        "connections" => [
            [
                "name" => "Primary Connection",
                "base_url" => "https://{device_hostname}",
                "rate_limit" => 120,
                "endpoints" => [
                    [
                        "name" => "System Status",
                        "path" => "/api/v2/monitor/system/status",
                        "method" => "GET",
                        "resource_type" => "device",
                        "metric_map" => [
                            "hostname"      => "host_name",
                            "model"         => "model",
                            "serial_number" => "serial",
                            "uptime_sec"    => "uptime_seconds",
                            "firmware"      => "version"
                        ]
                    ],
                    [
                        "name" => "CPU and Memory",
                        "path" => "/api/v2/monitor/system/resource/usage",
                        "method" => "GET",
                        "resource_type" => "sensor",
                        "metric_map" => [
                            "cpu_1min"     => "cpu_1min",
                            "cpu_5min"     => "cpu_5min",
                            "cpu_15min"    => "cpu_15min",
                            "mem_total"    => "total_memory",
                            "mem_used"     => "used_memory",
                            "mem_free"     => "free_memory",
                            "mem_percent"  => "percent_used"
                        ]
                    ],
                    [
                        "name" => "Session Statistics",
                        "path" => "/api/v2/monitor/system/session",
                        "method" => "GET",
                        "resource_type" => "custom",
                        "metric_map" => [
                            "current_sessions"  => "sessions_active",
                            "session_rate"      => "sessions_per_sec",
                            "max_sessions"      => "sessions_max"
                        ]
                    ],
                    [
                        "name" => "VPN Status",
                        "path" => "/api/v2/monitor/vpn/ssl",
                        "method" => "GET",
                        "resource_type" => "custom",
                        "metric_map" => [
                            "vpn_tunnels_up"    => "tunnels_up",
                            "vpn_tunnels_total" => "tunnels_total"
                        ]
                    ],
                    [
                        "name" => "Security Events / Disk Usage",
                        "path" => "/api/v2/monitor/log/current-disk-usage",
                        "method" => "GET",
                        "resource_type" => "storage",
                        "metric_map" => [
                            "disk_total_mb"   => "disk_total",
                            "disk_used_mb"    => "disk_used",
                            "disk_free_mb"    => "disk_free",
                            "disk_usage_pct"  => "disk_percent"
                        ]
                    ],
                    [
                        "name" => "Interface Statistics",
                        "path" => "/api/v2/monitor/system/interface",
                        "method" => "GET",
                        "resource_type" => "port",
                        "metric_map" => [
                            "name"           => "ifName",
                            "admin_status"   => "adminStatus",
                            "oper_status"    => "operStatus",
                            "input_bytes"    => "ifInOctets",
                            "output_bytes"   => "ifOutOctets",
                            "input_errors"   => "ifInErrors",
                            "output_errors"  => "ifOutErrors",
                            "input_drops"    => "ifInDiscards",
                            "output_drops"   => "ifOutDiscards"
                        ]
                    ]
                ]
            ]
        ]
    ]
];
